<?php

declare(strict_types=1);

namespace Siro\Core;

use InvalidArgumentException;
use RuntimeException;
use Siro\Core\DB\ApiQueryFilter;

/**
 * Runtime auto-CRUD controller (G3, v1.5.0).
 *
 * One controller serves every model registered with
 * `Router::apiResource()`. The model + per-model config travel in a static
 * registry keyed by the normalized base path (group prefix included) — see
 * PROGRESS.md §1.5 in the G3 lane for why the registry is keyed by path
 * instead of a closure handler (closures break route caching and make
 * `getRoutes()` blind to the handler, which would hide the routes from
 * `api:contract` / `make:openapi`).
 *
 * SECURITY (spec §1.3):
 * - Every action authorizes through `Gate` BEFORE touching the database.
 *   Ability = `abilities[action]` override, else `{table}.{verb}`
 *   (index/show → view, store → create, update → update, destroy → delete).
 *   `Gate::allows()` returns false for undefined abilities, so an action
 *   with no ability defined is DENIED (403) by default — registering
 *   auto-CRUD on a sensitive model without configuring a policy leaks nothing.
 * - Store/update bodies go through an optional per-model FormRequest
 *   (`rules()` + `authorize()`); without one, `Model::fill()` still guards
 *   mass assignment via `$fillable`.
 * - Query params go through `ApiQueryFilter` (whitelist enforced, 400 on
 *   anything not whitelisted).
 *
 * @package Siro\Core
 */
final class ApiCrudController extends Controller
{
    /**
     * @var array<string, array{model: class-string<Model>, options: array<string, mixed>}>
     */
    private static array $registry = [];

    /**
     * Register a model + config under its base path. Called by
     * `Router::apiResource()`. Re-registering the same path overwrites.
     *
     * @param class-string<Model> $modelClass
     * @param array<string, mixed> $options
     */
    public static function register(string $basePath, string $modelClass, array $options): void
    {
        self::$registry[$basePath] = ['model' => $modelClass, 'options' => $options];
    }

    /** Clear the registry. Required for test isolation. */
    public static function reset(): void
    {
        self::$registry = [];
    }

    /** @param class-string<Model> $modelClass */
    public static function tableFor(string $modelClass): string
    {
        $ref = new \ReflectionClass($modelClass);
        /** @var Model $instance */
        $instance = $ref->newInstanceWithoutConstructor();
        return $instance->getTable();
    }

    public function index(Request $request): Response
    {
        $entry = $this->config($request, 'index');
        $this->authorize('index', $entry, $request->user());

        try {
            $parsed = ApiQueryFilter::parse($request, $entry['model'], $entry['options']);
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 400, [], 'invalid_query');
        }

        $query = ApiQueryFilter::apply($entry['model']::query(), $parsed);
        $page = $query->paginate($parsed['per_page'], $parsed['page']);

        /** @var array<int, Model> $models */
        $models = $page['data'];
        $items = $this->serializeCollection($models, $entry, $parsed['fields'], $parsed['includes']);

        /** @var array<string, mixed> $meta */
        $meta = $page['meta'];
        $meta['filters'] = self::echoFilters($parsed['filters']);
        $meta['sort'] = self::echoSorts($parsed['sorts']);
        if ($parsed['includes'] !== []) {
            $meta['includes'] = $parsed['includes'];
        }
        if ($parsed['fields'] !== null) {
            $meta['fields'] = $parsed['fields'];
        }

