<?php

declare(strict_types=1);

namespace Siro\Core;

/**
 * Lightweight multi-language translation engine.
 *
 * Loads PHP array files from storage/lang/{locale}/
 * Supports dot-notation keys, parameter replacement, and locale fallback.
 *
 * Config:
 *   APP_LOCALE=en           Default locale
 *   APP_FALLBACK_LOCALE=en  Fallback when key is missing
 *
 * Usage:
 *   Lang::get('messages.welcome')                    // "Welcome!"
 *   Lang::get('validation.required', ['field' => 'Email'])  // "Email is required"
 *   Lang::locale()                                    // "vi"
 *   Lang::setLocale('vi')                             // Override
 *
 * File format (storage/lang/vi/messages.php):
 *   <?php return ['welcome' => 'Chào mừng!'];
 *
 * @package Siro\Core
 */
final class Lang
{
    private static string $locale = '';
    private static string $fallbackLocale = 'en';
    private static string $basePath = '';
    /** @var array<string, array<string, mixed>> */
    private static array $loaded = [];

    /**
     * Initialize the Lang system.
     */
    public static function boot(string $basePath): void
    {
        self::$basePath = rtrim($basePath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'lang';
        self::$locale = (string) Env::get('APP_LOCALE', 'en');
        self::$fallbackLocale = (string) Env::get('APP_FALLBACK_LOCALE', 'en');
    }

    /**
     * Get the base path for language files.
     */
    public static function basePath(): string
    {
        return self::$basePath;
    }

    /**
     * Get the current locale.
     */
    public static function locale(): string
    {
        return self::$locale !== '' ? self::$locale : 'en';
    }

    /**
     * Override the current locale at runtime.
     */
    public static function setLocale(string $locale): void
    {
        self::$locale = $locale;
    }

    /**
     * Get a translation string.
     *
     * @param string $key Dot-notation key, e.g. "messages.welcome"
     * @param array<string, mixed> $replace Parameters for :param placeholders
     * @param string|null $locale Override locale (null = use current)
     * @return string Translated string, or the key if not found
     */
    public static function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale = $locale ?? self::$locale;
        $parts = explode('.', $key, 2);

        if (count($parts) !== 2) {
            return $key;
        }

        [$file, $item] = $parts;

        // Try requested locale first, then fallback
        $value = self::resolve($file, $item, $locale);
        if ($value === null && $locale !== self::$fallbackLocale) {
            $value = self::resolve($file, $item, self::$fallbackLocale);
        }

        if ($value === null) {
            return $key;
        }

        return self::replace((string) $value, $replace);
    }

    /**
     * Check if a translation key exists.
     */
    public static function has(string $key, ?string $locale = null): bool
    {
        $locale = $locale ?? self::$locale;
        $parts = explode('.', $key, 2);

        if (count($parts) !== 2) {
            return false;
        }

        [$file, $item] = $parts;
        return self::resolve($file, $item, $locale) !== null
            || self::resolve($file, $item, self::$fallbackLocale) !== null;
    }

    /**
     * Resolve a translation from a file + key.
     */
    private static function resolve(string $file, string $item, string $locale): ?string
    {
        $lines = self::load($file, $locale);
        if ($lines === null) {
            return null;
        }

        return self::dotGet($lines, $item);
    }

    /**
     * Load translation file for a locale.
     * @return array<string, mixed>|null
     */
    private static function load(string $file, string $locale): ?array
    {
        $cacheKey = "lang.{$locale}.{$file}";

        if (isset(self::$loaded[$cacheKey])) {
            return self::$loaded[$cacheKey];
        }

        $path = self::$basePath . DIRECTORY_SEPARATOR . $locale
            . DIRECTORY_SEPARATOR . $file . '.php';

        if (!is_file($path)) {
            return null;
        }

        $lines = (array) require $path;
        self::$loaded[$cacheKey] = $lines;
        return $lines;
    }

    /**
     * Get a nested value using dot notation.
     * @param array<string, mixed> $array
     */
    private static function dotGet(array $array, string $key): ?string
    {
        if (isset($array[$key])) {
            return (string) $array[$key];
        }

        $keys = explode('.', $key);
        $current = $array;

        foreach ($keys as $segment) {
            if (!is_array($current) || !isset($current[$segment])) {
                return null;
            }
            $current = $current[$segment];
        }

        return is_string($current) ? $current : null;
    }

    /**
     * Replace :param placeholders with values.
     */
    private static function replace(string $message, array $replace): string
    {
        if ($replace === []) {
            return $message;
        }

        $search = [];
        $replacements = [];

        foreach ($replace as $key => $value) {
            $search[] = ':' . $key;
            $replacements[] = (string) $value;
        }

        return str_replace($search, $replacements, $message);
    }

    /**
     * Pluralize a translation based on count.
     *
     * Grammar: "{count} {singular}|{plural}"
     * Example: Lang::plural("message.apples", 5)  → "5 apples"
     * Example: Lang::plural("message.apples", 1)  → "1 apple"
     *
     * @param string $key Translation key
     * @param int $count Number for pluralization
     * @param array<string, mixed> $replace Additional replacements
     */
    public static function plural(string $key, int $count, array $replace = []): string
    {
        $template = self::get($key, $replace);
        $parts = explode('|', $template);

        $form = $count === 1 ? ($parts[0] ?? $template) : ($parts[1] ?? $parts[0] ?? $template);
        return str_replace('{count}', (string) $count, $form);
    }
}
