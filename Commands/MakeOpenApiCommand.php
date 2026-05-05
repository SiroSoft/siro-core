<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\App;

final class MakeOpenApiCommand
{
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

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    public function run(array $args): int
    {
        $this->parseArgs($args);

        $spec = $this->buildSpec();
        $json = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $outputDir = dirname($this->outputFile !== '' ? $this->outputFile : ($this->basePath . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'openapi'));
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0775, true);
        }

        file_put_contents($outputDir . DIRECTORY_SEPARATOR . 'openapi.json', $json);
        $this->write('Generated: ' . $outputDir . DIRECTORY_SEPARATOR . 'openapi.json');

        if ($this->withSwagger) {
            $this->generateSwaggerUi($outputDir);
            $this->copyToPublic($outputDir);
        }

        return 0;
    }

    private function parseArgs(array $args): void
    {
        foreach ($args as $arg) {
            if ($arg === '--with-swagger') {
                $this->withSwagger = true;
            } elseif (str_starts_with($arg, '--output=')) {
                $this->outputFile = substr($arg, 9);
            } elseif (str_starts_with($arg, '--title=')) {
                $this->title = substr($arg, 8);
            } elseif (str_starts_with($arg, '--version=')) {
                $this->apiVersion = substr($arg, 10);
            } elseif (str_starts_with($arg, '--host=')) {
                $this->host = substr($arg, 7);
            } elseif (str_starts_with($arg, '--tag=')) {
                $this->tagFilter = substr($arg, 6);
            } elseif (str_starts_with($arg, '--method=')) {
                $this->methodFilter = strtoupper(substr($arg, 8));
            } elseif (str_starts_with($arg, '--path=')) {
                $this->pathFilter = substr($arg, 7);
            } elseif (str_starts_with($arg, '--flow=')) {
                $this->flowFilter = substr($arg, 7);
            }
        }
    }

    private function buildSpec(): array
    {
        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => $this->title,
                'version' => $this->apiVersion,
                'description' => 'Siro API Framework — The Fastest PHP Micro-Framework for API Development',
                'contact' => ['name' => 'SiroSoft Team', 'url' => 'https://github.com/SiroSoft/SiroPHP'],
                'license' => ['name' => 'MIT', 'url' => 'https://opensource.org/licenses/MIT'],
            ],
            'servers' => [
                ['url' => 'http://' . $this->host, 'description' => 'Local development'],
                ['url' => 'https://api.example.com', 'description' => 'Production'],
            ],
            'tags' => [
                ['name' => 'Auth', 'description' => 'Authentication endpoints'],
                ['name' => 'Users', 'description' => 'User management CRUD'],
                ['name' => 'Products', 'description' => 'Product management CRUD'],
                ['name' => 'Categories', 'description' => 'Category management CRUD'],
                ['name' => 'Tags', 'description' => 'Tag management CRUD'],
                ['name' => 'System', 'description' => 'System endpoints'],
            ],
            'paths' => $this->buildPaths(),
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT',
                        'description' => 'JWT token obtained from /api/auth/login or /api/auth/register',
                    ],
                ],
                'schemas' => $this->buildSchemas(),
            ],
            'security' => [
                ['bearerAuth' => []],
            ],
        ];

        return $spec;
    }

    private function buildPaths(): array
    {
        $paths = [];

        // Root
        if ($this->passesFilter('System', 'GET', '/')) {
            $paths['/'] = [
                'get' => [
                    'tags' => ['System'],
                    'summary' => 'Welcome',
                    'description' => 'Root endpoint returning API information',
                    'security' => [],
                    'responses' => [
                        '200' => ['description' => 'API info', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SuccessResponse']]]],
                    ],
                ],
            ];
        }

        // Health
        if ($this->passesFilter('System', 'GET', '/health')) {
            $paths['/health'] = [
                'get' => [
                    'tags' => ['System'],
                    'summary' => 'Health check',
                    'description' => 'Health check endpoint for load balancers and monitoring',
                    'security' => [],
                    'responses' => [
                        '200' => ['description' => 'Service healthy', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/HealthResponse']]]],
                    ],
                ],
            ];
        }

        $this->addAuthPaths($paths);
        $this->addCrudPaths($paths, 'users', 'User', ['index', 'show', 'store', 'update', 'delete']);
        $this->addCrudPaths($paths, 'products', 'Product', ['index', 'show', 'store', 'update', 'delete']);
        $this->addCrudPaths($paths, 'categories', 'Category', ['index', 'show', 'store', 'update', 'delete']);
        $this->addCrudPaths($paths, 'tag', 'Tag', ['index', 'show', 'store', 'update', 'delete']);

        return $paths;
    }

    private function addAuthPaths(array &$paths): void
    {
        $authEndpoints = [
            'register' => ['method' => 'POST', 'path' => '/api/auth/register', 'summary' => 'Register new user', 'auth' => false, 'body' => 'RegisterRequest', 'response' => 'AuthResponse', 'status' => 201],
            'login' => ['method' => 'POST', 'path' => '/api/auth/login', 'summary' => 'Login', 'auth' => false, 'body' => 'LoginRequest', 'response' => 'AuthResponse', 'status' => 200],
            'refresh' => ['method' => 'POST', 'path' => '/api/auth/refresh', 'summary' => 'Refresh access token', 'auth' => false, 'body' => 'RefreshRequest', 'response' => 'RefreshResponse', 'status' => 200],
            'me' => ['method' => 'GET', 'path' => '/api/auth/me', 'summary' => 'Get authenticated user', 'auth' => true, 'body' => null, 'response' => 'UserResponse', 'status' => 200],
            'logout' => ['method' => 'POST', 'path' => '/api/auth/logout', 'summary' => 'Logout', 'auth' => true, 'body' => null, 'response' => 'SuccessResponse', 'status' => 200],
            'verify-email' => ['method' => 'POST', 'path' => '/api/auth/verify-email', 'summary' => 'Verify email address', 'auth' => false, 'body' => 'VerifyEmailRequest', 'response' => 'SuccessResponse', 'status' => 200],
            'forgot-password' => ['method' => 'POST', 'path' => '/api/auth/forgot-password', 'summary' => 'Request password reset', 'auth' => false, 'body' => 'ForgotPasswordRequest', 'response' => 'SuccessResponse', 'status' => 200],
            'reset-password' => ['method' => 'POST', 'path' => '/api/auth/reset-password', 'summary' => 'Reset password with token', 'auth' => false, 'body' => 'ResetPasswordRequest', 'response' => 'SuccessResponse', 'status' => 200],
        ];

        foreach ($authEndpoints as $name => $ep) {
            if (!$this->passesFilter('Auth', $ep['method'], $ep['path'])) continue;

            $operation = [
                'tags' => ['Auth'],
                'summary' => $ep['summary'],
                'security' => $ep['auth'] ? [] : [['bearerAuth' => []]],
                'responses' => [
                    (string) $ep['status'] => ['description' => 'Successful operation', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $ep['response']]]]],
                    '401' => ['description' => 'Unauthorized', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]]],
                    '422' => ['description' => 'Validation error', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationErrorResponse']]]],
                ],
            ];

            if (!$ep['auth']) {
                $operation['security'] = [];
            }

            if ($ep['body'] !== null) {
                $operation['requestBody'] = [
                    'required' => true,
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $ep['body']]]],
                ];
            }

            $paths[$ep['path']][strtolower($ep['method'])] = $operation;
        }
    }

    private function addCrudPaths(array &$paths, string $resource, string $model, array $actions): void
    {
        $tag = ucfirst($resource === 'tag' ? 'Tags' : $resource);

        if (in_array('index', $actions, true)) {
            $listPath = '/api/' . $resource;
            if ($this->passesFilter($tag, 'GET', $listPath)) {
                $paths[$listPath]['get'] = [
                    'tags' => [$tag],
                    'summary' => 'List ' . $resource,
                    'security' => [],
                    'parameters' => [
                        ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 1]],
                        ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 20, 'maximum' => 100]],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Paginated list', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Paginated' . $model . 'Response']]]],
                    ],
                ];
            }
        }

        if (in_array('show', $actions, true)) {
            $showPath = '/api/' . $resource . '/{id}';
            if ($this->passesFilter($tag, 'GET', $showPath)) {
                $paths[$showPath]['get'] = [
                    'tags' => [$tag],
                    'summary' => 'Get ' . $model . ' by ID',
                    'security' => [],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'responses' => [
                        '200' => ['description' => $model . ' details', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $model . 'Response']]]],
                        '404' => ['description' => 'Not found', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]]],
                    ],
                ];
            }
        }

        if (in_array('store', $actions, true)) {
            $storePath = '/api/' . $resource;
            if ($this->passesFilter($tag, 'POST', $storePath)) {
                $op = [
                    'tags' => [$tag],
                    'summary' => 'Create ' . $model,
                    'security' => [['bearerAuth' => []]],
                    'requestBody' => [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Create' . $model . 'Request']]],
                    ],
                    'responses' => [
                        '201' => ['description' => 'Created', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $model . 'Response']]]],
                        '422' => ['description' => 'Validation error', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationErrorResponse']]]],
                    ],
                ];
                if ($resource === 'products') {
                    $op['security'] = [];
                }
                $paths[$storePath]['post'] = $op;
            }
        }

        if (in_array('update', $actions, true)) {
            $updatePath = '/api/' . $resource . '/{id}';
            if ($this->passesFilter($tag, 'PUT', $updatePath)) {
                $paths[$updatePath]['put'] = [
                    'tags' => [$tag],
                    'summary' => 'Update ' . $model,
                    'security' => [['bearerAuth' => []]],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Update' . $model . 'Request']]],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Updated', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $model . 'Response']]]],
                        '404' => ['description' => 'Not found', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]]],
                        '422' => ['description' => 'Validation error', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationErrorResponse']]]],
                    ],
                ];
            }
        }

        if (in_array('delete', $actions, true)) {
            $deletePath = '/api/' . $resource . '/{id}';
            if ($this->passesFilter($tag, 'DELETE', $deletePath)) {
                $paths[$deletePath]['delete'] = [
                    'tags' => [$tag],
                    'summary' => 'Delete ' . $model,
                    'security' => [['bearerAuth' => []]],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Deleted', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SuccessResponse']]]],
                        '404' => ['description' => 'Not found', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]]],
                    ],
                ];
            }
        }
    }

    private function buildSchemas(): array
    {
        return [
            // ─── Core Response Wrappers ───
            'SuccessResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => true],
                    'message' => ['type' => 'string', 'example' => 'Operation successful'],
                    'data' => ['nullable' => true, 'type' => 'object'],
                    'meta' => ['type' => 'object'],
                ],
            ],
            'ErrorResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => false],
                    'message' => ['type' => 'string', 'example' => 'Resource not found'],
                ],
            ],
            'ValidationErrorResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => false],
                    'message' => ['type' => 'string', 'example' => 'Validation failed'],
                    'errors' => [
                        'type' => 'object',
                        'additionalProperties' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'example' => ['email' => ['The email field is required.']],
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

            // ─── Health ───
            'HealthResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => true],
                    'message' => ['type' => 'string', 'example' => 'OK'],
                    'data' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string', 'example' => 'healthy'],
                            'version' => ['type' => 'string', 'example' => '0.15.0'],
                            'php' => ['type' => 'string', 'example' => '8.2.30'],
                            'database' => ['type' => 'string', 'example' => 'connected'],
                            'time' => ['type' => 'string', 'format' => 'date-time', 'example' => '2026-05-06T12:00:00+00:00'],
                        ],
                    ],
                ],
            ],

            // ─── Auth ───
            'RegisterRequest' => [
                'type' => 'object',
                'required' => ['name', 'email', 'password'],
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 120, 'example' => 'John Doe'],
                    'email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 255, 'example' => 'john@example.com'],
                    'password' => ['type' => 'string', 'minLength' => 6, 'maxLength' => 255, 'example' => 'secret123'],
                ],
            ],
            'LoginRequest' => [
                'type' => 'object',
                'required' => ['email', 'password'],
                'properties' => [
                    'email' => ['type' => 'string', 'format' => 'email', 'example' => 'john@example.com'],
                    'password' => ['type' => 'string', 'example' => 'secret123'],
                ],
            ],
            'RefreshRequest' => [
                'type' => 'object',
                'required' => ['refresh_token'],
                'properties' => [
                    'refresh_token' => ['type' => 'string', 'example' => 'eyJ...'],
                ],
            ],
            'VerifyEmailRequest' => [
                'type' => 'object',
                'required' => ['token'],
                'properties' => [
                    'token' => ['type' => 'string', 'example' => 'abc123...'],
                ],
            ],
            'ForgotPasswordRequest' => [
                'type' => 'object',
                'required' => ['email'],
                'properties' => [
                    'email' => ['type' => 'string', 'format' => 'email', 'example' => 'john@example.com'],
                ],
            ],
            'ResetPasswordRequest' => [
                'type' => 'object',
                'required' => ['token', 'password'],
                'properties' => [
                    'token' => ['type' => 'string', 'example' => 'abc123...'],
                    'password' => ['type' => 'string', 'minLength' => 6, 'maxLength' => 255, 'example' => 'newpassword123'],
                ],
            ],
            'UserData' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'name' => ['type' => 'string', 'example' => 'John Doe'],
                    'email' => ['type' => 'string', 'format' => 'email', 'example' => 'john@example.com'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time', 'example' => '2026-05-06 12:00:00'],
                ],
            ],
            'TokenData' => [
                'type' => 'object',
                'properties' => [
                    'token' => ['type' => 'string', 'example' => 'eyJhbGciOiJIUzI1NiIs...'],
                    'refresh_token' => ['type' => 'string', 'example' => 'eyJhbGciOiJIUzI1NiIs...'],
                    'token_type' => ['type' => 'string', 'example' => 'Bearer'],
                    'expires_in' => ['type' => 'integer', 'example' => 3600],
                ],
            ],
            'AuthResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => true],
                    'message' => ['type' => 'string', 'example' => 'Login successful'],
                    'data' => ['$ref' => '#/components/schemas/TokenData'],
                ],
            ],
            'RefreshResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => true],
                    'message' => ['type' => 'string', 'example' => 'Token refreshed'],
                    'data' => ['$ref' => '#/components/schemas/TokenData'],
                ],
            ],
            'UserResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => true],
                    'message' => ['type' => 'string', 'example' => 'User retrieved'],
                    'data' => ['$ref' => '#/components/schemas/UserData'],
                ],
            ],

            // ─── Users CRUD ───
            'CreateUserRequest' => [
                'type' => 'object',
                'required' => ['name', 'email', 'password'],
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 120, 'example' => 'John Doe'],
                    'email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 255, 'example' => 'john@example.com'],
                    'password' => ['type' => 'string', 'minLength' => 6, 'maxLength' => 255, 'example' => 'secret123'],
                ],
            ],
            'UpdateUserRequest' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 120, 'example' => 'John Updated'],
                    'email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 255, 'example' => 'john.new@example.com'],
                    'password' => ['type' => 'string', 'minLength' => 6, 'maxLength' => 255, 'example' => 'newpass123'],
                ],
            ],
            'PaginatedUserResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => true],
                    'message' => ['type' => 'string', 'example' => 'Users retrieved'],
                    'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/UserData']],
                    'meta' => ['$ref' => '#/components/schemas/PaginationMeta'],
                ],
            ],

            // ─── Products CRUD ───
            'ProductData' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'name' => ['type' => 'string', 'example' => 'Laptop'],
                    'description' => ['type' => 'string', 'nullable' => true, 'example' => 'High performance laptop'],
                    'price' => ['type' => 'number', 'format' => 'float', 'example' => 999.99],
                    'stock' => ['type' => 'integer', 'example' => 50],
                    'category' => ['type' => 'string', 'nullable' => true, 'example' => 'Electronics'],
                    'status' => ['type' => 'string', 'example' => 'active'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time', 'example' => '2026-05-06 12:00:00'],
                ],
            ],
            'CreateProductRequest' => [
                'type' => 'object',
                'required' => ['name'],
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'example' => 'New Product'],
                    'description' => ['type' => 'string', 'example' => 'Product description'],
                    'price' => ['type' => 'number', 'format' => 'float', 'example' => 29.99],
                    'stock' => ['type' => 'integer', 'example' => 100],
                    'category' => ['type' => 'string', 'example' => 'Accessories'],
                    'status' => ['type' => 'string', 'example' => 'active'],
                ],
            ],
            'UpdateProductRequest' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'example' => 'Updated Product'],
                    'description' => ['type' => 'string', 'example' => 'Updated description'],
                    'price' => ['type' => 'number', 'format' => 'float', 'example' => 19.99],
                    'stock' => ['type' => 'integer', 'example' => 200],
                    'category' => ['type' => 'string', 'example' => 'Updated Category'],
                    'status' => ['type' => 'string', 'example' => 'inactive'],
                ],
            ],
            'ProductResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => true],
                    'message' => ['type' => 'string', 'example' => 'Product fetched'],
                    'data' => ['$ref' => '#/components/schemas/ProductData'],
                ],
            ],
            'PaginatedProductResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => true],
                    'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ProductData']],
                    'meta' => ['$ref' => '#/components/schemas/PaginationMeta'],
                ],
            ],

            // ─── Categories CRUD ───
            'CategoryData' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'name' => ['type' => 'string', 'example' => 'Electronics'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time', 'example' => '2026-05-06 12:00:00'],
                ],
            ],
            'CreateCategoryRequest' => [
                'type' => 'object',
                'required' => ['name', 'slug'],
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 100, 'example' => 'Electronics'],
                    'slug' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 100, 'example' => 'electronics'],
                ],
            ],
            'UpdateCategoryRequest' => [
                'type' => 'object',
                'required' => ['name', 'slug'],
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 100, 'example' => 'Updated Category'],
                    'slug' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 100, 'example' => 'updated-category'],
                ],
            ],
            'CategoryResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => true],
                    'data' => ['$ref' => '#/components/schemas/CategoryData'],
                ],
            ],
            'PaginatedCategoryResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => true],
                    'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CategoryData']],
                    'meta' => ['$ref' => '#/components/schemas/PaginationMeta'],
                ],
            ],

            // ─── Tags CRUD ───
            'TagData' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'name' => ['type' => 'string', 'example' => 'sale'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time', 'example' => '2026-05-06 12:00:00'],
                ],
            ],
            'CreateTagRequest' => [
                'type' => 'object',
                'required' => ['name'],
                'properties' => [
                    'name' => ['type' => 'string', 'example' => 'new-tag'],
                ],
            ],
            'UpdateTagRequest' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'example' => 'updated-tag'],
                ],
            ],
            'TagResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => true],
                    'data' => ['$ref' => '#/components/schemas/TagData'],
                ],
            ],
            'PaginatedTagResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => true],
                    'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/TagData']],
                    'meta' => ['$ref' => '#/components/schemas/PaginationMeta'],
                ],
            ],
        ];
    }

    private function passesFilter(string $tag, string $method, string $path): bool
    {
        if ($this->tagFilter !== null && !str_contains(strtolower($tag), strtolower($this->tagFilter))) {
            return false;
        }
        if ($this->methodFilter !== null && $method !== $this->methodFilter) {
            return false;
        }
        if ($this->pathFilter !== null && !str_contains($path, $this->pathFilter)) {
            return false;
        }
        if ($this->flowFilter !== null) {
            $flow = strtolower($this->flowFilter);
            $pathLower = strtolower($path);
            if ($flow === 'auth' && !str_contains($pathLower, '/auth/')) return false;
            if ($flow === 'crud' && !str_contains($pathLower, '/api/')) return false;
        }
        return true;
    }

    private function generateSwaggerUi(string $outputDir): void
    {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Siro API — Swagger UI</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
  <style>html{box-sizing:border-box}*,*::before,*::after{box-sizing:inherit}body{margin:0;background:#fafafa}</style>
</head>
<body>
  <div id="swagger-ui"></div>
  <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
  <script>
    SwaggerUIBundle({
      url: '/openapi.json',
      dom_id: '#swagger-ui',
      deepLinking: true,
      presets: [SwaggerUIBundle.presets.apis, SwaggerUIBundle.SwaggerUIStandalonePreset],
      layout: 'BaseLayout',
      docExpansion: 'list',
      defaultModelExpandDepth: 3,
    });
  </script>
</body>
</html>
HTML;
        file_put_contents($outputDir . DIRECTORY_SEPARATOR . 'swagger' . DIRECTORY_SEPARATOR . 'index.html', $html);
        $this->write('Generated: ' . $outputDir . DIRECTORY_SEPARATOR . 'swagger' . DIRECTORY_SEPARATOR . 'index.html');
    }

    private function copyToPublic(string $outputDir): void
    {
        $publicDir = $this->basePath . DIRECTORY_SEPARATOR . 'public';
        if (!is_dir($publicDir)) {
            mkdir($publicDir, 0775, true);
        }

        copy($outputDir . DIRECTORY_SEPARATOR . 'openapi.json', $publicDir . DIRECTORY_SEPARATOR . 'openapi.json');
        $this->write('Copied: ' . $publicDir . DIRECTORY_SEPARATOR . 'openapi.json');

        $swaggerFile = $outputDir . DIRECTORY_SEPARATOR . 'swagger' . DIRECTORY_SEPARATOR . 'index.html';
        if (file_exists($swaggerFile)) {
            copy($swaggerFile, $publicDir . DIRECTORY_SEPARATOR . 'docs.html');
            $this->write('Copied: ' . $publicDir . DIRECTORY_SEPARATOR . 'docs.html');
        }

        $this->write('');
        $this->write('  API Docs ready!');
        $this->write('  Swagger UI: http://localhost:8080/docs.html');
        $this->write('  OpenAPI:    http://localhost:8080/openapi.json');
    }
}
