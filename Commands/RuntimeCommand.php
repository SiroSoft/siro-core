<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\RuntimeManager;

final class RuntimeCommand implements CommandInterface
{
    use CommandSupport;

    public function __construct(string $basePath = '')
    {
        $basePath = '';
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $manager = new RuntimeManager();
        $action = $args[0] ?? 'list';

        return match ($action) {
            'install' => $this->install($manager, $args[1] ?? ''),
            'switch' => $this->switch($manager, $args[1] ?? ''),
            'list' => $this->listVersions($manager),
            'remove' => $this->remove($manager, $args[1] ?? ''),
            'current' => $this->current($manager),
            'path' => $this->path($manager),
            default => $this->help(),
        };
    }

    private function install(RuntimeManager $mgr, string $version): int
    {
        if ($version === '') {
            $this->write('Usage: siro runtime:install <version>');
            $this->write('  Examples: 8.2, 8.3, 8.4');
            return 1;
        }

        $this->write("Installing Siro Runtime {$version}...");
        $result = $mgr->install($version);

        if (!$result['success']) {
            $this->error($result['message']);
            return 1;
        }

        $this->success($result['message']);
        if (isset($result['dir'])) {
            $this->write("  Location: {$result['dir']}");
        }
        return 0;
    }

    private function switch(RuntimeManager $mgr, string $version): int
    {
        if ($version === '') {
            $this->write('Usage: siro runtime:switch <version>');
            return 1;
        }

        $result = $mgr->switch($version);
        if ($result['success']) {
            $this->success($result['message']);
            return 0;
        }
        $this->error($result['message']);
        return 1;
    }

    private function listVersions(RuntimeManager $mgr): int
    {
        $versions = $mgr->listVersions();
        if ($versions === []) {
            $this->write('No Siro Runtimes installed.');
            $this->write('  Run: siro runtime:install 8.2');
            return 0;
        }

        $active = $mgr->getActive();
        $this->write('Installed Siro Runtimes:');
        $this->write('');

        foreach ($versions as $v) {
            $marker = $v['active'] ? '  👉' : '   ';
            $line = "{$marker} PHP {$v['version']}";
            if ($v['php_version'] !== '?') {
                $line .= " (v{$v['php_version']})";
            }
            $this->write($line);
        }

        $this->write('');
        $this->write('Active: ' . ($active ? "PHP {$active}" : '(none)'));

        return 0;
    }

    private function remove(RuntimeManager $mgr, string $version): int
    {
        if ($version === '') {
            $this->write('Usage: siro runtime:remove <version>');
            return 1;
        }

        $result = $mgr->remove($version);
        if ($result['success']) {
            $this->success($result['message']);
            return 0;
        }
        $this->error($result['message']);
        return 1;
    }

    private function current(RuntimeManager $mgr): int
    {
        $active = $mgr->getActive();
        if ($active === '') {
            $this->write('No active Siro Runtime.');
            $this->write('  Run: siro runtime:install 8.2');
            return 0;
        }
        $this->write($active);
        return 0;
    }

    private function path(RuntimeManager $mgr): int
    {
        $this->write($mgr->currentPhpBinary());
        return 0;
    }

    private function help(): int
    {
        $this->write('Siro Runtime Manager');
        $this->write('');
        $this->write('  runtime:install <version>   Download & install Siro Runtime');
        $this->write('  runtime:switch <version>    Switch active runtime');
        $this->write('  runtime:list                List installed runtimes');
        $this->write('  runtime:remove <version>    Remove a runtime');
        $this->write('  runtime:current             Show active version');
        $this->write('  runtime:path                Show path to active PHP binary');
        $this->write('');
        $this->write('Examples:');
        $this->write('  siro runtime:install 8.2');
        $this->write('  siro runtime:switch 8.3');
        $this->write('  siro runtime:list');
        return 0;
    }
}
