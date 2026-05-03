<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class RouteRulesCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    public function run(array $args): int
    {
        $routesFile = $this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php';

        if (!is_file($routesFile)) {
            $this->write('No routes/api.php found.');
            return 1;
        }

        // Scan routes file for ->validate([...]) calls
        $content = (string) file_get_contents($routesFile);
        $lines = file($routesFile) ?: [];

        $this->write("Route Validation Rules");
        $this->write(str_repeat('-', 60));
        $this->write('');

        $found = false;
        $currentRoute = '';

        foreach ($lines as $num => $line) {
            $lineNum = $num + 1;

            // Detect route registration
            if (preg_match('/->(get|post|put|patch|delete|options)\([\'"]([^\'"]+)/i', $line, $m)) {
                $currentRoute = strtoupper($m[1]) . ' ' . $m[2];
            }

            // Detect group path
            if (preg_match('/->group\([\'"]([^\'"]+)/i', $line, $m)) {
                $currentRoute = 'GROUP: ' . $m[1];
            }

            // Detect validate() calls
            if (preg_match('/validate\(\[([^\]]*)\]\)/', $line, $m)) {
                $rules = $m[1];
                $this->write("  \033[1;33m{$currentRoute}\033[0m");
                $this->write("    Rules: {$rules}");
                $this->write('');
                $found = true;
            }

            // Also check controller files for validate
            if (preg_match('/\[([^,]+)Controller::class,\s*\'(\w+)/', $line, $m)) {
                $controller = $m[1];
                $method = $m[2];
                $ctrlFile = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR
                    . 'Controllers' . DIRECTORY_SEPARATOR . $controller . 'Controller.php';

                if (is_file($ctrlFile)) {
                    $rules = $this->extractValidationRules($ctrlFile, $method);
                    if ($rules !== []) {
                        $this->write("  \033[1;33m{$currentRoute}\033[0m");
                        foreach ($rules as $field => $rule) {
                            $this->write("    {$field}: {$rule}");
                        }
                        $this->write('');
                        $found = true;
                    }
                }
            }
        }

        if (!$found) {
            $this->write('No validation rules found in routes or controllers.');
        }

        return 0;
    }

    /** @return array<string, string> */
    private function extractValidationRules(string $file, string $method): array
    {
        $content = (string) file_get_contents($file);
        $lines = explode("\n", $content);

        $inMethod = false;
        $braceDepth = 0;
        $methodCode = '';

        foreach ($lines as $line) {
            if (!$inMethod) {
                if (preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $line)) {
                    $inMethod = true;
                    $braceDepth = 1;
                    $methodCode = $line . "\n";
                }
                continue;
            }

            $methodCode .= $line . "\n";
            $braceDepth += substr_count($line, '{') - substr_count($line, '}');

            if ($braceDepth <= 0) {
                break;
            }
        }

        // Extract validate() calls
        $rules = [];
        if (preg_match_all('/->validate\(\[\s*([\s\S]*?)\s*\]\)/', $methodCode, $matches)) {
            foreach ($matches[1] as $block) {
                if (preg_match_all('/[\'"]([^\'"]+)[\']\s*=>\s*[\'"]([^\'"]+)[\'"]/', $block, $pairs)) {
                    foreach ($pairs[1] as $i => $field) {
                        $rules[$field] = $pairs[2][$i];
                    }
                }
            }
        }

        return $rules;
    }
}
