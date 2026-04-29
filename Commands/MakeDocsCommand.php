<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class MakeDocsCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $docsDir = $this->basePath . DIRECTORY_SEPARATOR . 'docs';
        $openapiDir = $docsDir . DIRECTORY_SEPARATOR . 'openapi';
        $swaggerDir = $docsDir . DIRECTORY_SEPARATOR . 'swagger';
        $publicDir = $this->basePath . DIRECTORY_SEPARATOR . 'public';

        foreach ([$docsDir, $openapiDir, $swaggerDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }

        // Generate OpenAPI spec via MakeOpenApiCommand
        $openapiCmd = new MakeOpenApiCommand($this->basePath);
        $openapiCmd->run(['--output=' . $openapiDir . DIRECTORY_SEPARATOR . 'openapi.json']);

        // Generate Swagger UI HTML
        $html = $this->docsHtml();
        file_put_contents($swaggerDir . DIRECTORY_SEPARATOR . 'index.html', $html);

        // Copy to public/ for serving
        copy($openapiDir . DIRECTORY_SEPARATOR . 'openapi.json', $publicDir . DIRECTORY_SEPARATOR . 'openapi.json');
        copy($swaggerDir . DIRECTORY_SEPARATOR . 'index.html', $publicDir . DIRECTORY_SEPARATOR . 'docs.html');

        $this->write('Docs generated:');
        $this->write("  docs/openapi/openapi.json");
        $this->write("  docs/swagger/index.html");
        $this->write("  public/openapi.json");
        $this->write("  public/docs.html");
        $this->write('');
        $this->write('Visit: http://localhost:8080/docs.html');

        return 0;
    }

    private function docsHtml(): string
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
}
