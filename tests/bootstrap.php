<?php

/**
 * Test Bootstrap File
 * 
 * This file is loaded before running tests.
 * It sets up the autoloader and any required configurations.
 */

declare(strict_types=1);

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Set timezone for consistent test results
date_default_timezone_set('UTC');

// Error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', '1');
