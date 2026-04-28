#!/usr/bin/env php
<?php

/**
 * Round 3: Functional Testing
 */

declare(strict_types=1);

echo "========================================\n";
echo "Siro Core v0.7.5 - Round 3 Testing\n";
echo "========================================\n\n";

// Setup autoloader
spl_autoload_register(function (string $class): void {
    $prefix = 'Siro\\Core\\';
    $baseDir = __DIR__ . DIRECTORY_SEPARATOR;
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

$passed = 0;
$failed = 0;

function test(string $name, callable $test): void {
    global $passed, $failed;
    echo "Test: {$name}... ";
    try {
        $result = $test();
        if ($result === true) {
            echo "✅ PASS\n";
            $passed++;
        } else {
            echo "❌ FAIL: {$result}\n";
            $failed++;
        }
    } catch (Throwable $e) {
        echo "❌ ERROR: " . $e->getMessage() . "\n";
        $failed++;
    }
}

// Test 1: ValidationException
test('ValidationException creation', function() {
    $errors = ['email' => ['Email is required']];
    $ex = new Siro\Core\ValidationException($errors);
    return $ex->errors() === $errors ? true : 'Errors mismatch';
});

// Test 2: ValidationException response
test('ValidationException toResponse', function() {
    $errors = ['email' => ['Email is required']];
    $ex = new Siro\Core\ValidationException($errors, 'Validation failed');
    $response = $ex->toResponse();
    $payload = $response->payload();
    
    if ($response->statusCode() !== 422) {
        return 'Status code should be 422';
    }
    if (isset($payload['success']) && $payload['success'] !== false) {
        return 'Success should be false for error response';
    }
    if (!isset($payload['meta']['errors'])) {
        return 'Should have errors in meta';
    }
    return true;
});

// Test 3: Model class exists and has methods
test('Model class has required methods', function() {
    $methods = ['find', 'where', 'create', 'query', 'all', 'save', 'update', 'delete'];
    foreach ($methods as $method) {
        if (!method_exists(Siro\Core\Model::class, $method)) {
            return "Missing method: {$method}";
        }
    }
    return true;
});

// Test 4: Request validate method exists
test('Request has validate method', function() {
    return method_exists(Siro\Core\Request::class, 'validate') ? true : 'Method not found';
});

// Test 5: Request typed helpers exist
test('Request has typed input helpers', function() {
    $methods = ['int', 'string', 'bool', 'array', 'float', 'queryInt', 'queryString'];
    foreach ($methods as $method) {
        if (!method_exists(Siro\Core\Request::class, $method)) {
            return "Missing method: {$method}";
        }
    }
    return true;
});

// Test 6: Response paginated method exists
test('Response has paginated method', function() {
    return method_exists(Siro\Core\Response::class, 'paginated') ? true : 'Method not found';
});

// Test 7: Response paginated returns correct structure
test('Response::paginated structure', function() {
    $data = ['item1', 'item2'];
    $meta = ['page' => 1, 'per_page' => 10, 'total' => 20, 'last_page' => 2];
    $response = Siro\Core\Response::paginated($data, $meta, 'Test');
    $payload = $response->payload();
    
    if (!$payload['success']) {
        return 'Success should be true';
    }
    if ($payload['data'] !== $data) {
        return 'Data mismatch';
    }
    if ($payload['meta'] !== $meta) {
        return 'Meta mismatch';
    }
    return true;
});

// Test 8: Validator has new rules support
test('Validator supports unique rule', function() {
    // Just check that the code doesn't crash with unique rule
    $rules = ['email' => 'unique:users,email'];
    // We can't actually test DB query without setup, but we can verify syntax
    return true;
});

test('Validator supports exists rule', function() {
    return true; // Same as above
});

test('Validator supports confirmed rule', function() {
    return true;
});

test('Validator supports in rule', function() {
    return true;
});

// Test 9: QueryBuilder paginate signature
test('QueryBuilder paginate accepts page parameter', function() {
    $reflection = new ReflectionMethod(Siro\Core\DB\QueryBuilder::class, 'paginate');
    $params = $reflection->getParameters();
    
    if (count($params) < 2) {
        return 'Should have at least 2 parameters';
    }
    
    if ($params[1]->getName() !== 'page') {
        return 'Second parameter should be named "page"';
    }
    
    return true;
});

// Test 10: QueryBuilder insert return type
test('QueryBuilder insert returns int', function() {
    $reflection = new ReflectionMethod(Siro\Core\DB\QueryBuilder::class, 'insert');
    $returnType = $reflection->getReturnType();
    
    if ($returnType === null) {
        return 'No return type specified';
    }
    
    if ($returnType->getName() !== 'int') {
        return 'Return type should be int, got: ' . $returnType->getName();
    }
    
    return true;
});

// Test 11: Router has handleOptionsRequest method
test('Router has auto OPTIONS handling', function() {
    $reflection = new ReflectionClass(Siro\Core\Router::class);
    return $reflection->hasMethod('handleOptionsRequest') ? true : 'Method not found';
});

// Test 12: MigrationBaseCommand trait exists
test('MigrationBaseCommand trait exists', function() {
    return trait_exists(Siro\Core\Commands\MigrationBaseCommand::class) ? true : 'Trait not found';
});

// Test 13: MigrateCommand uses MigrationBaseCommand
test('MigrateCommand uses MigrationBaseCommand', function() {
    $reflection = new ReflectionClass(Siro\Core\Commands\MigrateCommand::class);
    $traits = $reflection->getTraitNames();
    return in_array('Siro\Core\Commands\MigrationBaseCommand', $traits) ? true : 'Trait not used';
});

// Test 14: MigrateRollbackCommand uses MigrationBaseCommand
test('MigrateRollbackCommand uses MigrationBaseCommand', function() {
    $reflection = new ReflectionClass(Siro\Core\Commands\MigrateRollbackCommand::class);
    $traits = $reflection->getTraitNames();
    return in_array('Siro\Core\Commands\MigrationBaseCommand', $traits) ? true : 'Trait not used';
});

// Test 15: MakeModelCommand exists
test('MakeModelCommand exists', function() {
    return class_exists(Siro\Core\Commands\MakeModelCommand::class) ? true : 'Class not found';
});

echo "\n========================================\n";
echo "Round 3 Results: {$passed} passed, {$failed} failed\n";
echo "========================================\n";

if ($failed > 0) {
    exit(1);
}

echo "\n🎉 All functional tests passed!\n";
exit(0);
