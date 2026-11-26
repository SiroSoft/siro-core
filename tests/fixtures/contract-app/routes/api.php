<?php
$router->get('/api/widgets', 'WidgetController@index');

// G7 fixture: one auto-generated resource so api:contract + make:openapi
// must understand G3's apiResource() routes (same source of routes, no
// special-casing). The model is an autoloadable trial model in the test
// namespace (tests/Integration/ContractWidget.php) — this fixture is test
// infrastructure, like ApiCrudE2eTest::buildFixtureApp.
$router->apiResource(
    \Siro\Core\Tests\Integration\ContractWidget::class,
    [
        'filterable' => ['status'],
        'sortable' => ['name'],
        'fields' => ['id', 'name', 'status'],
    ]
);
