<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class FixCommand
{
    use CommandSupport;

    private string $basePath;
    private array $lastStatus = [];
    private int $watchPid = 0;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    public function run(array $args): int
    {
        $dirs = [
            $this->basePath . DIRECTORY_SEPARATOR . 'app',
            $this->basePath . DIRECTORY_SEPARATOR . 'routes',
        ];

        $this->write('');
        $this->write('  ⚡ Siro Fix — watching for changes...');
        $this->write('  ' . str_repeat('-', 40));
        $this->write('  Watching: app/, routes/');
        $this->write('  Auto-replays last API test request on change');
        $this->write('  Press Ctrl+C to stop.');
        $this->write('');

        // Get the last api:test command from history
        $lastTest = $this->getLastApiTest();

        if ($lastTest === null) {
            $this->write('  ⚠ No previous api:test found. Run one first:');
            $this->write('    php siro api:test GET /api/users');
            return 1;
        }

        $this->write('  Last test: ' . $lastTest);
        $this->write('  Watching...');

        $lastMtime = $this->getMaxMtime($dirs);

        while (true) {
            sleep(1);
            $currentMtime = $this->getMaxMtime($dirs);
            if ($currentMtime > $lastMtime) {
                $lastMtime = $currentMtime;
                $this->write('');
                $this->write('  🔄 Code changed → replaying...');
                $output = shell_exec($lastTest . ' 2>&1');
                if ($output !== null) {
                    $lines = explode("\n", $output);
                    $statusLine = '';
                    foreach ($lines as $line) {
                        if (str_contains($line, 'Status:')) {
                            $statusLine = trim($line);
                            if (str_contains($statusLine, '200') || str_contains($statusLine, '201')) {
                                $this->write('  ✅ ' . $statusLine . ' — FIX SUCCESSFUL');
                            } elseif (str_contains($statusLine, '422') || str_contains($statusLine, '400') || str_contains($statusLine, '401')) {
                                $this->write('  ❌ ' . $statusLine . ' — still failing');
                                // Show the error
                                foreach ($lines as $l) {
                                    if (str_contains($l, '❌') || str_contains($l, 'Validation failed') || str_contains($l, 'error')) {
                                        $this->write('     ' . trim($l));
                                    }
                                }
                            } else {
                                $this->write('  ' . $statusLine);
                            }
                            break;
                        }
                    }
                }
                $this->write('  Watching...');
            }
        }
    }

    private function getMaxMtime(array $dirs): int
    {
        $max = 0;
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $mtime = $file->getMTime();
                    if ($mtime > $max) $max = $mtime;
                }
            }
        }
        return $max;
    }

    private function getLastApiTest(): ?string
    {
        $historyFile = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'api-test-history.json';
        if (file_exists($historyFile)) {
            $history = json_decode((string) file_get_contents($historyFile), true);
            if (is_array($history) && $history !== []) {
                $last = end($history);
                if (isset($last['command'])) {
                    return $last['command'];
                }
            }
        }
        return null;
    }
}
