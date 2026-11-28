<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Resource;

final class ApiCrudProductResource extends Resource
{
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = $this->data;
        $data['label'] = strtoupper((string) ($data['name'] ?? ''));
        return $data;
    }
}
