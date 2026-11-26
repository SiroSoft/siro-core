<?php

declare(strict_types=1);

namespace Siro\Core\DB;

use InvalidArgumentException;
use Siro\Core\Request;

/**
 * ApiQueryFilter — query-string parser for runtime auto-CRUD (G3, v1.5.0).
 *
 * SPEC (khóa, xem thêm `PROGRESS.md` §1 trong lane G3 + `REPORT.md` §1):
 *
 *   ?filter[field]=v              → field = v (eq)
 *   ?filter[field][op]=v          → op ∈ eq, ne, lt, lte, gt, gte, like, in, nin
 *   ?sort=-created_at,name        → created_at DESC, name ASC ("-" = DESC)
 *   ?include=rel1,rel2            → eager load (relation phải là method trên model)
 *   ?fields=id,name              → sparse fieldset trên output đã serialize
 *   ?page=2&per_page=20           → page >= 1; per_page 1..100 (mặc định 15)
 *
 * SECURITY — whitelist BẮT BUỘC, không có fallback ngầm:
 * - Tên field/relation phải khớp `^[A-Za-z_][A-Za-z0-9_]*$` VÀ nằm trong whitelist
 *   tương ứng (`filterable` / `sortable` / `includable` / `fields`). Không khớp
 *   → InvalidArgumentException (controller map thành 400, không 500, không leak).
 * - Option whitelist nào không khai (hoặc rỗng) thì dùng param đó là 400.
 * - `include` còn phải là method thật trên model class (`method_exists`).
 * - `like` bọc `%value%`; ký tự `%`/`_` trong value giữ nguyên nghĩa wildcard
 *   của LIKE (ghi rõ — giống Laravel khi không escape); value luôn là bound
 *   param qua QueryBuilder nên không SQL injection.
 * - `fields` chỉ áp ở tầng serialize (không `select()` ở query) để eager load
 *   không vỡ khi `fields` thiếu khóa chính / khóa ngoại.
 *
 * @package Siro\Core\DB
 */
final class ApiQueryFilter
{
    /** @var array<int, string> */
    public const OPERATORS = ['eq', 'ne', 'lt', 'lte', 'gt', 'gte', 'like', 'in', 'nin'];

    private const MAX_IN_VALUES = 100;
    private const MAX_PER_PAGE = 100;
    private const DEFAULT_PER_PAGE = 15;

    /**
     * Validate a whole ApiQueryFilter config (whitelists + per_page) without
     * parsing a request. Used by Router::apiResource() to fail fast at
     * registration time instead of on the first request.
     *
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException
     */
    public static function validateConfig(array $config): void
    {
        foreach (['filterable', 'sortable', 'includable', 'fields'] as $key) {
            self::whitelist($config, $key);
        }
        self::defaultPerPage($config);
    }

    /**
     * Parse query params thành cấu trúc đã validate.
     *
     * @param array<string, mixed> $config filterable/sortable/includable/fields/per_page
     * @return array{
     *   filters: array<int, array{field: string, op: string, value: mixed}>,
     *   sorts: array<int, array{field: string, direction: string}>,
     *   includes: array<int, string>,
     *   fields: array<int, string>|null,
     *   page: int,
     *   per_page: int
     * }
     *
     * @throws InvalidArgumentException khi param sai field / sai op / sai kiểu.
     */
    public static function parse(Request $request, string $modelClass, array $config): array
    {
        /** @var array<int, string> $filterable */
        $filterable = self::whitelist($config, 'filterable');
        /** @var array<int, string> $sortable */
        $sortable = self::whitelist($config, 'sortable');
        /** @var array<int, string> $includable */
        $includable = self::whitelist($config, 'includable');
        /** @var array<int, string> $fieldsWhitelist */
        $fieldsWhitelist = self::whitelist($config, 'fields');
        $defaultPerPage = self::defaultPerPage($config);

        return [
            'filters' => self::parseFilters($request, $filterable),
            'sorts' => self::parseSorts($request, $sortable),
            'includes' => self::parseIncludes($request, $modelClass, $includable),
            'fields' => self::parseFields($request, $fieldsWhitelist),
            'page' => self::parsePage($request, 'page', 1),
            'per_page' => self::parsePage($request, 'per_page', $defaultPerPage),
        ];
    }