        return Response::success($items, 'OK', 200, $meta);
    }

    public function show(Request $request): Response
    {
        $entry = $this->config($request, 'show');
        $this->authorize('show', $entry, $request->user());

        $model = $entry['model']::findOrFail($this->routeId($request));
        return Response::success($this->serialize($model, $entry, null, []));
    }

    public function store(Request $request): Response
    {
        $entry = $this->config($request, 'store');
        $this->authorize('store', $entry, $request->user());

        $data = $this->validatedBody($request, $entry);
        $model = $entry['model']::create($data);
        return Response::created($this->serialize($model, $entry, null, []));
    }

    public function update(Request $request): Response
    {
        $entry = $this->config($request, 'update');
        $this->authorize('update', $entry, $request->user());

        $model = $entry['model']::findOrFail($this->routeId($request));
        $data = $this->validatedBody($request, $entry);
        $model->update($data);
        return Response::success($this->serialize($model, $entry, null, []));
    }

    public function destroy(Request $request): Response
    {
        $entry = $this->config($request, 'destroy');
        $this->authorize('destroy', $entry, $request->user());

        $model = $entry['model']::findOrFail($this->routeId($request));
        $model->delete();
        return Response::noContent();
    }

    /**
     * @return array{model: class-string<Model>, options: array<string, mixed>}
     */
    private function config(Request $request, string $action): array
    {
        $path = '/' . trim($request->path(), '/');
        if (in_array($action, ['show', 'update', 'destroy'], true)) {
            $segments = explode('/', trim($path, '/'));
            array_pop($segments);
            $path = '/' . implode('/', $segments);
        }

        $entry = self::$registry[$path] ?? null;
        if ($entry === null) {
            throw new RuntimeException("No auto-CRUD resource registered for path '{$path}'.");
        }
        return $entry;
    }

    private function routeId(Request $request): int|string
    {
        $id = $request->param('id');
        if (!is_string($id) && !is_int($id)) {
            throw new RuntimeException('Auto-CRUD route is missing the {id} parameter.');
        }
        return $id;
    }

    /** @param array{model: class-string<Model>, options: array<string, mixed>} $entry */
    private function authorize(string $action, array $entry, mixed $user): void
    {
        $verb = match ($action) {
            'index', 'show' => 'view',
            'store' => 'create',
            'update' => 'update',
            'destroy' => 'delete',
            default => throw new RuntimeException("Unknown auto-CRUD action '{$action}'."),
        };
        $default = self::tableFor($entry['model']) . '.' . $verb;
        $abilities = $entry['options']['abilities'] ?? [];
        $ability = is_array($abilities) && isset($abilities[$action]) && is_string($abilities[$action])
            ? $abilities[$action]
            : $default;
        Gate::authorize($ability, $user);
    }

    /**
     * @param array{model: class-string<Model>, options: array<string, mixed>} $entry
     * @return array<string, mixed>
     */
    private function validatedBody(Request $request, array $entry): array
    {
        $requestClass = $entry['options']['request'] ?? null;
        if (!is_string($requestClass) || $requestClass === '') {
            return $request->body();
        }

        /** @var FormRequest $form */
        $form = new $requestClass($request);
        return $form->validated();
    }

    /**
     * @param array{model: class-string<Model>, options: array<string, mixed>} $entry
     * @param array<int, string>|null $fields
     * @param array<int, string> $includes
     * @return array<string, mixed>
     */
    private function serialize(Model $model, array $entry, ?array $fields, array $includes): array
    {
        $resourceClass = $entry['options']['resource'] ?? null;
        if (is_string($resourceClass) && $resourceClass !== '') {
            /** @var class-string<Resource> $resourceClass */
            $data = $resourceClass::make($model, $fields);
        } else {
            $data = $model->toArray();
            if ($fields !== null) {
                $data = self::applyFields($data, $fields);
            }
        }

        foreach ($includes as $relation) {
            $data[$relation] = self::serializeRelation($model->getRelation($relation));
        }
        return $data;
    }

    /**
     * @param array<int, Model> $models
     * @param array{model: class-string<Model>, options: array<string, mixed>} $entry
     * @param array<int, string>|null $fields
     * @param array<int, string> $includes
     * @return array<int, array<string, mixed>>
     */
    private function serializeCollection(array $models, array $entry, ?array $fields, array $includes): array
    {
        $resourceClass = $entry['options']['resource'] ?? null;
        if (is_string($resourceClass) && $resourceClass !== '') {
            /** @var class-string<Resource> $resourceClass */
            $items = $fields !== null
                ? $resourceClass::collectionOf($models, $fields)
                : $resourceClass::collection($models);
            foreach ($models as $index => $model) {
                foreach ($includes as $relation) {
                    $items[$index][$relation] = self::serializeRelation($model->getRelation($relation));
                }
            }
            return $items;
        }

        $items = [];
        foreach ($models as $model) {
            $items[] = $this->serialize($model, $entry, $fields, $includes);
        }
        return $items;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    private static function applyFields(array $data, array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $out[$field] = $data[$field];
            }
        }
        return $out;
    }

    private static function serializeRelation(mixed $value): mixed
    {
        if ($value instanceof Model) {
            return $value->toArray();
        }
        if (is_array($value)) {
            return array_map(
                static fn (mixed $item): mixed => $item instanceof Model ? $item->toArray() : $item,
                $value
            );
        }
        return $value;
    }

    /**
     * @param array<int, array{field: string, op: string, value: mixed}> $filters
     * @return array<int, string>
     */
    private static function echoFilters(array $filters): array
    {
        $echo = [];
        foreach ($filters as $filter) {
            $value = $filter['value'];
            if (is_array($value)) {
                $value = implode(',', array_map(
                    static fn (mixed $v): string => is_scalar($v) ? (string) $v : gettype($v),
                    $value
                ));
            } elseif (is_scalar($value)) {
                $value = (string) $value;
            } else {
                $value = gettype($value);
            }
            $echo[] = $filter['field'] . ':' . $filter['op'] . ':' . $value;
        }
        return $echo;
    }

    /**
     * @param array<int, array{field: string, direction: string}> $sorts
     * @return array<int, string>
     */
    private static function echoSorts(array $sorts): array
    {
        $echo = [];
        foreach ($sorts as $sort) {
            $echo[] = ($sort['direction'] === 'desc' ? '-' : '') . $sort['field'];
        }
        return $echo;
    }
}
