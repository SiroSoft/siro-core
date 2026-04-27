<?php

declare(strict_types=1);

namespace Siro\Core;

final class Validator
{
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
                    $errors[$field][] = self::label($field) . ' is required';
                    continue;
                }

                if ($value === null || $value === '') {
                    continue;
                }

                if ($rule === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $errors[$field][] = self::label($field) . ' is invalid';
                    continue;
                }

                if ($rule === 'numeric' && !is_numeric($value)) {
                    $errors[$field][] = self::label($field) . ' must be numeric';
                    continue;
                }

                if ($rule === 'integer' && filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $errors[$field][] = self::label($field) . ' must be an integer';
                    continue;
                }

                if (str_starts_with($rule, 'min:')) {
                    $min = (int) substr($rule, 4);
                    if (is_string($value) && strlen($value) < $min) {
                        $errors[$field][] = sprintf('%s must be at least %d characters', self::label($field), $min);
                        continue;
                    }

                    if (is_numeric($value) && (float) $value < $min) {
                        $errors[$field][] = sprintf('%s must be at least %d', self::label($field), $min);
                        continue;
                    }
                }

                if (str_starts_with($rule, 'max:')) {
                    $max = (int) substr($rule, 4);
                    if (is_string($value) && strlen($value) > $max) {
                        $errors[$field][] = sprintf('%s must not be greater than %d characters', self::label($field), $max);
                        continue;
                    }

                    if (is_numeric($value) && (float) $value > $max) {
                        $errors[$field][] = sprintf('%s must not be greater than %d', self::label($field), $max);
                        continue;
                    }
                }
            }
        }

        return $errors;
    }

    private static function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }
}