    /**
     * Áp cấu trúc đã parse lên ModelQueryBuilder (where / whereIn / orderBy /
     * eagerLoad). Chỉ gọi sau parse() — mọi field ở đây đã qua whitelist.
     *
     * @param array{
     *   filters: array<int, array{field: string, op: string, value: mixed}>,
     *   sorts: array<int, array{field: string, direction: string}>,
     *   includes: array<int, string>,
     *   fields: array<int, string>|null,
     *   page: int,
     *   per_page: int
     * } $parsed
     */
    public static function apply(ModelQueryBuilder $query, array $parsed): ModelQueryBuilder
    {
        foreach ($parsed['filters'] as $filter) {
            $field = $filter['field'];
            $op = $filter['op'];
            $value = $filter['value'];
            if ($op === 'in' && is_array($value)) {
                $query->whereIn($field, $value);
                continue;
            }
            if ($op === 'nin' && is_array($value)) {
                $query->whereNotIn($field, $value);
                continue;
            }
            $query->where($field, self::sqlOperator($op), $value);
        }

        foreach ($parsed['sorts'] as $sort) {
            $query->orderBy($sort['field'], $sort['direction']);
        }

        foreach ($parsed['includes'] as $relation) {
            $query->eagerLoad($relation);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    private static function whitelist(array $config, string $key): array
    {
        $raw = $config[$key] ?? [];
        if (!is_array($raw)) {
            throw new InvalidArgumentException("ApiQueryFilter config '{$key}' must be an array of field names.");
        }
        $list = [];
        foreach ($raw as $name) {
            if (!is_string($name) || !self::isIdentifier($name)) {
                throw new InvalidArgumentException("ApiQueryFilter config '{$key}' contains an invalid field name.");
            }
            $list[] = $name;
        }
        return array_values(array_unique($list));
    }

    /** @param array<string, mixed> $config */
    private static function defaultPerPage(array $config): int
    {
        $raw = $config['per_page'] ?? self::DEFAULT_PER_PAGE;
        if (!is_int($raw) || $raw < 1 || $raw > self::MAX_PER_PAGE) {
            throw new InvalidArgumentException(
                'ApiQueryFilter config \'per_page\' must be an integer between 1 and 100.'
            );
        }
        return $raw;
    }

    /**
     * @param array<int, string> $filterable
     * @return array<int, array{field: string, op: string, value: mixed}>
     */
    private static function parseFilters(Request $request, array $filterable): array
    {
        $raw = $request->query('filter', []);
        if (!is_array($raw)) {
            throw new InvalidArgumentException(
                "Query parameter 'filter' must be an array, e.g. ?filter[status]=active."
            );
        }

        $filters = [];
        foreach ($raw as $field => $spec) {
            $field = (string) $field;
            self::requireWhitelisted($field, $filterable, 'filter');

            if (is_array($spec)) {
                foreach ($spec as $op => $value) {
                    $filters[] = self::buildFilter($field, (string) $op, $value);
                }
                continue;
            }

            $filters[] = self::buildFilter($field, 'eq', $spec);
        }
        return $filters;
    }

    /**
     * @return array{field: string, op: string, value: mixed}
     */
    private static function buildFilter(string $field, string $op, mixed $value): array
    {
        if (!in_array($op, self::OPERATORS, true)) {
            throw new InvalidArgumentException(
                "Invalid filter operator '{$op}' for field '{$field}'. Allowed: " . implode(', ', self::OPERATORS) . '.'
            );
        }

        if ($op === 'in' || $op === 'nin') {
            return ['field' => $field, 'op' => $op, 'value' => self::parseInValues($field, $op, $value)];
        }

        if (is_array($value)) {
            throw new InvalidArgumentException("Filter value for field '{$field}' must be a scalar.");
        }

        if ($op === 'like') {
            $value = '%' . (is_scalar($value) ? (string) $value : '') . '%';
        }

        return ['field' => $field, 'op' => $op, 'value' => $value];
    }

    /**
     * @return array<int, mixed>
     */
    private static function parseInValues(string $field, string $op, mixed $value): array
    {
        $values = is_string($value) ? explode(',', $value) : (is_array($value) ? $value : null);
        if ($values === null) {
            throw new InvalidArgumentException(
                "Filter '{$field}[{$op}]' must be a comma-separated string or an array."
            );
        }

        $clean = [];
        foreach ($values as $item) {
            if (!is_scalar($item)) {
                throw new InvalidArgumentException("Filter '{$field}[{$op}]' values must be scalars.");
            }
            $item = is_string($item) ? trim($item) : $item;
            if ($item === '') {
                continue;
            }
            $clean[] = $item;
        }

        if ($clean === []) {
            throw new InvalidArgumentException("Filter '{$field}[{$op}]' must contain at least one value.");
        }
        if (count($clean) > self::MAX_IN_VALUES) {
            throw new InvalidArgumentException(
                "Filter '{$field}[{$op}]' accepts at most " . self::MAX_IN_VALUES . ' values.'
            );
        }
        return $clean;
    }

    /**
     * @param array<int, string> $sortable
     * @return array<int, array{field: string, direction: string}>
     */
    private static function parseSorts(Request $request, array $sortable): array
    {
        $raw = $request->query('sort', '');
        if (!is_string($raw)) {
            throw new InvalidArgumentException("Query parameter 'sort' must be a string, e.g. ?sort=-created_at,name.");
        }
        if (trim($raw) === '') {
            return [];
        }

        $sorts = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $direction = 'asc';
            if (str_starts_with($part, '-')) {
                $direction = 'desc';
                $part = substr($part, 1);
            }
            self::requireWhitelisted($part, $sortable, 'sort');
            $sorts[] = ['field' => $part, 'direction' => $direction];
        }
        return $sorts;
    }

    /**
     * @param array<int, string> $includable
     * @return array<int, string>
     */
    private static function parseIncludes(Request $request, string $modelClass, array $includable): array
    {
        $raw = $request->query('include', '');
        if (!is_string($raw)) {
            throw new InvalidArgumentException("Query parameter 'include' must be a string, e.g. ?include=category.");
        }
        if (trim($raw) === '') {
            return [];
        }

        $includes = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            self::requireWhitelisted($part, $includable, 'include');
            if (!method_exists($modelClass, $part)) {
                throw new InvalidArgumentException(
                    "Relation '{$part}' does not exist on model {$modelClass}."
                );
            }
            $includes[] = $part;
        }
        return array_values(array_unique($includes));
    }

