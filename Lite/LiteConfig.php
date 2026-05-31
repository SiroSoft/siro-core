<?php

declare(strict_types=1);

namespace Siro\Core\Lite;

final class LiteConfig
{
    private bool $enabled = false;

    /** @param array<string, mixed> $config */
    public function loadFromConfig(array $config): void
    {
        $this->enabled = isset($config['lite_mode']) && $config['lite_mode'] === 'enabled';
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
