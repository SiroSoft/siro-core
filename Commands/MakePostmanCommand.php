<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\App;

final class MakePostmanCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /**
 * Generate Postman collection v2.1.
 *
 * Creates a Postman collection with endpoints, auto-login
 * pre-request script, body examples from validation rules,
 * and bearer token variable.
 *
 * @package Siro\Core\Commands
 */
    public function run(array $args): int
    {
        $postmanDir = $this->basePath . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'postman';
        if (!is_dir($postmanDir)) {
            mkdir($postmanDir, 0775, true);
        }
        $output = $postmanDir . DIRECTORY_SEPARATOR . 'collection.json';
        $publicOutput = $this->basePath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'postman_collection.json';
        $host = 'localhost:8080';
        $filterTag = null;
        $filterMethod = null;
        $filterPath = null;
        $filterFlow = null;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--output=')) {
                $output = substr($arg, 9);
            } elseif (str_starts_with($arg, '--host=')) {
                $host = substr($arg, 7);
            } elseif (str_starts_with($arg, '--tag=')) {
                $filterTag = substr($arg, 6);
            } elseif (str_starts_with($arg, '--method=')) {
                $filterMethod = strtoupper(substr($arg, 9));
            } elseif (str_starts_with($arg, '--path=')) {
                $filterPath = substr($arg, 7);
            } elseif (str_starts_with($arg, '--flow=')) {
                $filterFlow = strtolower(substr($arg, 7));
            }
        }

        $app = new App($this->basePath);
        $app->boot();
        $app->loadRoutes($this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php');

        $routes = $app->router->getRoutes();

        $items = [];
        $hasAuth = false;
        $authEndpoint = null;
        $registerEndpoint = null;

        foreach ($routes as $route) {
            $method = strtoupper($route['method']);
            $path = $route['path'];
            $handler = $route['handler'];
            $middlewareStr = $route['middleware'];
            $middlewares = $middlewareStr !== '' ? explode(', ', $middlewareStr) : [];
            $hasAuthMiddleware = in_array('auth', $middlewares, true);
            $tag = $this->handlerToTag($handler);

            // Apply filters
            if ($filterTag !== null && strcasecmp($tag, $filterTag) !== 0) {
                continue;
            }
            if ($filterMethod !== null && strcasecmp($method, $filterMethod) !== 0) {
                continue;
            }
            if ($filterPath !== null && !str_starts_with($path, $filterPath)) {
                continue;
            }
            if ($filterFlow === 'auth' && !str_contains($path, '/auth/')) {
                continue;
            }
            if ($filterFlow === 'crud' && str_contains($path, '/auth/')) {
                continue;
            }

            if ($hasAuthMiddleware) {
                $hasAuth = true;
            }

            // Track auth endpoints
            if (str_contains($path, '/auth/login')) {
                $authEndpoint = ['method' => $method, 'path' => $path];
            }
            if (str_contains($path, '/auth/register')) {
                $registerEndpoint = ['method' => $method, 'path' => $path];
            }

            // Build request body example from handler
            $body = $this->buildRequestBody($handler, $method);

            $request = [
                'method' => $method,
                'header' => [
                    ['key' => 'Content-Type', 'value' => 'application/json', 'type' => 'text'],
                    ['key' => 'Accept', 'value' => 'application/json', 'type' => 'text'],
                ],
                'url' => [
                    'raw' => '{{base_url}}' . $path,
                    'host' => ['{{base_url}}'],
                    'path' => explode('/', trim($path, '/')),
                    'variable' => [],
                ],
            ];

            // Parse path params
            preg_match_all('/\{(\w+)\}/', $path, $pathParams);
            foreach ($pathParams[1] as $param) {
                $request['url']['variable'][] = [
                    'key' => $param,
                    'value' => '1',
                    'description' => $param,
                ];
                // Replace {param} with :param in raw URL for Postman
                $request['url']['raw'] = '{{base_url}}' . preg_replace('/\{(\w+)\}/', ':$$1', $path);
            }

            if ($body !== null) {
                $request['body'] = [
                    'mode' => 'raw',
                    'raw' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                    'options' => ['raw' => ['language' => 'json']],
                ];
            }

            $tag = $this->handlerToTag($handler);

            $items[] = [
                'name' => $method . ' ' . $path,
                'request' => $request,
                'response' => [],
            ];
        }

        $collection = [
            'info' => [
                'name' => 'Siro API',
                'description' => 'Auto-generated Postman collection for Siro API',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'variable' => [
                ['key' => 'base_url', 'value' => 'http://' . $host, 'type' => 'string'],
                ['key' => 'token', 'value' => '', 'type' => 'string'],
            ],
            'item' => $items,
        ];

        // Add auth pre-request script if auth endpoints exist
        if ($hasAuth) {
            $loginPath = $authEndpoint['path'] ?? '/api/auth/login';
            $loginMethod = $authEndpoint['method'] ?? 'POST';

            $collection['auth'] = [
                'type' => 'bearer',
                'bearer' => [
                    ['key' => 'token', 'value' => '{{token}}', 'type' => 'string'],
                ],
            ];

            // Add pre-request script to auto-login
            $collection['event'] = [
                [
                    'listen' => 'prerequest',
                    'script' => [
                        'type' => 'text/javascript',
                        'exec' => [
                            '// Auto-login to get bearer token',
                            'if (!pm.variables.get("token") && pm.request.url.toString().includes("/auth/")) {',
                            '    return; // Skip auth for auth endpoints',
                            '}',
                            '',
                            'const loginUrl = pm.variables.get("base_url") + "' . $loginPath . '";',
                            'const loginData = {',
                            '    email: "admin@example.com",',
                            '    password: "password",',
                            '};',
                            '',
                            'pm.sendRequest({',
                            '    url: loginUrl,',
                            '    method: "' . $loginMethod . '",',
                            '    header: { "Content-Type": "application/json" },',
                            '    body: { mode: "raw", raw: JSON.stringify(loginData) }',
                            '}, function (err, res) {',
                            '    if (!err) {',
                            '        const json = res.json();',
                            '        if (json.data && json.data.token) {',
                            '            pm.variables.set("token", json.data.token);',
                            '        }',
                            '    }',
                            '});',
                        ],
                    ],
                ],
            ];
        }

        $json = json_encode($collection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        file_put_contents($output, $json);

        $publicOutput = $this->basePath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'postman_collection.json';
        copy($output, $publicOutput);

        $this->write('Postman collection generated:');
        $this->write("  docs/postman/collection.json");
        $this->write("  public/postman_collection.json");
        if ($hasAuth) {
            $this->write('  - Auth: Bearer token auto-fetched via pre-request script');
            $this->write('  - Auth endpoint: ' . ($authEndpoint['method'] ?? 'POST') . ' ' . ($authEndpoint['path'] ?? '/api/auth/login'));
        }

        return 0;
    }

    private function handlerToTag(string $handler): string
    {
        if (str_contains($handler, '@')) {
            $parts = explode('@', $handler);
            $controller = basename(str_replace('\\', '/', $parts[0]));
            return str_replace('Controller', '', $controller);
        }

        return 'General';
    }

    private function buildRequestBody(string $handler, string $method): ?array
    {
        if (!in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
            return null;
        }

        // Try to extract validation rules from controller
        $controllerFile = $this->findControllerFile($handler);
        if ($controllerFile === null) {
            return null;
        }

        $source = file_get_contents($controllerFile);
        if ($source === false) {
            return null;
        }

        if (str_contains($handler, '@')) {
            $parts = explode('@', $handler);
            $methodName = $parts[1] ?? '';
        } else {
            return null;
        }

        $pattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*:\s*Response\s*\{.*?\$request->validate\(\s*\[(.*?)\]\s*\)/s';
        preg_match($pattern, $source, $matches);

        if (!isset($matches[1])) {
            return ['example' => 'field', 'value' => 'value'];
        }

        $rulesBlock = $matches[1];
        $example = [];

        preg_match_all("/'(\w+)'\s*=>\s*'([^']+)'/", $rulesBlock, $ruleMatches);

        foreach ($ruleMatches[1] as $i => $field) {
            $ruleStr = $ruleMatches[2][$i];
            $rules = explode('|', $ruleStr);

            $example[$field] = $this->ruleToExample($field, $rules);
        }

        return $example !== [] ? $example : ['example' => 'field', 'value' => 'value'];
    }

    private function findControllerFile(string $handler): ?string
    {
        if (!str_contains($handler, '@')) {
            return null;
        }

        $parts = explode('@', $handler);
        $controllerClass = str_replace('\\', DIRECTORY_SEPARATOR, $parts[0]);
        $path = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . basename($controllerClass) . '.php';

        if (is_file($path)) {
            return $path;
        }

        return null;
    }

    private function ruleToExample(string $field, array $rules): mixed
    {
        if (in_array('email', $rules, true)) {
            return $field === 'email' ? 'user@example.com' : 'email@example.com';
        }
        if (in_array('integer', $rules, true) || in_array('numeric', $rules, true)) {
            return 1;
        }
        if (in_array('confirmed', $rules, true)) {
            return 'password123';
        }
        if (str_contains($field, 'name')) {
            return 'John Doe';
        }
        if (str_contains($field, 'phone')) {
            return '0123456789';
        }
        if (str_contains($field, 'password')) {
            return 'secret123';
        }

        return 'string';
    }
}
