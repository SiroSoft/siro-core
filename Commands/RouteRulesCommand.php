<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class RouteRulesCommand implements \Siro\Core\Commands\CommandInterface
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
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
        $groupPrefix = '';

        foreach ($lines as $num => $line) {
            $lineNum = $num + 1;

            // Detect route registration
            if (preg_match('/->(get|post|put|patch|delete|options)\([\'"]([^\'"]+)/i', $line, $m)) {
                $path = $m[2];
                // Routes inside $router->group('/api', ...) are written
                // relative ('/auth/register') but served with the group
                // prefix ('/api/auth/register').
                if ($groupPrefix !== '' && !str_starts_with($path, $groupPrefix)) {
                    $path = rtrim($groupPrefix, '/') . '/' . ltrim($path, '/');
                }
                $currentRoute = strtoupper($m[1]) . ' ' . $path;
            }

            // Detect group path — routes below inherit this prefix.
            // (Single-level groups only; a nested group replaces it.)
            if (preg_match('/->group\([\'"]([^\'"]+)/i', $line, $m)) {
                if (str_starts_with($m[1], '/')) {
                    $groupPrefix = $m[1];
                }
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
        $seenOpen = false;
        $methodCode = '';

        foreach ($lines as $line) {
            if (!$inMethod) {
                if (preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $line)) {
                    $inMethod = true;
                } else {
                    continue;
                }
            }

            // Count braces on EVERY line including the signature line.
            // The old code started at depth 1 on the signature line and
            // skipped counting it — but controllers put the opening brace
            // on the NEXT line, so depth went 1 -> 2 -> 1 and never
            // reached 0: extraction swallowed the rest of the file and
            // merged every later method's validate() rules into this one.
            $methodCode .= $line . "\n";
            $braceDepth += substr_count($line, '{') - substr_count($line, '}');
            if (str_contains($line, '{')) {
                $seenOpen = true;
            }

            if ($seenOpen && $braceDepth <= 0) {
                break;
            }
        }

        // A method with no opening brace (abstract/interface, or a
        // signature the scan never completed) has no body to extract
        // from — return nothing instead of leaking later code.
        if (!$seenOpen) {
            return [];
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
