<?php

declare(strict_types=1);

namespace Siro\Core;

/**
 * Input validation engine.
 *
 * Supports rules: required, email, numeric, integer, file, min, max,
 * confirmed, in, unique, exists. Returns structured error messages.
 * Messages are translated via Lang when available.
 * Custom rules can be registered via Validator::extend().
 *
 * @package Siro\Core
 */
final class Validator
{
    /** @var array<string, callable> */
    private static array $customRules = [];

    /** @var array<string, callable> */
    private static array $ruleStrategies = [];
    /**
     * Register a custom validation rule.
     *
     * The callback receives (mixed $value, string $field, array $input, mixed $parameter).
     * Return true if valid, false or string error message if invalid.
     *
     * Usage:
     *   Validator::extend('phone', function ($value, $field, $input, $param) {
     *       return preg_match('/^\+?[0-9]{10,15}$/', (string) $value) ? true : ':field is not a valid phone number';
     *   });
     *
     *   $request->validate(['phone' => 'phone']);
     */
    public static function extend(string $name, callable $callback): void
    {
        self::$customRules[$name] = $callback;
    }

    /**
     * Register a built-in rule strategy.
     *
     * @param string $name Rule name (e.g., 'email', 'min')
     * @param callable $strategy Callback: function(mixed $value, ?string $param, array $input = [], string $field = ''): string|array|null
     *   Returns null if valid, error message string or [key, replacements] array if invalid
     */
    private static function registerStrategy(string $name, callable $strategy): void
    {
        self::$ruleStrategies[$name] = $strategy;
    }

