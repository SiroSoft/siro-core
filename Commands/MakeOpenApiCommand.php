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

    /**
 * Generate OpenAPI 3.0.3 spec.
 *
 * Reads all routes from the Router, extracts validation rules from
 * controller files via regex, and produces an openapi.json file
 * compatible with Swagger UI and code generators.
 *
 * @package Siro\Core\Commands
 */
    public function run(array $args): int
    {
        $openapiDir = $this->basePath . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'openapi';
        if (!is_dir($openapiDir)) {
            mkdir($openapiDir, 0775, true);
        }
        $output = $openapiDir . DIRECTORY_SEPARATOR . 'openapi.json';
        $title = 'Siro API';
        $version = '1.0.0';
        $host = 'localhost:8080';
        $schemes = ['http'];
        $filterTag = null;
        $filterMethod = null;
        $filterPath = null;
        $filterFlow = null;
        $withSwagger = false;

        foreach ($args as $arg) {
            if ($arg === '--with-swagger') {
                $withSwagger = true;
                continue;
            }
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
            $hasAuthMiddleware = in_array('auth', $middlewares, true)
                || count(array_filter($middlewares, fn($m) => stripos($m, 'auth') !== false || stripos($m, 'jwt') !== false)) > 0;
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
            $requestSchema = $this->extractValidationRules($handler, $path);

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

        if ($withSwagger) {
            $swaggerDir = $this->basePath . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'swagger';
            if (!is_dir($swaggerDir)) {
                mkdir($swaggerDir, 0775, true);
            }
            $html = $this->swaggerHtml();
            file_put_contents($swaggerDir . DIRECTORY_SEPARATOR . 'index.html', $html);

            $publicDir = $this->basePath . DIRECTORY_SEPARATOR . 'public';
            copy($output, $publicDir . DIRECTORY_SEPARATOR . 'openapi.json');
            copy($swaggerDir . DIRECTORY_SEPARATOR . 'index.html', $publicDir . DIRECTORY_SEPARATOR . 'docs.html');

            $this->write("  docs/swagger/index.html");
            $this->write("  public/openapi.json");
            $this->write("  public/docs.html");
            $this->write('');
            $this->write('Visit: http://localhost:8080/docs.html');
        }

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

    private function extractValidationRules(string $handler, string $routePath = ''): ?array
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

        // Find the method body
        $methodPattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*:\s*Response\s*\{(.*?)\}/s';
        preg_match($methodPattern, $source, $methodMatch);
        $methodBody = $methodMatch[1] ?? '';

        $properties = [];
        $required = [];

        // 1. Try extract from $request->validate([...]) first
        preg_match('/\$request->validate\(\s*\[(.*?)\]\s*\)/s', $methodBody, $validateMatch);
        if (isset($validateMatch[1])) {
            preg_match_all("/'(\w+)'\s*=>\s*'([^']+)'/", $validateMatch[1], $ruleMatches);
            foreach ($ruleMatches[1] as $i => $field) {
                $rules = explode('|', $ruleMatches[2][$i]);
                $properties[$field] = $this->ruleToSchemaProperty($field, $rules);
                if (in_array('required', $rules, true)) $required[] = $field;
            }
        }

        // 2. Extract from $request->input('field') and $request->string('field')
        preg_match_all('/\$request->(?:input|string|int|float)\(\s*[\'"]([^\'"]+)[\'"]/', $methodBody, $inputMatches);
        foreach ($inputMatches[1] as $field) {
            if (!isset($properties[$field])) {
                $type = 'string';
                if (str_contains($methodBody, '(int) $request->input(\'' . $field . '\')') || str_contains($methodBody, '->int(\'' . $field . '\')')) $type = 'integer';
                elseif (str_contains($methodBody, '(float) $request->input(\'' . $field . '\')') || str_contains($methodBody, '->float(\'' . $field . '\')')) $type = 'number';
                $properties[$field] = ['type' => $type, 'example' => $type === 'integer' ? 1 : ($type === 'number' ? 0.0 : 'string'), 'description' => ucfirst(str_replace('_', ' ', $field))];
            }
        }

        // 3. Extract from $request->only([...])
        preg_match_all('/\$request->only\(\s*\[([^\]]+)\]\s*\)/', $methodBody, $onlyMatches);
        foreach ($onlyMatches[1] as $block) {
            preg_match_all('/[\'"]([^\'"]+)[\'"]/', $block, $fields);
            foreach ($fields[1] as $field) {
                if (!isset($properties[$field])) {
                    $properties[$field] = ['type' => 'string', 'example' => 'string', 'description' => ucfirst(str_replace('_', ' ', $field))];
                }
            }
        }

        // 4. Extract from $data['field'] = or $item['field'] = patterns
        preg_match_all('/\[\s*[\'"]([^\'"]+)[\'"]\s*\]\s*=\s*(?:\$request->|\$data|\$item|\$input)/', $methodBody, $assignMatches);
        foreach ($assignMatches[1] as $field) {
            if (!isset($properties[$field]) && !in_array($field, ['id', 'payment_id', 'product_id', 'store_id', 'admin_id', 'user_id', 'items', 'created_at', 'updated_at', 'status'], true)) {
                $properties[$field] = ['type' => 'string', 'example' => 'string', 'description' => ucfirst(str_replace('_', ' ', $field))];
            }
        }

        // 5. If nothing found, try path-based inference
        if (empty($properties)) {
            return $this->inferSchemaFromPath($methodName, $routePath !== '' ? $routePath : $this->findPathForHandler($handler));
        }

        $schema = ['type' => 'object', 'properties' => $properties];
        if (!empty($required)) $schema['required'] = $required;

        $example = [];
        foreach ($properties as $field => $prop) {
            $example[$field] = $prop['example'] ?? ($prop['type'] === 'integer' ? 1 : 'string');
        }
        $schema['example'] = $example;

        return $schema;
    }

    private function findControllerFile(string $handler): ?string
    {
        if (!str_contains($handler, '@')) return null;

        $parts = explode('@', $handler);
        $controllerClass = $parts[0];
        $relativePath = str_replace(['App\\Controllers\\', 'App\\', '\\'], ['', '', DIRECTORY_SEPARATOR], $controllerClass);

        // Try multiple paths
        $paths = [
            $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . $relativePath . '.php',
            $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $controllerClass) . '.php',
        ];

        foreach ($paths as $path) {
            if (is_file($path)) return $path;
        }
        return null;
    }

    private function findPathForHandler(string $handler): string
    {
        // This is a best-effort lookup. The routes are already processed,
        // we stored them in $this->routes or can search logic.
        // Since we're in a static context, we'll infer from the handler name.
        if (str_contains($handler, '@')) {
            $parts = explode('@', $handler);
            $action = $parts[1] ?? '';
            return '/' . str_replace(['get', 'post', 'Controller'], '', lcfirst(str_replace('\\', '/', $parts[0]))) . '/' . $action;
        }
        return '';
    }

    private function inferSchemaFromPath(string $methodName, string $path): ?array
    {
        $p = strtolower($path . ' ' . $methodName);

        if (str_contains($p, 'login')) return ['type' => 'object', 'properties' => ['phone' => ['type' => 'string'], 'password' => ['type' => 'string', 'format' => 'password']], 'required' => ['phone', 'password']];
        if (str_contains($p, 'create_payment') || str_contains($p, 'store_payment')) return ['type' => 'object', 'properties' => ['items' => ['type' => 'array'], 'payment_method' => ['type' => 'string'], 'table_id' => ['type' => 'integer'], 'customer_id' => ['type' => 'integer'], 'status' => ['type' => 'integer']], 'required' => ['items']];
        if (str_contains($p, 'register') || str_contains($p, 'create_customer')) return ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'phone' => ['type' => 'string'], 'password' => ['type' => 'string']], 'required' => ['phone']];
        if (str_contains($p, 'add_product') || str_contains($p, 'edit_product') || str_contains($p, 'store_product')) return ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'price' => ['type' => 'number'], 'category_id' => ['type' => 'integer']], 'required' => ['title']];
        if (str_contains($p, 'create_category')) return ['type' => 'object', 'properties' => ['title' => ['type' => 'string']], 'required' => ['title']];
        if (str_contains($p, 'create_table') || str_contains($p, 'store_table')) return ['type' => 'object', 'properties' => ['tablename' => ['type' => 'string']], 'required' => ['tablename']];
        if (str_contains($p, 'add_agency') || str_contains($p, 'create_agency') || str_contains($p, 'agency/create')) return ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'phone' => ['type' => 'string'], 'address' => ['type' => 'string']], 'required' => ['name']];
        if (str_contains($p, 'addinvoiceinput') || str_contains($p, 'input_add') || str_contains($p, 'input_invoice')) return ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'agency_id' => ['type' => 'integer'], 'items' => ['type' => 'array']], 'required' => ['items']];
        if (str_contains($p, 'export_invoice') || str_contains($p, 'create_export')) return ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'note' => ['type' => 'string'], 'items' => ['type' => 'array']], 'required' => ['items']];
        if (str_contains($p, 'create_booking') || str_contains($p, 'creat_booking')) return ['type' => 'object', 'properties' => ['table_id' => ['type' => 'integer'], 'customer_name' => ['type' => 'string'], 'customer_phone' => ['type' => 'string'], 'booking_time' => ['type' => 'string']], 'required' => ['table_id']];
        if (str_contains($p, 'add_combo')) return ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'price' => ['type' => 'number'], 'products' => ['type' => 'array']], 'required' => ['title']];
        if (str_contains($p, 'create_contact')) return ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'phone' => ['type' => 'string'], 'message' => ['type' => 'string']]];
        if (str_contains($p, 'add_rating_question') || str_contains($p, 'create_rating')) return ['type' => 'object', 'properties' => ['question' => ['type' => 'string'], 'type' => ['type' => 'string']]];

        if (str_contains($p, 'delete_') || str_contains($p, 'destroy_') || str_contains($p, 'remove_')) return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];
        if (str_contains($p, 'update_') || str_contains($p, 'edit_')) return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];
        if (str_contains($p, 'change_password')) return ['type' => 'object', 'properties' => ['old_password' => ['type' => 'string'], 'new_password' => ['type' => 'string']], 'required' => ['old_password', 'new_password']];
        if (str_contains($p, 'add_device_token') || str_contains($p, 'store_device_token')) return ['type' => 'object', 'properties' => ['device_token' => ['type' => 'string']]];
        if (str_contains($p, 'update_language') || str_contains($p, 'update-language')) return ['type' => 'object', 'properties' => ['language' => ['type' => 'string']]];

        if (str_contains($p, 'check_in_table') || str_contains($p, 'check_out_table') || str_contains($p, 'release_table')) return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];
        if (str_contains($p, 'enable_order') || str_contains($p, 'disable_order') || str_contains($p, 'reset_qr')) return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];
        if (str_contains($p, 'reassign_user')) return ['type' => 'object', 'properties' => ['table_id' => ['type' => 'integer'], 'user_id' => ['type' => 'integer']]];
        if (str_contains($p, 'change_table')) return ['type' => 'object', 'properties' => ['from_table_id' => ['type' => 'integer'], 'to_table_id' => ['type' => 'integer']]];
        if (str_contains($p, 'print_order')) return ['type' => 'object', 'properties' => ['table_id' => ['type' => 'integer']]];
        if (str_contains($p, 'update_printed')) return ['type' => 'object', 'properties' => ['detail_id' => ['type' => 'integer'], 'quantity' => ['type' => 'integer']]];
        if (str_contains($p, 'update_order_table') || str_contains($p, 'update_booking_order')) return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'listitem' => ['type' => 'string']]];

        if (str_contains($p, 'company_create') || str_contains($p, 'create_company')) return ['type' => 'object', 'properties' => ['company_name' => ['type' => 'string'], 'company_address' => ['type' => 'string']], 'required' => ['company_address']];
        if (str_contains($p, 'create_footer') || str_contains($p, 'store_footer')) return ['type' => 'object', 'properties' => ['header' => ['type' => 'string'], 'footer_text' => ['type' => 'string']]];
        if (str_contains($p, 'update_footer')) return ['type' => 'object', 'properties' => ['header' => ['type' => 'string'], 'footer_text' => ['type' => 'string']]];
        if (str_contains($p, 'printer_update') || str_contains($p, 'update_printer')) return ['type' => 'object', 'properties' => ['list_printer' => ['type' => 'array']]];
        if (str_contains($p, 'cash_drawer_add') || str_contains($p, 'add_cash_drawer')) return ['type' => 'object', 'properties' => ['start_amount' => ['type' => 'number']]];
        if (str_contains($p, 'cash_drawer_update') || str_contains($p, 'update_cash_drawer')) return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'status' => ['type' => 'string']]];
        if (str_contains($p, 'store_branch_add') || str_contains($p, 'create_branch')) return ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'phone' => ['type' => 'string'], 'storename' => ['type' => 'string'], 'password' => ['type' => 'string']], 'required' => ['name', 'phone', 'storename', 'password']];
        if (str_contains($p, 'store_branch_update') || str_contains($p, 'update_branch')) return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'storename' => ['type' => 'string']]];
        if (str_contains($p, 'update_store') || str_contains($p, 'update-store')) return ['type' => 'object', 'properties' => ['storename' => ['type' => 'string'], 'address' => ['type' => 'string']]];
        if (str_contains($p, 'update_currency')) return ['type' => 'object', 'properties' => ['currency' => ['type' => 'string']]];
        if (str_contains($p, 'ip_refresh')) return ['type' => 'object', 'properties' => ['current_ip' => ['type' => 'string']]];
        if (str_contains($p, 'save_print_kitchen')) return ['type' => 'object', 'properties' => ['setting' => ['type' => 'object']]];
        if (str_contains($p, 'generate_api_key')) return ['type' => 'object', 'properties' => []];

        if (str_contains($p, 'payment_method_add') || str_contains($p, 'add_method')) return ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']];
        if (str_contains($p, 'payment_method_update') || str_contains($p, 'update_method')) return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]];
        if (str_contains($p, 'payment_method_delete') || str_contains($p, 'delete_method')) return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];
        if (str_contains($p, 'check_used')) return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];
        if (str_contains($p, 'edit_menu')) return ['type' => 'object', 'properties' => ['items' => ['type' => 'array']]];

        if (str_contains($p, 'store_customer_orders') || str_contains($p, 'customer_order')) return ['type' => 'object', 'properties' => ['table_id' => ['type' => 'integer'], 'items' => ['type' => 'array']], 'required' => ['items']];
        if (str_contains($p, 'update_customer_orders')) return ['type' => 'object', 'properties' => ['payment_id' => ['type' => 'integer'], 'items' => ['type' => 'array']]];
        if (str_contains($p, 'update_number_of_people')) return ['type' => 'object', 'properties' => ['table_id' => ['type' => 'integer'], 'number_of_people' => ['type' => 'integer']]];
        if (str_contains($p, 'call_staff')) return ['type' => 'object', 'properties' => ['table_id' => ['type' => 'integer']]];
        if (str_contains($p, 'notification_bill') || str_contains($p, 'paymentnotification')) return ['type' => 'object', 'properties' => ['payment_id' => ['type' => 'integer']]];
        if (str_contains($p, 'create_pin')) return ['type' => 'object', 'properties' => ['pin' => ['type' => 'string']]];
        if (str_contains($p, 'check_qr_code') || str_contains($p, 'check_qr_token')) return ['type' => 'object', 'properties' => ['token' => ['type' => 'string']]];
        if (str_contains($p, 'store_customer_ratings') || str_contains($p, 'store_rating')) return ['type' => 'object', 'properties' => ['rating' => ['type' => 'integer'], 'comment' => ['type' => 'string']]];

        if (str_contains($p, 'create_attribute')) return ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'type' => ['type' => 'string'], 'options' => ['type' => 'array']]];
        if (str_contains($p, 'store_product_type') || str_contains($p, 'update_product_type')) return ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];
        if (str_contains($p, 'time_prices_store') || str_contains($p, 'time_price')) return ['type' => 'object', 'properties' => ['product_id' => ['type' => 'integer'], 'price' => ['type' => 'number'], 'start_time' => ['type' => 'string'], 'end_time' => ['type' => 'string']]];
        if (str_contains($p, 'user_authorization_update') || str_contains($p, 'update_permission')) return ['type' => 'object', 'properties' => ['user_id' => ['type' => 'integer'], 'permission_ids' => ['type' => 'array']]];
        if (str_contains($p, 'financial_report_create') || str_contains($p, 'create_report')) return ['type' => 'object', 'properties' => ['report_type' => ['type' => 'string'], 'total_revenue' => ['type' => 'number'], 'report_date' => ['type' => 'string']]];
        if (str_contains($p, 'electronic_bill_store') || str_contains($p, 'store_electronic')) return ['type' => 'object', 'properties' => ['payment_id' => ['type' => 'integer'], 'ma_hoadon' => ['type' => 'string']]];

        if (str_contains($p, 'split_invoice')) return ['type' => 'object', 'properties' => ['payment_id' => ['type' => 'integer'], 'items' => ['type' => 'array']], 'required' => ['payment_id']];
        if (str_contains($p, 'merge_invoice')) return ['type' => 'object', 'properties' => ['payment_ids' => ['type' => 'array']], 'required' => ['payment_ids']];
        if (str_contains($p, 'create_payment_link') || str_contains($p, 'payos_create')) return ['type' => 'object', 'properties' => ['amount' => ['type' => 'integer'], 'description' => ['type' => 'string']], 'required' => ['amount']];
        if (str_contains($p, 'provider_create') || str_contains($p, 'activate_provider')) return ['type' => 'object', 'properties' => ['tax_code' => ['type' => 'string']]];
        if (str_contains($p, 'setting_default_provider') || str_contains($p, 'default_provider')) return ['type' => 'object', 'properties' => ['idProvider' => ['type' => 'integer']]];
        if (str_contains($p, 'webhook')) return ['type' => 'object', 'properties' => ['orderCode' => ['type' => 'integer'], 'amount' => ['type' => 'integer']]];
        if (str_contains($p, 'update_notification') || str_contains($p, 'notification_status')) return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'status' => ['type' => 'integer']]];
        if (str_contains($p, 'served')) return ['type' => 'object', 'properties' => ['payment_detail_id' => ['type' => 'integer']]];
        if (str_contains($p, 'filter_list') || str_contains($p, 'search_list')) return ['type' => 'object', 'properties' => ['query' => ['type' => 'string'], 'status' => ['type' => 'integer'], 'pageSize' => ['type' => 'integer'], 'currentPage' => ['type' => 'integer']]];

        if (str_contains($p, 'update_threshold') || str_contains($p, 'update_bank')) return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];
        if (str_contains($p, 'invoice_templates') || str_contains($p, 'template')) return ['type' => 'object', 'properties' => ['template' => ['type' => 'object']]];

        return null;
    }

    private function swaggerHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Siro API Docs</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>&#x2699;</text></svg>">
<style>
html { box-sizing: border-box; overflow-y: scroll; }
*, *:before, *:after { box-sizing: inherit; }
body { margin: 0; background: #fafafa; }
.swagger-ui .topbar { display: none; }
</style>
</head>
<body>
<div id="swagger-ui"></div>
<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
<script>
SwaggerUIBundle({
    url: '/openapi.json',
    dom_id: '#swagger-ui',
    deepLinking: true,
    presets: [SwaggerUIBundle.presets.apis],
    layout: 'BaseLayout'
});
</script>
</body>
</html>
HTML;
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