    /**
     * @param array<int, string> $fieldsWhitelist
     * @return array<int, string>|null
     */
    private static function parseFields(Request $request, array $fieldsWhitelist): ?array
    {
        $raw = $request->query('fields', '');
        if (!is_string($raw)) {
            throw new InvalidArgumentException("Query parameter 'fields' must be a string, e.g. ?fields=id,name.");
        }
        if (trim($raw) === '') {
            return null;
        }

        $fields = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            self::requireWhitelisted($part, $fieldsWhitelist, 'fields');
            $fields[] = $part;
        }
        return array_values(array_unique($fields));
    }

    private static function parsePage(Request $request, string $key, int $default): int
    {
        $raw = $request->query($key, (string) $default);
        if (is_int($raw)) {
            $value = $raw;
        } elseif (is_string($raw) && ctype_digit($raw)) {
            $value = (int) $raw;
        } else {
            throw new InvalidArgumentException("Query parameter '{$key}' must be a positive integer.");
        }

        if ($key === 'page' && $value < 1) {
            throw new InvalidArgumentException("Query parameter 'page' must be at least 1.");
        }
        if ($key === 'per_page' && ($value < 1 || $value > self::MAX_PER_PAGE)) {
            throw new InvalidArgumentException(
                "Query parameter 'per_page' must be between 1 and " . self::MAX_PER_PAGE . '.'
            );
        }
        return $value;
    }

    /** @param array<int, string> $whitelist */
    private static function requireWhitelisted(string $name, array $whitelist, string $param): void
    {
        if (!self::isIdentifier($name) || !in_array($name, $whitelist, true)) {
            throw new InvalidArgumentException(
                "Field '{$name}' is not allowed in query parameter '{$param}'."
            );
        }
    }

    private static function isIdentifier(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name);
    }

    private static function sqlOperator(string $op): string
    {
        return match ($op) {
            'eq' => '=',
            'ne' => '!=',
            'lt' => '<',
            'lte' => '<=',
            'gt' => '>',
            'gte' => '>=',
            'like' => 'LIKE',
            default => throw new InvalidArgumentException("Unknown filter operator '{$op}'."),
        };
    }
}
