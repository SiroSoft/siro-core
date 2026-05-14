<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\App;

final class MakeOpenApiCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    private string $basePath;
    private string $host = 'localhost:8080';
    private string $title = 'Siro API';
    private string $apiVersion = '0.15.0';
    private string $outputFile = '';
    private bool $withSwagger = false;
    private ?string $tagFilter = null;
    private ?string $methodFilter = null;
    private ?string $pathFilter = null;
    private ?string $flowFilter = null;

    /** @var array<string, array<string, mixed>> */
    private array $schemas = [];
    /** @var array<string, string> */
    private array $tags = [];

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $this->parseArgs($args);

        // Fix 1: Production safety - disabled by default
        $envFile = $this->basePath . '/.env';
        $env = strtolower((string) getenv('APP_ENV'));
        if ($env === '' && file_exists($envFile)) {
            $content = file_get_contents($envFile);
            if (preg_match('/^APP_ENV\s*=\s*(\w+)/m', (string) $content, $m)) {
                $env = strtolower($m[1]);
            }
        }

        $openApiEnabled = (bool) getenv('SIRO_OPENAPI_ENABLED');
        if (!$openApiEnabled && file_exists($envFile)) {
            $content = (string) file_get_contents($envFile);
            if (preg_match('/^SIRO_OPENAPI_ENABLED\s*=\s*(true|1)/mi', $content)) {
                $openApiEnabled = true;
            }
        }
        $openApiEnabled = $openApiEnabled ?: ($env !== 'production' && $env !== 'prod');
        if (!$openApiEnabled) {
            $this->write('  ⚠ OpenAPI spec generation is disabled in production.');
            $this->write('  Set SIRO_OPENAPI_ENABLED=true in .env to enable.');
            $this->write('  Or use with explicit --force flag if you know what you are doing.');
            return 1;
        }

        $routes = $this->loadRoutes();
        $this->buildCoreSchemas();

        $paths = [];
        foreach ($routes as $route) {
            if (!$this->passesFilter($route)) continue;
            $this->addRouteToPaths($paths, $route);
        }

        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => $this->title,
                'version' => $this->apiVersion,
                'description' => 'Siro API Framework',
                'contact' => ['name' => 'SiroSoft Team', 'url' => 'https://github.com/SiroSoft/SiroPHP'],
                'license' => ['name' => 'MIT', 'url' => 'https://opensource.org/licenses/MIT'],
            ],
            'servers' => [
                ['url' => 'http://' . $this->host, 'description' => 'Development'],
            ],
            'tags' => $this->buildTagsList(),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT',
                        'description' => 'JWT token from /api/auth/login or /api/auth/register',
                    ],
                ],
                'schemas' => $this->schemas,
            ],
        ];

        // Only require auth on routes that need it (heuristic: CRUD methods on non-auth paths)
        $hasProtectedRoutes = false;
        foreach ($routes as $r) {
            if (!str_contains($r['path'] ?? '', '/auth/') && in_array($r['method'] ?? '', ['POST', 'PUT', 'DELETE'], true)) {
                $hasProtectedRoutes = true;
                break;
            }
        }
        if ($hasProtectedRoutes) {
            $spec['security'] = [['bearerAuth' => []]];
        }

        $json = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $outputDir = dirname($this->outputFile !== '' ? $this->outputFile : ($this->basePath . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'openapi'));
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0775, true);
        }
        file_put_contents($outputDir . DIRECTORY_SEPARATOR . 'openapi.json', $json);
        $this->write('Generated: ' . $outputDir . DIRECTORY_SEPARATOR . 'openapi.json (' . count($paths) . ' endpoints)');

        if ($this->withSwagger) {
            $this->generateSwaggerUi($outputDir);
            $this->copyToPublic($outputDir);
        }
        return 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadRoutes(): array
    {
        $routes = [];
        try {
            $app = new App($this->basePath);
            if (defined('SIRO_BASE_PATH') || defined('BASE_PATH')) {
                $app->boot();
            }
            $routesFile = $this->basePath . '/routes/api.php';
            if (file_exists($routesFile)) {
                $app->loadRoutes($routesFile);
            }
            $router = $app->router();
            $ref = new \ReflectionClass($router);
            if ($ref->hasMethod('getRoutes')) {
                $registered = $router->getRoutes();
            } elseif ($ref->hasProperty('staticRoutes')) {
                $prop = $ref->getProperty('staticRoutes');
                $prop->setAccessible(true);
                $registered = $prop->getValue($router);
            } else {
                return $this->buildFallbackRoutes();
            }

            foreach ((array) $registered as $r) {
                if (is_array($r) && isset($r['method'], $r['path'])) {
                    $r['handler'] = $this->resolveHandler($r['handler'] ?? '');
                    $r['middleware'] = $r['middleware'] ?? [];
                    $routes[] = $r;
                }
            }
        } catch (\Throwable $e) {
            $this->write('⚠ Warning: Could not read routes from app (' . $e->getMessage() . ')');
            return $this->buildFallbackRoutes();
        }

        return $routes !== [] ? $routes : $this->buildFallbackRoutes();
    }

    private function resolveHandler(mixed $handler): string
    {
        if (is_string($handler)) return $handler;
        if (is_array($handler) && count($handler) >= 2) {
            $first = $handler[0];
            if (is_object($first)) {
                return get_class($first) . '@' . $handler[1];
            }
            return (is_string($first) ? $first : $first::class) . '@' . $handler[1];
        }
        if ($handler instanceof \Closure) return 'Closure';
        return 'unknown';
    }

    /**
     * @param array<mixed> $paths
     * @param array<string, mixed> $route
     */
    private function addRouteToPaths(array &$paths, array $route): void
    {
        $method = strtolower($route['method'] ?? 'get');
        $path = $route['path'] ?? '/';
        $handler = $route['handler'] ?? '';
        $middleware = $route['middleware'] ?? [];

        // Derive tag from controller class or path
        $tag = $this->deriveTag($handler, $path);

        // Normalize middleware to array
        $middlewareArr = is_array($middleware) ? $middleware : (is_string($middleware) ? [$middleware] : []);
        $hasAuth = $this->hasAuthMiddleware($middlewareArr);

        // Build operation
        $operation = [
            'tags' => [$tag],
            'summary' => $this->deriveSummary($method, $path),
            'security' => $hasAuth ? [['bearerAuth' => []]] : [],
        ];

        // Extract path params like {id}
        $parameters = $this->extractPathParams($path);
        // Add query params for GET list endpoints
        if ($method === 'get' && !str_contains($path, '{')) {
            $parameters[] = ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 1]];
            $parameters[] = ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 20]];
        }
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        // Extract request body for POST/PUT/PATCH
        if (in_array($method, ['post', 'put', 'patch'])) {
            $schemaName = $this->buildRequestSchema($handler, $method);
            if ($schemaName !== null) {
                $operation['requestBody'] = [
                    'required' => true,
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $schemaName]]],
                ];
            }
        }

        // Build response schemas
        $responseSchema = $this->buildResponseSchema($handler, $method, $path);
        $operation['responses'] = $this->buildResponses($responseSchema, $method, $path);

        $paths[$path][$method] = $operation;
    }

    private function deriveTag(string $handler, string $path): string
    {
        if (str_contains($path, '/auth/')) return 'Auth';
        if (str_contains($path, '/health') || $path === '/') return 'System';

        // Extract from controller class name
        if (preg_match('/\\\\(\w+)Controller/', $handler, $m)) {
            $tag = $m[1];
            // Smart pluralization
            $last = substr($tag, -1);
            $tag = match (true) {
                str_ends_with($tag, 'y') => substr($tag, 0, -1) . 'ies',
                $last === 's' || $last === 'x' || $last === 'z' || str_ends_with($tag, 'sh') || str_ends_with($tag, 'ch') => $tag . 'es',
                default => $tag . 's',
            };
            $this->tags[$tag] = $tag;
            return $tag;
        }

        // Extract from path segment
        $parts = array_values(array_filter(explode('/', $path)));
        foreach ($parts as $p) {
            if ($p !== 'api' && !str_starts_with($p, '{')) {
                $tag = ucfirst(rtrim($p, 's') . 's');
                $this->tags[$tag] = $tag;
                return $tag;
            }
        }
        return 'API';
    }

    private function deriveSummary(string $method, string $path): string
    {
        $action = match ($method) {
            'get' => str_contains($path, '{') ? 'Get' : 'List',
            'post' => 'Create',
            'put' => 'Update',
            'patch' => 'Partial update',
            'delete' => 'Delete',
            default => strtoupper($method),
        };
        $parts = array_values(array_filter(explode('/', $path)));
        $resource = end($parts);
        if ($resource !== false) {
            $resource = str_replace(['{', '}'], '', $resource);
            // Check if next-to-last part is the real resource (for /api/resource/{id})
            $prev = prev($parts);
            if ($prev !== false && $prev !== 'api' && str_contains($path, '{')) {
                $resource = $prev;
            }
        }
        return $action . ' ' . ($resource !== false ? $resource : 'resource');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractPathParams(string $path): array
    {
        $params = [];
        if (preg_match_all('/\{(\w+)\}/', $path, $matches)) {
            foreach ($matches[1] as $name) {
                $params[] = [
                    'name' => $name,
                    'in' => 'path',
                    'required' => true,
                    'schema' => ['type' => 'integer'],
                ];
            }
        }
        return $params;
    }

    /**
     * @param array<int, mixed> $middleware
     */
    private function hasAuthMiddleware(array $middleware): bool
    {
        foreach ($middleware as $m) {
            $mStr = is_string($m) ? $m : (is_array($m) ? implode(',', $m) : '');
            // Fix 4: Normalize auth detection - handle auth:api, auth:sanctum, custom auth middleware
            if (preg_match('/\bauth\b/i', $mStr)) return true;
            if (str_contains($mStr, 'AuthMiddleware') || str_contains($mStr, 'Auth:')) return true;
        }
        return false;
    }

    private function buildRequestSchema(string $handler, string $method): ?string
    {
        $schemaName = $this->schemaName($handler, $method, 'Request');
        if (isset($this->schemas[$schemaName])) return $schemaName;

        $rules = $this->extractValidationRules($handler);
        if ($rules === []) return null;

        $properties = [];
        $required = [];
        foreach ($rules as $field => $fieldRules) {
            $properties[$field] = $this->ruleToProperty($field, $fieldRules);
            if ($this->isRequired($fieldRules)) {
                $required[] = $field;
            }
        }

        $this->schemas[$schemaName] = [
            'type' => 'object',
            'properties' => $properties,
        ];
        if ($required !== []) {
            $this->schemas[$schemaName]['required'] = $required;
        }
        return $schemaName;
    }

    private function buildResponseSchema(string $handler, string $method, string $path): string
    {
        // Singular for show/update/delete, plural for index
        $isList = $method === 'get' && !str_contains($path, '{');
        $suffix = $isList ? 'ListResponse' : 'Response';
        $schemaName = $this->schemaName($handler, $method, $suffix);

        if (isset($this->schemas[$schemaName])) return $schemaName;

        // Try to extract data schema from resource/model
        $dataSchema = $this->extractDataSchema($handler);

        if ($isList) {
            $itemSchema = $dataSchema ?? ['type' => 'object'];
            $this->schemas[$schemaName] = [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'message' => ['type' => 'string'],
                    'data' => ['type' => 'array', 'items' => $itemSchema],
                    'meta' => ['$ref' => '#/components/schemas/PaginationMeta'],
                ],
            ];
        } else {
            $this->schemas[$schemaName] = [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'message' => ['type' => 'string'],
                    'data' => $dataSchema ?? ['type' => 'object'],
                ],
            ];
        }
        return $schemaName;
    }

    /** @var array<int, string> Internal model fields that should never appear in API docs */
    private array $internalModelFields = ['password', 'token_version', 'verification_token', 'password_reset_token', 'password_reset_expires_at', 'email_verified_at', 'deleted_at', 'remember_token'];

    /**
     * @return array<string, mixed>|null
     */
    private function extractDataSchema(string $handler): ?array
    {
        if (!preg_match('/(\w+)Controller@(\w+)/', $handler, $m)) return null;

        // Fix 3: Prefer Resource::toArray() over Model
        $resourceName = $m[1] . 'Resource';
        $resourceFile = $this->basePath . '/app/Resources/' . $resourceName . '.php';
        if (file_exists($resourceFile)) {
            return $this->parseResourceFile($resourceFile);
        }

        // Fallback to Model only if Resource doesn't exist — strip internal fields
        $modelFile = $this->basePath . '/app/Models/' . $m[1] . '.php';
        if (file_exists($modelFile)) {
            $schema = $this->parseModelFile($modelFile);
            if (isset($schema['properties'])) {
                foreach ($schema['properties'] as $field => $prop) {
                    if (in_array(strtolower($field), $this->internalModelFields, true)) {
                        unset($schema['properties'][$field]);
                    }
                }
            }
            return $schema;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseResourceFile(string $file): array
    {
        $content = (string) file_get_contents($file);
        $properties = [];

        // Extract return array keys from toArray()
        if (preg_match('/function\s+toArray\s*\(\)\s*:\s*array\s*\{([^}]+)\}/s', $content, $m)) {
            $body = $m[1];
            preg_match_all('/\'(\w+)\'\s*=>/', $body, $keys);
            foreach ($keys[1] as $key) {
                $properties[$key] = $this->inferPropertyType($key, $content);
            }
        }

        return ['type' => 'object', 'properties' => $properties];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseModelFile(string $file): array
    {
        $content = (string) file_get_contents($file);
        $properties = [];

        // Extract $casts property
        if (preg_match('/\$casts\s*=\s*\[([^\]]+)\]/s', $content, $m)) {
            preg_match_all('/\'(\w+)\'\s*=>\s*\'(\w+)\'/', $m[1], $casts);
            foreach ($casts[1] as $i => $field) {
                $properties[$field] = ['type' => $this->castToType($casts[2][$i] ?? 'string')];
            }
        }

        // Extract $fillable for required fields
        if (preg_match('/\$fillable\s*=\s*\[([^\]]+)\]/s', $content, $m)) {
            preg_match_all('/\'(\w+)\'/', $m[1], $fields);
        }

        // Add common fields
        if (!isset($properties['id'])) {
            $properties['id'] = ['type' => 'integer'];
        }
        if (!isset($properties['created_at'])) {
            $properties['created_at'] = ['type' => 'string', 'format' => 'date-time'];
        }

        return ['type' => 'object', 'properties' => $properties];
    }

    /** @var array<int, string> Sensitive fields that should not be exposed in OpenAPI */
    private array $sensitiveFields = ['is_admin', 'role', 'permissions', 'balance', 'credit', 'token_version', 'verification_token', 'password_reset_token', 'password_reset_expires_at', 'email_verified_at', 'deleted_at'];

    /** @var array<int, string> Internal validation rules that should not be exposed */
    private array $internalRules = ['unique:', 'exists:', 'confirmed', 'required_if:', 'prohibited_if:'];

    /**
     * @return array<string, mixed>
     */
    private function extractValidationRules(string $handler): array
    {
        if (!preg_match('/(\w+)Controller@(\w+)/', $handler, $m)) return [];

        $controllerFile = $this->basePath . '/app/Controllers/' . $m[1] . 'Controller.php';
        if (!file_exists($controllerFile)) return [];

        $content = (string) file_get_contents($controllerFile);

        $method = $m[2];
        if (!preg_match('/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)\s*(?::\s*[^{]+)?\{/', $content, $methodMatch, PREG_OFFSET_CAPTURE)) return [];
        $startPos = $methodMatch[0][1];
        $body = $this->extractMethodBody($content, $startPos);
        if ($body === '') return [];

        $rules = [];

        // Pattern 1: validate() with nested arrays (e.g., 'items' => [1,2])
        if (preg_match('/\->validate\s*\(\s*\[([^\]]*\[[^\]]*\]\s*,*\s*)+\s*\)/s', $body, $vMatch)) {
            preg_match_all('/\'(\w+)\'\s*=>\s*\'([^\']+)\'/', $vMatch[0], $pairs);
            foreach ($pairs[1] as $i => $field) {
                $rules[$field] = explode('|', $pairs[2][$i]);
            }
        }

        // Pattern 2: validate() with simple flat array (most common)
        if ($rules === [] && preg_match('/\->validate\s*\((\s*\[[^]]+\])\s*\)/s', $body, $vMatch)) {
            preg_match_all('/\'(\w+)\'\s*=>\s*\'([^\']+)\'/', $vMatch[1], $pairs);
            foreach ($pairs[1] as $i => $field) {
                $rules[$field] = explode('|', $pairs[2][$i]);
            }
        }

        // Pattern 3: Validator::make() with flat array
        if ($rules === [] && preg_match('/Validator::make\s*\([^,]+,\s*\[([^\]]+)\]/s', $body, $vMatch)) {
            preg_match_all('/\'(\w+)\'\s*=>\s*\'([^\']+)\'/', $vMatch[1], $pairs);
            foreach ($pairs[1] as $i => $field) {
                $rules[$field] = explode('|', $pairs[2][$i]);
            }
        }

        // Fix 2: Remove sensitive fields and internal rules from OpenAPI exposure
        foreach ($rules as $field => $fieldRules) {
            if (in_array(strtolower($field), $this->sensitiveFields, true)) {
                unset($rules[$field]);
                continue;
            }
            // Strip internal-only validation rules (don't expose logic through OpenAPI)
            $rules[$field] = array_values(array_filter($fieldRules, function ($rule) {
                foreach ($this->internalRules as $internal) {
                    if (str_starts_with($rule, $internal)) return false;
                }
                return true;
            }));
        }

        return $rules;
    }

    private function extractMethodBody(string $content, int $startPos): string
    {
        $depth = 0;
        $entered = false;
        $len = strlen($content);
        $body = '';
        for ($i = $startPos; $i < $len; $i++) {
            $ch = $content[$i];
            $body .= $ch;
            if ($ch === '{') { $depth++; $entered = true; }
            if ($ch === '}') $depth--;
            if ($entered && $depth === 0) break;
        }
        return $body;
    }

    /**
     * @param array<int, string> $rules
     * @return array<string, mixed>
     */
    private function ruleToProperty(string $field, array $rules): array
    {
        $type = 'string';
        foreach ($rules as $rule) {
            $ruleName = explode(':', $rule)[0];
            $type = match ($ruleName) {
                'integer', 'int' => 'integer',
                'numeric', 'float', 'double', 'price' => 'number',
                'boolean', 'bool' => 'boolean',
                'email', 'url' => 'string',
                'array' => 'array',
                'json' => 'object',
                default => $type,
            };
        }

        $prop = ['type' => $type];

        // Extract min/max for strings
        if ($type === 'string') {
            foreach ($rules as $rule) {
                if (str_starts_with($rule, 'min:')) $prop['minLength'] = (int) substr($rule, 4);
                if (str_starts_with($rule, 'max:')) $prop['maxLength'] = (int) substr($rule, 4);
            }
        }
        if ($type === 'number' || $type === 'integer') {
            foreach ($rules as $rule) {
                if (str_starts_with($rule, 'min:')) $prop['minimum'] = (float) substr($rule, 4);
                if (str_starts_with($rule, 'max:')) $prop['maximum'] = (float) substr($rule, 4);
            }
        }
        if (in_array('email', $rules, true)) $prop['format'] = 'email';

        // Example value
        $prop['example'] = match ($type) {
            'integer' => 1,
            'number' => 0.0,
            'boolean' => true,
            default => 'string',
        };

        return $prop;
    }

    /**
     * @param array<string, mixed> $rules
     */
    private function isRequired(array $rules): bool
    {
        return in_array('required', $rules, true);
    }

    private function schemaName(string $handler, string $method, string $suffix): string
    {
        // Extract base name from handler
        if (preg_match('/(\w+)Controller@(\w+)/', $handler, $m)) {
            $action = match ($method) {
                'post' => 'Create',
                'put', 'patch' => 'Update',
                'delete' => 'Delete',
                default => ucfirst($m[2]),
            };
            return $m[1] . $action . $suffix;
        }
        return ucfirst($method) . 'Request';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildResponses(?string $responseSchema, string $method, string $path): array
    {
        $successCode = $method === 'post' ? '201' : '200';
        $responses = [];

        if ($responseSchema && isset($this->schemas[$responseSchema])) {
            $responses[$successCode] = [
                'description' => 'Successful operation',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $responseSchema]]],
            ];
        } else {
            $responses[$successCode] = ['description' => 'Successful operation'];
        }

        if (str_contains($path, '{')) {
            $responses['404'] = ['description' => 'Resource not found'];
        }
        if (in_array($method, ['post', 'put', 'patch'])) {
            $responses['422'] = [
                'description' => 'Validation error',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationErrorResponse']]],
            ];
        }
        return $responses;
    }

    /**
     * @return array<string, mixed>
     */
    private function inferPropertyType(string $key, string $content): array
    {
        $type = match (true) {
            str_contains($key, 'id') || str_contains($key, 'count') || str_contains($key, 'stock') => 'integer',
            str_contains($key, 'price') || str_contains($key, 'amount') || str_contains($key, 'total') => 'number',
            str_contains($key, 'email') => ['type' => 'string', 'format' => 'email', 'example' => 'user@example.com'],
            str_contains($key, 'created_at') || str_contains($key, 'updated_at') || str_contains($key, 'deleted_at') => ['type' => 'string', 'format' => 'date-time'],
            str_contains($key, 'status') || str_contains($key, 'name') || str_contains($key, 'slug') => 'string',
            default => 'string',
        };
        return is_string($type) ? ['type' => $type] : $type;
    }

    private function castToType(string $cast): string
    {
        return match ($cast) {
            'int', 'integer' => 'integer',
            'float', 'double', 'decimal' => 'number',
            'bool', 'boolean' => 'boolean',
            'json', 'array' => 'object',
            default => 'string',
        };
    }

    private function buildCoreSchemas(): void
    {
        $this->schemas = [
            'SuccessResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => true],
                    'message' => ['type' => 'string', 'example' => 'Operation successful'],
                    'data' => ['nullable' => true],
                ],
            ],
            'ErrorResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => false],
                    'message' => ['type' => 'string', 'example' => 'Not found'],
                ],
            ],
            'ValidationErrorResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => false],
                    'message' => ['type' => 'string', 'example' => 'Validation failed'],
                    'errors' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
            ],
            'PaginationMeta' => [
                'type' => 'object',
                'properties' => [
                    'current_page' => ['type' => 'integer', 'example' => 1],
                    'per_page' => ['type' => 'integer', 'example' => 20],
                    'total' => ['type' => 'integer', 'example' => 100],
                    'last_page' => ['type' => 'integer', 'example' => 5],
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function buildTagsList(): array
    {
        $tagList = [];
        $defined = ['Auth' => 'Authentication', 'System' => 'System endpoints'];
        foreach (['Auth', 'System'] as $t) {
            if (isset($this->tags[$t])) {
                $tagList[] = ['name' => $t, 'description' => $defined[$t]];
            }
        }
        foreach ($this->tags as $tag => $desc) {
            if ($tag !== 'Auth' && $tag !== 'System') {
                $tagList[] = ['name' => $tag, 'description' => $tag . ' management'];
            }
        }
        return $tagList;
    }

    /**
     * @param array<string, mixed> $route
     */
    private function passesFilter(array $route): bool
    {
        $path = $route['path'] ?? '/';
        $method = $route['method'] ?? 'GET';
        $tag = $this->deriveTag($route['handler'] ?? '', $path);

        if ($method === 'OPTIONS') return false;
        if ($this->tagFilter !== null && !str_contains(strtolower($tag), strtolower($this->tagFilter))) return false;
        if ($this->methodFilter !== null && strtoupper($method) !== strtoupper($this->methodFilter)) return false;
        if ($this->pathFilter !== null && !str_contains($path, $this->pathFilter)) return false;
        if ($this->flowFilter !== null) {
            $flow = strtolower($this->flowFilter);
            if ($flow === 'auth' && !str_contains($path, '/auth/')) return false;
            if ($flow === 'crud' && !str_contains($path, '/api/')) return false;
        }
        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildFallbackRoutes(): array
    {
        // Fix 5: Explicit warning about fallback mode
        $this->write('');
        $this->write('  ⚠ WARNING: Running in fallback mode (static file parsing)');
        $this->write('  ⚠ Could not boot application to read actual routes.');
        $this->write('  ⚠ Generated spec may be incomplete or inaccurate.');
        $this->write('  ⚠ Consider fixing the app bootstrap issue for accurate specs.');
        $this->write('');
        $routes = [];
        $controllerDir = $this->basePath . '/app/Controllers';
        if (!is_dir($controllerDir)) return $routes;

        $httpMethods = [
            'index' => 'GET', 'show' => 'GET', 'store' => 'POST',
            'update' => 'PUT', 'delete' => 'DELETE',
        ];
        foreach (glob($controllerDir . '/*Controller.php') ?: [] as $file) {
            $base = basename($file, 'Controller.php');
            $resource = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $base));

            $routes[] = ['method' => 'GET', 'path' => '/api/' . $resource, 'handler' => "App\\Controllers\\{$base}Controller@index"];
            $routes[] = ['method' => 'GET', 'path' => "/api/{$resource}/{id}", 'handler' => "App\\Controllers\\{$base}Controller@show"];
            $routes[] = ['method' => 'POST', 'path' => '/api/' . $resource, 'handler' => "App\\Controllers\\{$base}Controller@store"];
            $routes[] = ['method' => 'PUT', 'path' => "/api/{$resource}/{id}", 'handler' => "App\\Controllers\\{$base}Controller@update"];
            $routes[] = ['method' => 'DELETE', 'path' => "/api/{$resource}/{id}", 'handler' => "App\\Controllers\\{$base}Controller@delete"];
        }
        return $routes;
    }

    /**
     * @param array<int, string> $args
     */
    private function parseArgs(array $args): void
    {
        foreach ($args as $arg) {
            if ($arg === '--with-swagger') $this->withSwagger = true;
            elseif (str_starts_with($arg, '--output=')) $this->outputFile = substr($arg, 9);
            elseif (str_starts_with($arg, '--title=')) $this->title = substr($arg, 8);
            elseif (str_starts_with($arg, '--version=')) $this->apiVersion = substr($arg, 10);
            elseif (str_starts_with($arg, '--host=')) $this->host = substr($arg, 7);
            elseif (str_starts_with($arg, '--tag=')) $this->tagFilter = substr($arg, 6);
            elseif (str_starts_with($arg, '--method=')) $this->methodFilter = strtoupper(substr($arg, 8));
            elseif (str_starts_with($arg, '--path=')) $this->pathFilter = substr($arg, 7);
            elseif (str_starts_with($arg, '--flow=')) $this->flowFilter = substr($arg, 7);
        }
    }

    private function generateSwaggerUi(string $outputDir): void
    {
        $swaggerDir = $outputDir . DIRECTORY_SEPARATOR . 'swagger';
        if (!is_dir($swaggerDir)) mkdir($swaggerDir, 0775, true);

        file_put_contents($swaggerDir . DIRECTORY_SEPARATOR . 'index.html', '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Siro API Docs</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css"></head><body><div id="swagger-ui"></div><script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script><script>SwaggerUIBundle({url:"/openapi.json",dom_id:"#swagger-ui",deepLinking:true,presets:[SwaggerUIBundle.presets.apis,SwaggerUIBundle.SwaggerUIStandalonePreset],layout:"BaseLayout",docExpansion:"list"})</script></body></html>');
        $this->write('Generated: ' . $swaggerDir . DIRECTORY_SEPARATOR . 'index.html');
    }

    private function copyToPublic(string $outputDir): void
    {
        $publicDir = $this->basePath . DIRECTORY_SEPARATOR . 'public';
        if (!is_dir($publicDir)) mkdir($publicDir, 0775, true);
        copy($outputDir . DIRECTORY_SEPARATOR . 'openapi.json', $publicDir . DIRECTORY_SEPARATOR . 'openapi.json');
        copy($outputDir . DIRECTORY_SEPARATOR . 'swagger' . DIRECTORY_SEPARATOR . 'index.html', $publicDir . DIRECTORY_SEPARATOR . 'docs.html');
        $isProd = in_array(strtolower((string) getenv('APP_ENV')), ['production', 'prod', 'staging'], true);
        if ($isProd) {
            $this->write('  ⚠ Files copied to public/ — accessible to anyone!');
            $this->write('  ⚠ Add route middleware to protect /openapi.json and /docs.html');
            $this->write('  ⚠ Or remove files after review: rm public/openapi.json public/docs.html');
        } else {
            $this->write('  API Docs ready!');
        }

        $host = $this->host;
        $this->write("  Swagger UI: http://{$host}/docs.html");
        $this->write("  OpenAPI:    http://{$host}/openapi.json");
    }
}
