<?php

/**
 * Edge Case Testing Script
 * Tests boundary conditions and error handling
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Siro\Core\Model;
use Siro\Core\DB\ModelQueryBuilder;

// Test Model class
class TestModel extends Model
{
    protected string $table = 'test_table';
    protected array $hidden = ['password'];
}

echo "=== EDGE CASE TESTING ===\n\n";

$testsPassed = 0;
$testsFailed = 0;

// Test 1: Hydrate empty array
echo "Test 1: Hydrate with empty data... ";
try {
    $model = TestModel::hydrate([]);
    echo "✅ PASS\n";
    $testsPassed++;
} catch (Throwable $e) {
    echo "❌ FAIL: {$e->getMessage()}\n";
    $testsFailed++;
}

// Test 2: HydrateAll with empty array
echo "Test 2: HydrateAll with empty array... ";
try {
    $models = TestModel::hydrateAll([]);
    if ($models === []) {
        echo "✅ PASS\n";
        $testsPassed++;
    } else {
        echo "❌ FAIL: Expected empty array\n";
        $testsFailed++;
    }
} catch (Throwable $e) {
    echo "❌ FAIL: {$e->getMessage()}\n";
    $testsFailed++;
}

// Test 3: first() returns null when no results
echo "Test 3: first() returns null... ";
try {
    // This would need a real database, skip for now
    echo "⚠️  SKIP (needs DB)\n";
} catch (Throwable $e) {
    echo "❌ FAIL: {$e->getMessage()}\n";
    $testsFailed++;
}

// Test 4: paginate() with invalid page number
echo "Test 4: paginate() handles invalid page... ";
try {
    // Should handle gracefully
    echo "⚠️  SKIP (needs DB)\n";
} catch (Throwable $e) {
    echo "❌ FAIL: {$e->getMessage()}\n";
    $testsFailed++;
}

// Test 5: Magic getter on non-existent attribute
echo "Test 5: Magic getter on non-existent attribute... ";
try {
    $model = TestModel::hydrate(['id' => 1]);
    $value = $model->non_existent_field;
    if ($value === null) {
        echo "✅ PASS\n";
        $testsPassed++;
    } else {
        echo "❌ FAIL: Expected null\n";
        $testsFailed++;
    }
} catch (Throwable $e) {
    echo "❌ FAIL: {$e->getMessage()}\n";
    $testsFailed++;
}

// Test 6: toArray() respects hidden fields
echo "Test 6: toArray() hides password... ";
try {
    $model = TestModel::hydrate([
        'id' => 1,
        'email' => 'test@test.com',
        'password' => 'secret123'
    ]);
    $array = $model->toArray();
    if (!isset($array['password'])) {
        echo "✅ PASS\n";
        $testsPassed++;
    } else {
        echo "❌ FAIL: Password should be hidden\n";
        $testsFailed++;
    }
} catch (Throwable $e) {
    echo "❌ FAIL: {$e->getMessage()}\n";
    $testsFailed++;
}

// Test 7: Magic getter bypasses hidden fields
echo "Test 7: Magic getter bypasses hidden... ";
try {
    $model = TestModel::hydrate([
        'id' => 1,
        'password' => 'secret123'
    ]);
    $password = $model->password;
    if ($password === 'secret123') {
        echo "✅ PASS\n";
        $testsPassed++;
    } else {
        echo "❌ FAIL: Expected 'secret123', got: " . var_export($password, true) . "\n";
        $testsFailed++;
    }
} catch (Throwable $e) {
    echo "❌ FAIL: {$e->getMessage()}\n";
    $testsFailed++;
}

// Summary
echo "\n=== SUMMARY ===\n";
echo "Tests Passed: $testsPassed\n";
echo "Tests Failed: $testsFailed\n";
echo "Total Tests: " . ($testsPassed + $testsFailed) . "\n";

if ($testsFailed === 0) {
    echo "\n✅ ALL TESTS PASSED!\n";
    exit(0);
} else {
    echo "\n❌ SOME TESTS FAILED!\n";
    exit(1);
}
