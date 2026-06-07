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

    /** @var array<string, string> */
    private static array $customMessages = [];

    /** @var array<string, array{parsed:array<int,string>,nullable:bool,required:bool,requiredIf:?string}> */
    private static array $parsedRuleCache = [];
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

    /** @param array<string, string> $messages */
    public static function messages(array $messages): void
    {
        foreach ($messages as $rule => $message) {
            self::$customMessages[(string) $rule] = (string) $message;
        }
    }

    private static function message(string $rule, string $default): string
    {
        return self::$customMessages[$rule] ?? $default;
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
        self::registerStrategy('email', function ($value) {
            return filter_var($value, FILTER_VALIDATE_EMAIL) === false
                ? self::message('email', 'validation.email')
                : null;
        });

        // Numeric validation
        self::registerStrategy('numeric', function ($value) {
            return !is_numeric($value) ? self::message('numeric', 'validation.numeric') : null;
        });

        // Integer validation
        self::registerStrategy('integer', function ($value) {
            return filter_var($value, FILTER_VALIDATE_INT) === false ? self::message('integer', 'validation.integer') : null;
        });

        // Date validation
        self::registerStrategy('date', function ($value) {
            $ts = is_int($value) || is_float($value) ? $value : strtotime(is_scalar($value) ? (string) $value : '');
            return ($ts === false || $ts <= 0) ? self::message('date', 'validation.date') : null;
        });

        // URL validation
        self::registerStrategy('url', function ($value) {
            return filter_var($value, FILTER_VALIDATE_URL) === false ? self::message('url', 'validation.url') : null;
        });

        // File validation
        self::registerStrategy('file', function ($value): ?string {
            return (!($value instanceof UploadedFile) || !$value->isValid()) ? self::message('file', 'validation.file') : null;
        });

        // Min validation (handles strings, numbers, files)
        self::registerStrategy('min', function ($value, ?string $param, array $input = [], string $field = ''): array|null {
            if ($param === null) return null;
            $min = (int) $param;

            if ($value instanceof UploadedFile && $value->isValid()) {
                $sizeInKb = (int) ceil($value->getSize() / 1024);
                return $sizeInKb < $min ? [self::message('min', 'validation.min'), ['min' => (string) $min]] : null;
            }

            if (is_int($value)) {
                return $value < $min ? [self::message('min', 'validation.min'), ['min' => (string) $min]] : null;
            }

            if (is_string($value)) {
                if (is_numeric($value)) {
                    return (float) $value < $min ? [self::message('min', 'validation.min'), ['min' => (string) $min]] : null;
                }
                return strlen(trim($value)) < $min ? [self::message('min', 'validation.min'), ['min' => (string) $min]] : null;
            }

            if (is_float($value)) {
                return $value < $min ? [self::message('min', 'validation.min'), ['min' => (string) $min]] : null;
            }
            return null;
        });

        // Max validation (handles strings, numbers, files)
        self::registerStrategy('max', function ($value, ?string $param, array $input = [], string $field = ''): array|null {
            if ($param === null) return null;
            $max = (int) $param;

            if ($value instanceof UploadedFile && $value->isValid()) {
                $sizeInKb = (int) ceil($value->getSize() / 1024);
                return $sizeInKb > $max ? [self::message('max', 'validation.max'), ['max' => (string) $max]] : null;
            }

            if (is_int($value)) {
                return $value > $max ? [self::message('max', 'validation.max'), ['max' => (string) $max]] : null;
            }

            if (is_string($value)) {
                if (is_numeric($value)) {
                    return (float) $value > $max ? [self::message('max', 'validation.max'), ['max' => (string) $max]] : null;
                }
                return strlen(trim($value)) > $max ? [self::message('max', 'validation.max'), ['max' => (string) $max]] : null;
            }

            if (is_float($value)) {
                return $value > $max ? [self::message('max', 'validation.max'), ['max' => (string) $max]] : null;
            }
            return null;
        });

        // Confirmed validation
        self::registerStrategy('confirmed', function ($value, ?string $param, array $input, string $field): ?string {
            $confirmationField = $field . '_confirmation';
            $confirmationValue = $input[$confirmationField] ?? null;
            return $value !== $confirmationValue ? self::message('confirmed', 'validation.confirmed') : null;
        });

        // In validation
        self::registerStrategy('in', function ($value, ?string $param, array $input = [], string $field = ''): array|null {
            if ($param === null) return null;
            $allowedValues = array_map('trim', explode(',', $param));
            return !in_array(is_scalar($value) ? (string) $value : '', $allowedValues, true)
                ? [self::message('in', 'validation.in'), ['values' => implode(', ', $allowedValues)]]
                : null;
        });

        // Regex validation
        self::registerStrategy('regex', function ($value, ?string $param): ?string {
            if ($param === null) return null;
            try {
                if (preg_match((string) $param, '') === false) return null;
            } catch (\Throwable $e) {
                \Siro\Core\Logger::error('Regex validation failed: ' . $e->getMessage());
                return null;
            }
            return !preg_match($param, is_scalar($value) ? (string) $value : '') ? self::message('regex', 'validation.regex') : null;
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

        // Initialize strategies once for all rules
        self::initStrategies();

        foreach ($rules as $field => $ruleLine) {
            // Skip wildcard rules in the main loop — they are handled in a second pass
            if (str_contains($field, '.*.')) {
                continue;
            }

            $value = $input[$field] ?? null;

            // Reject arrays for non-file rules (type confusion)
            if (is_array($value) && $value !== []) {
                $fieldRules = explode('|', $ruleLine);
                if (!in_array('file', $fieldRules, true)) {
                    $errors[$field][] = self::msg(self::message('array', 'validation.array'), ['field' => self::label($field)]);
                    continue;
                }
            }

            $fieldErrors = self::validateValue($value, $field, $ruleLine, $input);
            foreach ($fieldErrors as $errField => $errMsgs) {
                foreach ($errMsgs as $msg) {
                    $errors[$errField][] = $msg;
                }
            }
        }

        // Second pass: handle wildcard rules (e.g. items.*.product_id)
        foreach ($rules as $field => $ruleLine) {
            if (!str_contains($field, '.*.')) {
                continue;
            }

            $dotStarDotPos = strpos($field, '.*.');
            $baseKey = substr($field, 0, $dotStarDotPos);
            $nestedField = substr($field, $dotStarDotPos + 3);

            $arrayValue = $input[$baseKey] ?? null;
            if (!is_array($arrayValue)) {
                continue;
            }

            foreach ($arrayValue as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }

                $nestedValue = $item[$nestedField] ?? null;
                $indexedField = $baseKey . '.' . $index . '.' . $nestedField;

                $fieldErrors = self::validateValue($nestedValue, $indexedField, $ruleLine, $input);
                foreach ($fieldErrors as $errField => $errMsgs) {
                    foreach ($errMsgs as $msg) {
                        $errors[$errField][] = $msg;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Validate a single value against a rule string.
     *
     * @param mixed $value The value to validate
     * @param string $field The field name (for error reporting)
     * @param string $ruleLine The rule string (e.g., 'required|integer|min:1')
     * @param array<string, mixed> $input Full input array
     * @return array<string, array<int, string>> Errors keyed by field (empty if valid)
     */
    private static function validateValue(mixed $value, string $field, string $ruleLine, array $input): array
    {
        $errors = [];

        // Use cached parsed rules if available
        if (!isset(self::$parsedRuleCache[$ruleLine])) {
            $fieldRules = explode('|', $ruleLine);
            $isNullable = in_array('nullable', $fieldRules, true);
            $isRequired = in_array('required', $fieldRules, true);
            $requiredIf = null;
            foreach ($fieldRules as $rule) {
                if (str_starts_with($rule, 'required_if:')) {
                    $requiredIf = substr($rule, 12);
                    break;
                }
            }
            self::$parsedRuleCache[$ruleLine] = [
                'parsed' => $fieldRules,
                'nullable' => $isNullable,
                'required' => $isRequired,
                'requiredIf' => $requiredIf,
            ];
        }
        $cached = self::$parsedRuleCache[$ruleLine];
        $fieldRules = $cached['parsed'];
        $isNullable = $cached['nullable'];
        $isRequired = $cached['required'];
        $requiredIf = $cached['requiredIf'];

        if ($requiredIf !== null) {
            $parts = explode(',', $requiredIf, 2);
            $otherField = trim($parts[0]);
            $otherValue = trim($parts[1] ?? '');
            if ($otherField !== '' && ($input[$otherField] ?? null) === $otherValue) {
                $isRequired = true;
            }
        }

        // Check required
        $checkValue = is_string($value) ? trim($value) : $value;
        if ($isRequired && ($checkValue === null || $checkValue === '')) {
            $errors[$field][] = self::msg(self::message('required', 'validation.required'), ['field' => self::label($field)]);
            return $errors;
        }

        // Skip validation for empty values if nullable or not required
        if (($value === null || $value === '') && !$isRequired) {
            return $errors;
        }
        if ($value === null && $isNullable) {
            return $errors;
        }

        foreach ($fieldRules as $rule) {
            // Skip meta-rules
            if ($rule === 'nullable' || $rule === 'required' || str_starts_with($rule, 'required_if:')) {
                continue;
            }

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
                continue;
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
                        $key = isset($result[0]) && is_scalar($result[0]) ? (string) $result[0] : '';
                        $replacements = isset($result[1]) && is_array($result[1]) ? $result[1] : [];
                        /** @var array<string, string> $replacements */
                        $replacements['field'] = self::label($field);
                        $errors[$field][] = self::msg($key, $replacements);
                    } else {
                        $errors[$field][] = self::msg(is_scalar($result) ? (string) $result : '', ['field' => self::label($field)]);
                    }
                    continue;
                }
            }

            // Handle unique/exists rules (need database access)
            if ($ruleName === 'unique') {
                if ($ruleParam !== null) {
                    $parts = explode(',', $ruleParam);
                    $table = trim($parts[0]);
                    $column = trim($parts[1] ?? $field);

                    if ($table !== '') {
                        $exists = Database::table($table)
                            ->where($column, '=', $value)
                            ->count();

                        if ($exists > 0) {
                            $errors[$field][] = self::msg(self::message('unique', 'validation.unique'), ['field' => self::label($field)]);
                            continue;
                        }
                    }
                }
            }

            if ($ruleName === 'exists') {
                if ($ruleParam !== null) {
                    $parts = explode(',', $ruleParam);
                    $table = trim($parts[0]);
                    $column = trim($parts[1] ?? $field);

                    if ($table !== '') {
                        $exists = Database::table($table)
                            ->where($column, '=', $value)
                            ->count();

                        if ($exists === 0) {
                            $errors[$field][] = self::msg(self::message('exists', 'validation.exists'), ['field' => self::label($field)]);
                            continue;
                        }
                    }
                }
            }
        }

        return $errors;
    }

    /** @param array<string, string> $replace */
    private static function msg(string $key, array $replace = []): string
    {
        $translated = Lang::get($key, $replace);
        if ($translated !== $key) {
            return $translated;
        }
        // Fallback to English if no translation file
        return self::fallback($key, $replace);
    }

    /** @param array<string, string> $replace */
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
        return htmlspecialchars(ucfirst(str_replace('_', ' ', $field)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
