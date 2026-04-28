#!/usr/bin/env php
<?php

/**
 * Quick Verification Test for v0.7.6 Features
 */

declare(strict_types=1);

$basePath = __DIR__;
require_once $basePath . '/vendor/autoload.php';

use Siro\Core\Request;
use Siro\Core\Model;
use Siro\Core\Resource;
use Siro\Core\UploadedFile;
use Siro\Core\DB\SoftDeletes;
use Siro\Core\DB\Relations\HasMany;
use Siro\Core\DB\Relations\BelongsTo;
use Siro\Core\Commands\RouteListCommand;

echo "========================================\n";
echo "Siro Core v0.7.6 Feature Verification\n";
echo "========================================\n\n";

$passed = 0;
$failed = 0;

function test(string $name, callable $test): void {
    global $passed, $failed;
    echo "Test: $name... ";
    try {
        $result = $test();
        if ($result === true) {
            echo "✅ PASS\n";
            $passed++;
        } else {
            echo "❌ FAIL: $result\n";
            $failed++;
        }
    } catch (Throwable $e) {
        echo "❌ ERROR: " . $e->getMessage() . "\n";
        $failed++;
    }
}

// Feature 1: Request->validated() and only()
test('Request::validated() exists', function() {
    $reflection = new ReflectionClass(Request::class);
    return $reflection->hasMethod('validated') ? true : 'Method not found';
});

test('Request::only() exists', function() {
    $reflection = new ReflectionClass(Request::class);
    return $reflection->hasMethod('only') ? true : 'Method not found';
});

// Feature 2: Model scopes via ModelQueryBuilder
test('ModelQueryBuilder exists', function() {
    return class_exists(\Siro\Core\DB\ModelQueryBuilder::class) ? true : 'Class not found';
});

// Feature 3: Model relationships
test('HasMany relationship exists', function() {
    return class_exists(HasMany::class) ? true : 'Class not found';
});

test('BelongsTo relationship exists', function() {
    return class_exists(BelongsTo::class) ? true : 'Class not found';
});

test('Model::hasMany() method exists', function() {
    $reflection = new ReflectionClass(Model::class);
    return $reflection->hasMethod('hasMany') ? true : 'Method not found';
});

test('Model::belongsTo() method exists', function() {
    $reflection = new ReflectionClass(Model::class);
    return $reflection->hasMethod('belongsTo') ? true : 'Method not found';
});

// Feature 4: Soft Deletes
test('SoftDeletes trait exists', function() {
    return trait_exists(SoftDeletes::class) ? true : 'Trait not found';
});

test('SoftDeletes has delete() method', function() {
    $reflection = new ReflectionClass(SoftDeletes::class);
    return $reflection->hasMethod('delete') ? true : 'Method not found';
});

test('SoftDeletes has forceDelete() method', function() {
    $reflection = new ReflectionClass(SoftDeletes::class);
    return $reflection->hasMethod('forceDelete') ? true : 'Method not found';
});

// Feature 5: Resource auto-map
test('Resource::make() exists', function() {
    $reflection = new ReflectionClass(Resource::class);
    return $reflection->hasMethod('make') ? true : 'Method not found';
});

test('Resource::collectionOf() exists', function() {
    $reflection = new ReflectionClass(Resource::class);
    return $reflection->hasMethod('collectionOf') ? true : 'Method not found';
});

// Feature 6: File upload
test('UploadedFile class exists', function() {
    return class_exists(UploadedFile::class) ? true : 'Class not found';
});

test('Request::file() method exists', function() {
    $reflection = new ReflectionClass(Request::class);
    return $reflection->hasMethod('file') ? true : 'Method not found';
});

// Feature 7: route:list command
test('RouteListCommand exists', function() {
    return class_exists(RouteListCommand::class) ? true : 'Class not found';
});

test('RouteListCommand has run() method', function() {
    $reflection = new ReflectionClass(RouteListCommand::class);
    return $reflection->hasMethod('run') ? true : 'Method not found';
});

// Feature 8: Unit tests exist (check files)
test('Unit test directory exists', function() {
    return is_dir(__DIR__ . '/tests/unit') ? true : 'Directory not found';
});

echo "\n========================================\n";
echo "Verification Summary\n";
echo "========================================\n";
echo "Total Tests: " . ($passed + $failed) . "\n";
echo "Passed: ✅ $passed\n";
echo "Failed: ❌ $failed\n";
echo "Success Rate: " . (($passed + $failed) > 0 ? round(($passed / ($passed + $failed)) * 100, 1) : 0) . "%\n";
echo "========================================\n";

if ($failed === 0) {
    echo "\n🎉 All v0.7.6 features verified successfully!\n";
    exit(0);
} else {
    echo "\n⚠️  Some features need attention.\n";
    exit(1);
}