    /**
     * Initialize all validation strategies (lazy-loaded).
     */
    private static function initStrategies(): void
    {
        if (self::$ruleStrategies !== []) {
            return; // Already initialized
        }

        // Email validation
        self::registerStrategy('email', function ($value): ?string {
            return filter_var($value, FILTER_VALIDATE_EMAIL) === false
                ? 'validation.email'
                : null;
        });

        // Numeric validation
        self::registerStrategy('numeric', function ($value): ?string {
            return !is_numeric($value) ? 'validation.numeric' : null;
        });

        // Integer validation
        self::registerStrategy('integer', function ($value): ?string {
            return filter_var($value, FILTER_VALIDATE_INT) === false ? 'validation.integer' : null;
        });

        // Date validation
        self::registerStrategy('date', function ($value): ?string {
            $ts = is_int($value) || is_float($value) ? $value : strtotime((string) $value);
            return ($ts === false || $ts <= 0) ? 'validation.date' : null;
        });

        // URL validation
        self::registerStrategy('url', function ($value): ?string {
            return filter_var($value, FILTER_VALIDATE_URL) === false ? 'validation.url' : null;
        });

        // File validation
        self::registerStrategy('file', function ($value): ?string {
            return (!$value instanceof UploadedFile || !$value->isValid()) ? 'validation.file' : null;
        });

        // Min validation (handles strings, numbers, files)
        self::registerStrategy('min', function ($value, ?string $param, array $input = [], string $field = ''): string|array|null {
            if ($param === null) return null;
            $min = (int) $param;

            if ($value instanceof UploadedFile && $value->isValid()) {
                $sizeInKb = (int) ceil($value->getSize() / 1024);
                return $sizeInKb < $min ? ['validation.min', ['min' => (string) $min]] : null;
            }

            if (is_int($value)) {
                return $value < $min ? ['validation.min', ['min' => (string) $min]] : null;
            }

            if (is_string($value)) {
                return strlen(trim($value)) < $min ? ['validation.min', ['min' => (string) $min]] : null;
            }

            if (is_float($value)) {
                return $value < $min ? ['validation.min', ['min' => (string) $min]] : null;
            }
            return null;
        });

        // Max validation (handles strings, numbers, files)
        self::registerStrategy('max', function ($value, ?string $param, array $input = [], string $field = ''): string|array|null {
            if ($param === null) return null;
            $max = (int) $param;

            if ($value instanceof UploadedFile && $value->isValid()) {
                $sizeInKb = (int) ceil($value->getSize() / 1024);
                return $sizeInKb > $max ? ['validation.max', ['max' => (string) $max]] : null;
            }

            if (is_int($value)) {
                return $value > $max ? ['validation.max', ['max' => (string) $max]] : null;
            }

            if (is_string($value)) {
                return strlen(trim($value)) > $max ? ['validation.max', ['max' => (string) $max]] : null;
            }

            if (is_float($value)) {
                return $value > $max ? ['validation.max', ['max' => (string) $max]] : null;
            }
            return null;
        });

        // Confirmed validation
        self::registerStrategy('confirmed', function ($value, ?string $param, array $input, string $field): ?string {
            $confirmationField = $field . '_confirmation';
            $confirmationValue = $input[$confirmationField] ?? null;
            return $value !== $confirmationValue ? 'validation.confirmed' : null;
        });

        // In validation
        self::registerStrategy('in', function ($value, ?string $param, array $input = [], string $field = ''): string|array|null {
            if ($param === null) return null;
            $allowedValues = array_map('trim', explode(',', $param));
            return !in_array((string) $value, $allowedValues, true)
                ? ['validation.in', ['values' => implode(', ', $allowedValues)]]
                : null;
        });

        // Regex validation
        self::registerStrategy('regex', function ($value, ?string $param): ?string {
            if ($param === null) return null;
            if (@preg_match($param, '') === false) return null; // Invalid pattern, skip
            return !preg_match($param, (string) $value) ? 'validation.regex' : null;
        });
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $rules
     * @return array<string, array<int, string>>
     */
    public static function make(array $input, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleLine) {
            $value = $input[$field] ?? null;

            // Reject arrays for non-file rules (type confusion)
            if (is_array($value) && $value !== []) {
                $fieldRules = explode('|', $ruleLine);
                if (!in_array('file', $fieldRules, true)) {
                    $errors[$field][] = self::msg('validation.array', ['field' => self::label($field)]);
                    continue;
                }
            }

            $fieldRules = explode('|', $ruleLine);
            $isNullable = in_array('nullable', $fieldRules, true);
            $isRequired = in_array('required', $fieldRules, true);

            // Handle required_if
            $requiredIf = null;
            foreach ($fieldRules as $rule) {
                if (str_starts_with($rule, 'required_if:')) {
                    $requiredIf = substr($rule, 12);
                    break;
                }
            }
            if ($requiredIf !== null) {
                $parts = explode(',', $requiredIf, 2);
                $otherField = trim($parts[0] ?? '');
                $otherValue = trim($parts[1] ?? '');
                if ($otherField !== '' && ($input[$otherField] ?? null) == $otherValue) {
                    $isRequired = true;
                }
            }

            // Check required
            $checkValue = is_string($value) ? trim($value) : $value;
            if ($isRequired && ($checkValue === null || $checkValue === '')) {
                $errors[$field][] = self::msg('validation.required', ['field' => self::label($field)]);
                continue;
            }

            // Skip validation for empty values if nullable or not required
            if (($value === null || $value === '') && !$isRequired) {
                continue;
            }
            if ($value === null && $isNullable) {
                continue;
            }

            foreach ($fieldRules as $rule) {
                // Skip meta-rules
                if ($rule === 'nullable' || $rule === 'required' || str_starts_with($rule, 'required_if:')) {
                    continue;
                }

                // Initialize strategies on first use
                self::initStrategies();

                // Parse rule name and parameter
                $ruleName = $rule;
                $ruleParam = null;
                if (str_contains($rule, ':')) {
                    $parts = explode(':', $rule, 2);
                    $ruleName = $parts[0];
                    $ruleParam = $parts[1] ?? null;
                }

                // Check custom rules first
                if (isset(self::$customRules[$ruleName])) {
                    $result = (self::$customRules[$ruleName])($value, $field, $input, $ruleParam);
                    if ($result !== true) {
                        $msg = is_string($result) ? $result : self::label($field) . ' is invalid';
                        $errors[$field][] = str_replace(':field', self::label($field), $msg);
                        continue;
                    }
                }

                // Check built-in strategy rules
                if (isset(self::$ruleStrategies[$ruleName])) {
                    $strategy = self::$ruleStrategies[$ruleName];

                    // Some strategies need extra context
                    $result = match ($ruleName) {
                        'confirmed' => $strategy($value, $ruleParam, $input, $field),
                        default => $strategy($value, $ruleParam)
                    };

                    if ($result !== null) {
                        // Result can be string key or [key, replacements]
                        if (is_array($result)) {
                            [$key, $replacements] = $result;
                            $replacements['field'] = self::label($field);
                            $errors[$field][] = self::msg($key, $replacements);
                        } else {
                            $errors[$field][] = self::msg($result, ['field' => self::label($field)]);
                        }
                        continue;
                    }
                }

                // Handle unique/exists rules (need database access)
                if ($ruleName === 'unique') {
                    if ($ruleParam !== null) {
                        $parts = explode(',', $ruleParam);
                        $table = trim($parts[0] ?? '');
                        $column = trim($parts[1] ?? $field);

                        if ($table !== '') {
                            $exists = Database::table($table)
                                ->where($column, '=', $value)
                                ->count();

                            if ($exists > 0) {
                                $errors[$field][] = self::msg('validation.unique', ['field' => self::label($field)]);
                                continue;
                            }
                        }
                    }
                }

                if ($ruleName === 'exists') {
                    if ($ruleParam !== null) {
                        $parts = explode(',', $ruleParam);
                        $table = trim($parts[0] ?? '');
                        $column = trim($parts[1] ?? $field);

                        if ($table !== '') {
                            $exists = Database::table($table)
                                ->where($column, '=', $value)
                                ->count();

                            if ($exists === 0) {
                                $errors[$field][] = self::msg('validation.exists', ['field' => self::label($field)]);
                                continue;
                            }
                        }
                    }
                }
            }
        }

        return $errors;
    }

    private static function msg(string $key, array $replace = []): string
    {
        $translated = Lang::get($key, $replace);
        if ($translated !== $key) {
            return $translated;
        }
        // Fallback to English if no translation file
        return self::fallback($key, $replace);
    }

    private static function fallback(string $key, array $replace): string
    {
        $defaults = [
            'validation.required' => ':field is required',
            'validation.email' => ':field must be a valid email',
            'validation.numeric' => ':field must be numeric',
            'validation.integer' => ':field must be an integer',
            'validation.date' => ':field must be a valid date',
            'validation.url' => ':field must be a valid URL',
            'validation.regex' => ':field format is invalid',
            'validation.min' => ':field must be at least :min',
            'validation.max' => ':field must not exceed :max',
            'validation.unique' => ':field already exists',
            'validation.exists' => ':field does not exist',
            'validation.confirmed' => ':field confirmation does not match',
            'validation.in' => ':field must be one of: :values',
            'validation.file' => ':field must be a valid file',
            'validation.array' => ':field must not be an array',
        ];

        $msg = $defaults[$key] ?? $key;

        foreach ($replace as $k => $v) {
            $msg = str_replace(':' . $k, (string) $v, $msg);
        }

        return $msg;
    }

    private static function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }
}
