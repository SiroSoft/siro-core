<?php

declare(strict_types=1);

namespace Siro\Core;

/**
 * Input validation engine.
 *
 * Supports rules: required, email, numeric, integer, min, max,
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
     * @param array<string, mixed> $input
     * @param array<string, string> $rules
     * @return array<string, array<int, string>>
     */
    public static function make(array $input, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleLine) {
            $value = $input[$field] ?? null;
            $fieldRules = explode('|', $ruleLine);

            foreach ($fieldRules as $rule) {
                if ($rule === 'required' && ($value === null || $value === '')) {
                    $errors[$field][] = self::msg('validation.required', ['field' => self::label($field)]);
                    continue;
                }

                if ($value === null || $value === '') {
                    continue;
                }

                if ($rule === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $errors[$field][] = self::msg('validation.email', ['field' => self::label($field)]);
                    continue;
                }

                if ($rule === 'numeric' && !is_numeric($value)) {
                    $errors[$field][] = self::msg('validation.numeric', ['field' => self::label($field)]);
                    continue;
                }

                if ($rule === 'integer' && filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $errors[$field][] = self::msg('validation.integer', ['field' => self::label($field)]);
                    continue;
                }

                if (str_starts_with($rule, 'min:')) {
                    $min = (int) substr($rule, 4);
                    if (is_string($value) && strlen($value) < $min) {
                        $errors[$field][] = self::msg('validation.min', ['field' => self::label($field), 'min' => (string) $min]);
                        continue;
                    }

                    if (is_numeric($value) && (float) $value < $min) {
                        $errors[$field][] = self::msg('validation.min', ['field' => self::label($field), 'min' => (string) $min]);
                        continue;
                    }
                }

                if (str_starts_with($rule, 'max:')) {
                    $max = (int) substr($rule, 4);
                    if (is_string($value) && strlen($value) > $max) {
                        $errors[$field][] = self::msg('validation.max', ['field' => self::label($field), 'max' => (string) $max]);
                        continue;
                    }

                    if (is_numeric($value) && (float) $value > $max) {
                        $errors[$field][] = self::msg('validation.max', ['field' => self::label($field), 'max' => (string) $max]);
                        continue;
                    }
                }

                if (str_starts_with($rule, 'unique:')) {
                    $params = substr($rule, 7);
                    $parts = explode(',', $params);
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

                if (str_starts_with($rule, 'exists:')) {
                    $params = substr($rule, 7);
                    $parts = explode(',', $params);
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

                if ($rule === 'confirmed') {
                    $confirmationField = $field . '_confirmation';
                    $confirmationValue = $input[$confirmationField] ?? null;

                    if ($value !== $confirmationValue) {
                        $errors[$field][] = self::msg('validation.confirmed', ['field' => self::label($field)]);
                        continue;
                    }
                }

                if (str_starts_with($rule, 'in:')) {
                    $allowedValues = array_map('trim', explode(',', substr($rule, 3)));

                    if (!in_array((string) $value, $allowedValues, true)) {
                        $errors[$field][] = self::msg('validation.in', [
                            'field' => self::label($field),
                            'values' => implode(', ', $allowedValues),
                        ]);
                        continue;
                    }
                }

                // Custom rules registered via Validator::extend()
                $ruleName = $rule;
                $ruleParam = null;
                if (str_contains($rule, ':')) {
                    $parts = explode(':', $rule, 2);
                    $ruleName = $parts[0];
                    $ruleParam = $parts[1];
                }

                if (isset(self::$customRules[$ruleName])) {
                    $result = (self::$customRules[$ruleName])($value, $field, $input, $ruleParam);
                    if ($result !== true) {
                        $msg = is_string($result) ? $result : self::label($field) . ' is invalid';
                        $errors[$field][] = str_replace(':field', self::label($field), $msg);
                        continue;
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
            'validation.min' => ':field must be at least :min',
            'validation.max' => ':field must not exceed :max',
            'validation.unique' => ':field already exists',
            'validation.exists' => ':field does not exist',
            'validation.confirmed' => ':field confirmation does not match',
            'validation.in' => ':field must be one of: :values',
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
