<?php

declare(strict_types=1);

namespace Siro\Core\Testing;

/**
 * Zero-dependency seeded faker for factories and tests.
 *
 * Deterministic once seeded (mt_rand based), except uuid() which needs
 * cryptographic randomness. Vietnamese-flavored defaults for local apps.
 *
 * Usage:
 *   Faker::seed(42);
 *   $name = Faker::vnFullName();   // "Nguyen Thi Lan"
 *   $phone = Faker::vnPhone();     // "0912345678"
 *
 * @package Siro\Core\Testing
 */
final class Faker
{
    /** @var array<int, string> */
    private const LAST_NAMES = [
        'Nguyen', 'Tran', 'Le', 'Pham', 'Hoang', 'Huynh', 'Phan', 'Vu',
        'Vo', 'Dang', 'Bui', 'Do', 'Ho', 'Ngo', 'Duong', 'Ly',
    ];

    /** @var array<int, string> */
    private const MIDDLE_NAMES = [
        'Van', 'Thi', 'Huu', 'Minh', 'Thanh', 'Ngoc', 'Duc', 'Kim',
        'Hoang', 'Phuong', 'Quoc', 'Gia', 'Bao', 'Anh', 'Tuan', 'Hai',
    ];

    /** @var array<int, string> */
    private const FIRST_NAMES = [
        'An', 'Binh', 'Cuong', 'Dung', 'Hai', 'Hoa', 'Hung', 'Huong',
        'Khanh', 'Lan', 'Linh', 'Long', 'Mai', 'Minh', 'Nam', 'Ngoc',
        'Phuc', 'Son', 'Thao', 'Trang', 'Tuan', 'Vy',
    ];

    /** @var array<int, string> */
    private const WORDS = [
        'siro', 'nhanh', 'gon', 'nhe', 'api', 'don', 'hang', 'thanh',
        'toan', 'bao', 'cao', 'moi', 'sach', 'dep', 'tot', 'khoe',
    ];

    /** @var array<int, string> */
    private const PHONE_PREFIXES = ['032', '033', '034', '035', '036', '037', '038', '039', '070', '079', '077', '076', '078', '083', '084', '085', '081', '082', '056', '058', '059', '090', '093', '089', '091', '094', '088', '092', '096', '097', '098'];

    public static function seed(int $seed): void
    {
        mt_srand($seed);
    }

    public static function vnFullName(): string
    {
        return self::text(self::pick(self::LAST_NAMES)) . ' ' . self::text(self::pick(self::MIDDLE_NAMES)) . ' ' . self::text(self::pick(self::FIRST_NAMES));
    }

    public static function vnPhone(): string
    {
        $digits = '';
        for ($i = 0; $i < 7; $i++) {
            $digits .= (string) mt_rand(0, 9);
        }
        return '0' . ltrim(self::text(self::pick(self::PHONE_PREFIXES)), '0') . $digits;
    }

    public static function email(string $name = ''): string
    {
        $local = $name !== '' ? self::slug($name) : self::slug(self::text(self::pick(self::FIRST_NAMES))) . '.' . self::slug(self::text(self::pick(self::LAST_NAMES)));
        return strtolower($local) . mt_rand(1, 999) . '@example.com';
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    public static function intBetween(int $min, int $max): int
    {
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }
        return mt_rand($min, $max);
    }

    /**
     * @param array<int|string, mixed> $items
     */
    public static function pick(array $items): mixed
    {
        if ($items === []) {
            return null;
        }
        $values = array_values($items);
        return $values[mt_rand(0, count($values) - 1)];
    }

    public static function sentence(int $words = 6): string
    {
        $parts = [];
        for ($i = 0; $i < max(1, $words); $i++) {
            $parts[] = self::text(self::pick(self::WORDS));
        }
        $text = implode(' ', $parts);
        return ucfirst($text) . '.';
    }

    private static function text(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        return '';
    }

    public static function date(string $from = '-2 years', string $to = 'now', string $format = 'Y-m-d H:i:s'): string
    {
        $start = (int) strtotime($from);
        $end = (int) strtotime($to);
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
        return date($format, mt_rand($start, $end));
    }

    public static function boolean(int $chance = 50): bool
    {
        return mt_rand(1, 100) <= max(0, min(100, $chance));
    }

    private static function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', '.', $value);
        return trim($value, '.');
    }
}
