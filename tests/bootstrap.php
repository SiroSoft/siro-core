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

// Set required env for tests
$_ENV['JWT_SECRET'] = 'test_jwt_secret_key_for_unit_tests_only_32chars!!';
putenv('JWT_SECRET=' . $_ENV['JWT_SECRET']);
$_ENV['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
