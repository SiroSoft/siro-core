<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Fuzz;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use Siro\Core\Response;

final class FuzzResponseTest extends TestCase
{
    #[DataProvider('providePayloadVariations')]
    public function testSuccessNeverThrows(mixed $data, string $message, int $status): void
    {
        $r = Response::success($data, $message, $status);
        $this->assertInstanceOf(Response::class, $r);
        $payload = $r->payload();
        $this->assertIsArray($payload);
    }

    /** @return iterable<string, array{mixed, string, int}> */
    public static function providePayloadVariations(): iterable
    {
        $datasets = [
            [null, 'OK', 200],
            [['id' => 1], 'Created', 201],
            [[], 'No Content', 204],
            ['string data', 'OK', 200],
            [42, 'OK', 200],
            [true, 'OK', 200],
            [['nested' => ['deep' => 'value']], 'OK', 200],
            [str_repeat('x', 10000), 'Large', 200],
            [null, '', 200],
            [null, str_repeat('x', 1000), 200],
        ];
        $idx = 0;
        foreach ($datasets as $data) {
            yield 'sv_' . $idx++ => [$data[0], $data[1], $data[2]];
        }
    }

    #[DataProvider('provideErrorVariations')]
    public function testErrorNeverThrows(string $message, int $status, array $errors): void
    {
        $r = Response::error($message, $status, $errors);
        $this->assertInstanceOf(Response::class, $r);
        $payload = $r->payload();
        $this->assertFalse($payload['success']);
    }

    /** @return iterable<string, array{string, int, array}> */
    public static function provideErrorVariations(): iterable
    {
        yield 'basic' => ['Not found', 404, []];
        yield 'validation' => ['Validation failed', 422, ['name' => ['Required'], 'email' => ['Invalid']]];
        yield 'server error' => ['Server error', 500, ['trace' => '...']];
        yield 'empty message' => ['', 400, []];
        yield 'unicode' => ['Not found', 418, []];
        yield 'nested errors' => ['Bad request', 400, ['meta' => ['errors' => ['deep' => 'error']]]];
    }

    #[DataProvider('providePaginatedVariations')]
    public function testPaginatedNeverThrows(array $data, array $meta, string $message): void
    {
        $r = Response::paginated($data, $meta, $message);
        $this->assertInstanceOf(Response::class, $r);
        $payload = $r->payload();
        $this->assertIsArray($payload);
    }

    /** @return iterable<string, array{array, array, string}> */
    public static function providePaginatedVariations(): iterable
    {
        yield 'empty' => [[], ['page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1], 'OK'];
        yield 'one page' => [[['id' => 1]], ['page' => 1, 'per_page' => 15, 'total' => 1, 'last_page' => 1], 'OK'];
        yield 'large meta' => [[], ['page' => 1, 'per_page' => 100, 'total' => 0, 'last_page' => 1], ''];
    }

    #[DataProvider('provideStatusCodeRanges')]
    public function testStatusCodeNeverThrows(int $status): void
    {
        $r = Response::error('test', $status);
        $this->assertSame($status, $r->statusCode());
    }

    /** @return iterable<string, array{int}> */
    public static function provideStatusCodeRanges(): iterable
    {
        foreach ([200, 201, 204, 301, 302, 400, 401, 403, 404, 409, 422, 429, 500, 502, 503] as $code) {
            yield (string) $code => [$code];
        }
    }

    #[DataProvider('provideHeaderVariations')]
    public function testHeadersNeverThrows(string $name, string $value): void
    {
        $r = Response::success(null);
        $r->header($name, $value);
        $this->assertTrue(true);
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideHeaderVariations(): iterable
    {
        yield 'content-type' => ['Content-Type', 'application/json'];
        yield 'custom' => ['X-Custom', 'value'];
        yield 'unicode header' => ['X-Data', 'value'];
        yield 'empty value' => ['X-Empty', ''];
        yield 'long value' => ['X-Long', str_repeat('x', 1000)];
    }
}
