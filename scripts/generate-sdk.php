#!/usr/bin/env php
<?php
declare(strict_types=1);

$openApiFile = $argv[1] ?? __DIR__ . '/../storage/openapi.json';
$outputDir = $argv[2] ?? __DIR__ . '/../storage/sdk';

if (!is_file($openApiFile)) {
    fwrite(STDERR, "Error: OpenAPI spec not found at $openApiFile\n");
    fwrite(STDERR, "Generate it first: php siro make:openapi\n");
    exit(1);
}

$spec = json_decode(file_get_contents($openApiFile), true);
if (!is_array($spec) || !isset($spec['paths'])) {
    fwrite(STDERR, "Error: Invalid OpenAPI spec\n");
    exit(1);
}

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0775, true);
}

$namespace = 'App\\Sdk';
$sdkClass = 'ApiClient';
$className = 'ApiClient';

$methods = [];
$models = [];

foreach ($spec['paths'] as $path => $pathItem) {
    foreach ($pathItem as $method => $operation) {
        $method = strtolower($method);
        if (!in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'options', 'head'], true)) continue;

        $operationId = $operation['operationId'] ?? str_replace(['/', '{', '}'], ['_', '', ''], trim($path, '/')) . '_' . $method;
        $summary = $operation['summary'] ?? $operationId;
        $parameters = $operation['parameters'] ?? [];
        $requestBody = $operation['requestBody'] ?? null;

        // Parse path params
        $phpPath = preg_replace('/\{(\w+)\}/', '\'.$1.', $path);
        $pathParams = [];
        preg_match_all('/\{(\w+)\}/', $path, $pathParams);
        $pathParams = $pathParams[1];

        // Get response schema
        $responseSchema = null;
        $responseType = 'array';
        foreach (($operation['responses'] ?? []) as $code => $response) {
            if ($code >= 200 && $code < 300) {
                $content = $response['content']['application/json'] ?? null;
                if ($content && isset($content['schema'])) {
                    $schema = $content['schema'];
                    if ($schema['type'] === 'array' && isset($schema['items']['$ref'])) {
                        $ref = $schema['items']['$ref'];
                        $responseType = $namespace . '\\' . basename(str_replace('/', '\\', $ref));
                    } elseif (isset($schema['$ref'])) {
                        $ref = $schema['$ref'];
                        $responseType = $namespace . '\\' . basename(str_replace('/', '\\', $ref));
                    }
                }
                break;
            }
        }

        // Collect model refs
        if ($requestBody) {
            $content = $requestBody['content']['application/json'] ?? null;
            if ($content && isset($content['schema']['$ref'])) {
                $ref = basename(str_replace('/', '\\', $content['schema']['$ref']));
                $models[$ref] = true;
            }
        }

        // Build method signature
        $signatureParams = [];
        $callParams = [];
        foreach ($pathParams as $pp) {
            $signatureParams[] = "string \${$pp}";
            $callParams[] = "'{$pp}' => \${$pp}";
        }
        if ($requestBody) {
            $signatureParams[] = 'array $data = []';
            $callParams[] = '$data';
        }

        $isGet = $method === 'get';
        $methodBody = "\$url = \$this->baseUrl . \"{$phpPath}\";\n";
        $methodBody .= "        \$response = \\Siro\\Core\\Http::{$method}(\$url";
        if ($isGet && $requestBody === null) {
            $methodBody .= ', [], $this->headers';
        } elseif (!$isGet && $requestBody) {
            $methodBody .= ', $data, $this->headers';
        } elseif (!$isGet) {
            $methodBody .= ', null, $this->headers';
        } else {
            $methodBody .= ', $this->headers';
        }
        $methodBody .= ');';
        $methodBody .= "\n        return \$response->json();";

        $methods[$operationId] = [
            'name' => lcfirst(str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $operationId)))),
            'summary' => $summary,
            'params' => implode(', ', $signatureParams),
            'body' => $methodBody,
            'responseType' => $responseType,
        ];
    }
}

// Build SDK class
$classBody = "<?php\n\n";
$classBody .= "declare(strict_types=1);\n\n";
$classBody .= "namespace {$namespace};\n\n";
$classBody .= "/**\n";
$classBody .= " * Auto-generated API client from OpenAPI spec.\n";
$classBody .= " * Generated: " . date('c') . "\n";
$classBody .= " */\n";
$classBody .= "final class {$className}\n";
$classBody .= "{\n";
$classBody .= "    private string \$baseUrl;\n";
$classBody .= "    /** @var array<string, string> */\n";
$classBody .= "    private array \$headers = [];\n\n";
$classBody .= "    public function __construct(string \$baseUrl, ?string \$apiKey = null)\n";
$classBody .= "    {\n";
$classBody .= "        \$this->baseUrl = rtrim(\$baseUrl, '/');\n";
$classBody .= "        if (\$apiKey !== null) {\n";
$classBody .= "            \$this->headers['Authorization'] = 'Bearer ' . \$apiKey;\n";
$classBody .= "        }\n";
$classBody .= "        \$this->headers['Accept'] = 'application/json';\n";
$classBody .= "        \$this->headers['Content-Type'] = 'application/json';\n";
$classBody .= "    }\n\n";

foreach ($methods as $opId => $method) {
    $classBody .= "    /**\n";
    $classBody .= "     * {$method['summary']}\n";
    $classBody .= "     *\n";
    $classBody .= "     * @return {$method['responseType']}\n";
    $classBody .= "     */\n";
    $classBody .= "    public function {$method['name']}({$method['params']}): {$method['responseType']}\n";
    $classBody .= "    {\n";
    $classBody .= "        {$method['body']}\n";
    $classBody .= "    }\n\n";
}

$classBody .= "}\n";

file_put_contents($outputDir . '/' . $className . '.php', $classBody);

echo "SDK generated: {$outputDir}/{$className}.php\n";
echo "Methods: " . count($methods) . "\n";

if (count($methods) > 0) {
    echo "\nGenerated methods:\n";
    foreach ($methods as $opId => $m) {
        echo "  - {$m['name']}({$m['params']})\n";
    }
}

exit(0);
