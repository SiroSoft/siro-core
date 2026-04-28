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

                // unique:table,column
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
                            $errors[$field][] = sprintf('%s already exists', self::label($field));
                            continue;
                        }
                    }
                }

                // exists:table,column
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
                            $errors[$field][] = sprintf('%s does not exist', self::label($field));
                            continue;
                        }
                    }
                }

                // confirmed (checks if field_confirmation matches)
                if ($rule === 'confirmed') {
                    $confirmationField = $field . '_confirmation';
                    $confirmationValue = $input[$confirmationField] ?? null;
                    
                    if ($value !== $confirmationValue) {
                        $errors[$field][] = sprintf('%s confirmation does not match', self::label($field));
                        continue;
                    }
                }

                // in:a,b,c
                if (str_starts_with($rule, 'in:')) {
                    $allowedValues = array_map('trim', explode(',', substr($rule, 3)));
                    
                    if (!in_array((string) $value, $allowedValues, true)) {
                        $errors[$field][] = sprintf('%s must be one of: %s', self::label($field), implode(', ', $allowedValues));
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
