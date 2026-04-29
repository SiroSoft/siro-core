<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\App;

final class MakeOpenApiCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $output = $this->basePath . DIRECTORY_SEPARATOR . 'openapi.json';
        $title = 'Siro API';
        $version = '1.0.0';
        $host = 'localhost:8080';
        $schemes = ['http'];
        $filterTag = null;
        $filterMethod = null;
        $filterPath = null;
        $filterFlow = null;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--output=')) {
                $output = substr($arg, 9);
            } elseif (str_starts_with($arg, '--title=')) {
                $title = substr($arg, 8);
            } elseif (str_starts_with($arg, '--version=')) {
                $version = substr($arg, 10);
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

        $paths = [];
        $hasAuth = false;

        foreach ($routes as $route) {
            $method = strtolower($route['method']);
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

            $operationId = $this->handlerToOperationId($handler, $method);

            // Parse path params
            $parameters = [];
            preg_match_all('/\{(\w+)\}/', $path, $pathParams);
            foreach ($pathParams[1] as $param) {
                $parameters[] = [
                    'name' => $param,
                    'in' => 'path',
                    'required' => true,
                    'schema' => ['type' => 'integer'],
                    'description' => $param,
                ];
            }

            // Parse query params from handler name hints
            if ($method === 'get' && in_array($operationId, ['index', 'list'], true)) {
                $parameters[] = [
                    'name' => 'page',
                    'in' => 'query',
                    'required' => false,
                    'schema' => ['type' => 'integer', 'default' => 1],
                ];
                $parameters[] = [
                    'name' => 'per_page',
                    'in' => 'query',
                    'required' => false,
                    'schema' => ['type' => 'integer', 'default' => 20],
                ];
            }

            $responses = [
                '200' => ['description' => 'Successful operation'],
                '400' => ['description' => 'Bad request'],
                '422' => ['description' => 'Validation error'],
            ];

            if ($hasAuthMiddleware) {
                $responses['401'] = ['description' => 'Unauthorized'];
            }

            // Extract validation rules from controller
            $requestSchema = $this->extractValidationRules($handler);

            $pathItem = [];
            $pathItem[$method] = [
                'summary' => $this->handlerToSummary($handler),
                'operationId' => $operationId,
                'parameters' => $parameters,
                'responses' => $responses,
                'tags' => [$this->handlerToTag($handler)],
            ];

            if ($hasAuthMiddleware) {
                $pathItem[$method]['security'] = [['bearerAuth' => []]];
            }

            if ($requestSchema !== null && in_array($method, ['post', 'put', 'patch'], true)) {
                $pathItem[$method]['requestBody'] = [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => $requestSchema,
                        ],
                    ],
                ];
            }

            $paths[$path][$method] = $pathItem[$method];
        }

        $openapi = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => $title,
                'version' => $version,
                'description' => 'Auto-generated OpenAPI spec for Siro API',
            ],
            'servers' => [
                ['url' => $schemes[0] . '://' . $host, 'description' => 'Local server'],
            ],
            'paths' => $paths,
            'components' => [
                'schemas' => [
                    'Error' => [
                        'type' => 'object',
                        'properties' => [
                            'success' => ['type' => 'boolean', 'example' => false],
                            'message' => ['type' => 'string'],
                            'data' => ['type' => 'null'],
                            'meta' => [
                                'type' => 'object',
                                'properties' => [
                                    'errors' => ['type' => 'object'],
                                ],
                            ],
                        ],
                    ],
                    'Success' => [
                        'type' => 'object',
                        'properties' => [
                            'success' => ['type' => 'boolean', 'example' => true],
                            'message' => ['type' => 'string'],
                            'data' => ['type' => 'object', 'nullable' => true],
                            'meta' => ['type' => 'object'],
                        ],
                    ],
                ],
            ],
        ];

        if ($hasAuth) {
            $openapi['components']['securitySchemes'] = [
                'bearerAuth' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'JWT',
                ],
            ];
        }

        $json = json_encode($openapi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        file_put_contents($output, $json);

        $this->write('OpenAPI spec generated: ' . $output);

        return 0;
    }

    private function handlerToOperationId(string $handler, string $method): string
    {
        if (str_contains($handler, '@')) {
            $parts = explode('@', $handler);
            $controller = basename(str_replace('\\', '/', $parts[0]));
            $action = $parts[1] ?? 'index';
            $controller = str_replace('Controller', '', $controller);
            return lcfirst($controller) . ucfirst($action);
        }

        return $method . 'Handler';
    }

    private function handlerToSummary(string $handler): string
    {
        if (str_contains($handler, '@')) {
            $parts = explode('@', $handler);
            $action = $parts[1] ?? '';
            return ucfirst(str_replace(['_', '-'], ' ', $action));
        }

        return 'API endpoint';
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

    private function extractValidationRules(string $handler): ?array
    {
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

        // Find the method and extract validate() calls
        $pattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*:\s*Response\s*\{.*?\$request->validate\(\s*\[(.*?)\]\s*\)/s';
        preg_match($pattern, $source, $matches);

        if (!isset($matches[1])) {
            return null;
        }

        $rulesBlock = $matches[1];
        $properties = [];
        $required = [];

        // Parse individual rule lines: 'field' => 'required|email|max:255',
        preg_match_all("/'(\w+)'\s*=>\s*'([^']+)'/", $rulesBlock, $ruleMatches);

        foreach ($ruleMatches[1] as $i => $field) {
            $ruleStr = $ruleMatches[2][$i];
            $rules = explode('|', $ruleStr);

            $property = $this->ruleToSchemaProperty($field, $rules);

            if (in_array('required', $rules, true)) {
                $required[] = $field;
            }

            $properties[$field] = $property;
        }

        if ($properties === []) {
            return null;
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        // Build example from properties
        $example = [];
        foreach ($properties as $field => $prop) {
            $example[$field] = $prop['example'] ?? ($prop['type'] === 'integer' ? 1 : 'string');
        }
        $schema['example'] = $example;

        return $schema;
    }

    private function findControllerFile(string $handler): ?string
    {
        if (!str_contains($handler, '@')) {
            return null;
        }

        $parts = explode('@', $handler);
        $controllerClass = $parts[0];
        $controllerClass = str_replace('\\', DIRECTORY_SEPARATOR, $controllerClass);

        // Try app/Controllers/
        $path = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . basename($controllerClass) . '.php';

        if (is_file($path)) {
            return $path;
        }

        return null;
    }

    private function ruleToSchemaProperty(string $field, array $rules): array
    {
        $type = 'string';
        $format = null;
        $example = 'string';

        if (in_array('integer', $rules, true) || in_array('numeric', $rules, true)) {
            $type = 'integer';
            $example = 1;
        }

        if (in_array('email', $rules, true)) {
            $format = 'email';
            $example = 'user@example.com';
        }

        if (in_array('confirmed', $rules, true)) {
            $example = 'password123';
        }

        // Extract min/max
        $min = null;
        $max = null;
        foreach ($rules as $rule) {
            if (str_starts_with($rule, 'min:')) {
                $min = (int) substr($rule, 4);
            }
            if (str_starts_with($rule, 'max:')) {
                $max = (int) substr($rule, 4);
            }
        }

        $property = [
            'type' => $type,
            'example' => $example,
            'description' => ucfirst(str_replace('_', ' ', $field)),
        ];

        if ($format !== null) {
            $property['format'] = $format;
        }
        if ($min !== null) {
            $property['minLength'] = $min;
        }
        if ($max !== null) {
            $property['maxLength'] = $max;
        }

        return $property;
    }
}
