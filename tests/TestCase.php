<?php

declare(strict_types=1);

namespace Siro\Core\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base Test Case Class
 * 
 * All test classes should extend this class.
 * Provides common setup and helper methods.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Setup method called before each test
     */
    protected function setUp(): void
    {
        parent::setUp();
        // Add common setup here if needed
    }

    /**
     * Teardown method called after each test
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        // Add common cleanup here if needed
    }

    /**
     * Helper to create a temporary file
     */
    protected function createTempFile(string $content = ''): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'siro_test_');
        file_put_contents($tempFile, $content);
        return $tempFile;
    }

    /**
     * Helper to remove a file
     */
    protected function removeFile(string $file): void
    {
        if (file_exists($file)) {
            unlink($file);
        }
    }
}
