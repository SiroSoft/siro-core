<?php

declare(strict_types=1);

namespace Siro\Core;

/**
 * String manipulation utility class.
 *
 * Provides slug generation, case conversion, truncation,
 * pluralization, and common string inspection methods.
 * All methods are multibyte-safe (UTF-8).
 *
 * @package Siro\Core
 */
final class Str
{
    public static function slug(string $value, string $separator = '-'): string
    {
        $value = preg_replace('/[^\p{L}\p{N}\s\-_]/u', '', $value);
        $value = preg_replace('/[\s\-_]+/', $separator, $value);
        $value = preg_replace('/' . preg_quote($separator, '/') . '+/', $separator, $value);
        return trim(mb_strtolower($value), $separator);
    }

    public static function limit(string $value, int $limit = 100, string $end = '...'): string
    {
        if (mb_strwidth($value, 'UTF-8') <= $limit) {
            return $value;
        }
        return rtrim(mb_strimwidth($value, 0, max(0, $limit - mb_strwidth($end)), '', 'UTF-8')) . $end;
    }

    public static function words(string $value, int $words = 100, string $end = '...'): string
    {
        $parts = preg_split('/\s+/u', $value) ?: [];
        if (count($parts) <= $words) {
            return $value;
        }
        return implode(' ', array_slice((array) $parts, 0, $words)) . $end;
    }

    public static function camel(string $value): string
    {
        return lcfirst(self::studly($value));
    }

    public static function studly(string $value): string
    {
        return str_replace(' ', '', mb_convert_case(str_replace(['-', '_'], ' ', $value), MB_CASE_TITLE));
    }

    public static function snake(string $value, string $delimiter = '_'): string
    {
        $value = preg_replace('/[\s\-]+/', $delimiter, $value);
        $value = preg_replace('/([A-Z])/', $delimiter . '$1', $value);
        return trim(mb_strtolower($value), $delimiter);
    }

    public static function kebab(string $value): string
    {
        return self::snake($value, '-');
    }

    public static function contains(string $haystack, string $needle): bool
    {
        return $needle !== '' && mb_strpos($haystack, $needle) !== false;
    }

    public static function startsWith(string $haystack, string $needle): bool
    {
        return $needle !== '' && mb_strpos($haystack, $needle) === 0;
    }

    public static function endsWith(string $haystack, string $needle): bool
    {
        return $needle !== '' && mb_substr($haystack, -mb_strlen($needle)) === $needle;
    }

    public static function after(string $subject, string $search): string
    {
        $pos = mb_strpos($subject, $search);
        return $pos === false ? $subject : mb_substr($subject, $pos + mb_strlen($search));
    }

    public static function before(string $subject, string $search): string
    {
        $pos = mb_strpos($subject, $search);
        return $pos === false ? $subject : mb_substr($subject, 0, $pos);
    }

    public static function random(int $length = 16): string
    {
        return substr(bin2hex(random_bytes(32)), 0, $length);
    }

    public static function padBoth(string $value, int $length, string $pad = ' '): string
    {
        return str_pad($value, $length, $pad, STR_PAD_BOTH);
    }

    public static function substr(string $string, int $start, ?int $length = null): string
    {
        return mb_substr($string, $start, $length);
    }

    public static function ucfirst(string $string): string
    {
        return mb_strtoupper(mb_substr($string, 0, 1)) . mb_substr($string, 1);
    }

    public static function lower(string $value): string
    {
        return mb_strtolower($value);
    }

    public static function upper(string $value): string
    {
        return mb_strtoupper($value);
    }

    public static function title(string $value): string
    {
        return mb_convert_case($value, MB_CASE_TITLE);
    }

    public static function replace(string $search, string $replace, string $subject): string
    {
        return str_replace($search, $replace, $subject);
    }

    public static function length(string $value): int
    {
        return mb_strlen($value);
    }

    public static function isJson(string $value): bool
    {
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    public static function plural(string $value): string
    {
        $rules = [
            '/(quiz)$/i' => '$1zes',
            '/^(ox)$/i' => '$1en',
            '/([m|l])ouse$/i' => '$1ice',
            '/(matr|vert|ind)ix|ex$/i' => '$1ices',
            '/(x|ch|ss|sh)$/i' => '$1es',
            '/([^aeiouy]|qu)y$/i' => '$1ies',
            '/(hive)$/i' => '$1s',
            '/(?:([^f])fe|([lr])f)$/i' => '$1$2ves',
            '/(shea|lea|loa|thie)f$/i' => '$1ves',
            '/sis$/i' => 'ses',
            '/([ti])um$/i' => '$1a',
            '/(tomat|potat|ech|her|vet)o$/i' => '$1oes',
            '/(bu)s$/i' => '$1ses',
            '/(alias|status)$/i' => '$1es',
            '/(octop|vir)us$/i' => '$1i',
            '/(ax|test)is$/i' => '$1es',
            '/s$/i' => 's',
            '/$/' => 's',
        ];
        foreach ($rules as $pattern => $replacement) {
            if (preg_match($pattern, $value)) {
                return preg_replace($pattern, $replacement, $value);
            }
        }
        return $value;
    }

    public static function singular(string $value): string
    {
        $rules = [
            '/(quiz)zes$/i' => '$1',
            '/(matr)ices$/i' => '$1ix',
            '/(vert|ind)ices$/i' => '$1ex',
            '/^(ox)en$/i' => '$1',
            '/(alias|status)es$/i' => '$1',
            '/([octop|vir])i$/i' => '$1us',
            '/(cris|ax|test)es$/i' => '$1is',
            '/(shoe)s$/i' => '$1',
            '/(o)es$/i' => '$1',
            '/(bus)es$/i' => '$1',
            '/([m|l])ice$/i' => '$1ouse',
            '/(x|ch|ss|sh)es$/i' => '$1',
            '/(m)ovies$/i' => '$1ovie',
            '/(s)eries$/i' => '$1eries',
            '/([^aeiouy]|qu)ies$/i' => '$1y',
            '/([lr])ves$/i' => '$1f',
            '/(tive)s$/i' => '$1',
            '/(hive)s$/i' => '$1',
            '/([^f])ves$/i' => '$1fe',
            '/(^analy)ses$/i' => '$1sis',
            '/((a)naly|(b)a|(d)iagno|(p)arenthe|(p)rogno|(s)ynop|(t)he)ses$/i' => '$1$2sis',
            '/([ti])a$/i' => '$1um',
            '/(n)ews$/i' => '$1ews',
            '/s$/i' => '',
        ];
        foreach ($rules as $pattern => $replacement) {
            if (preg_match($pattern, $value)) {
                return preg_replace($pattern, $replacement, $value);
            }
        }
        return $value;
    }
}
