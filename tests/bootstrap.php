<?php

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment variables from .env file
try
{
	$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
	$dotenv->load();
}
catch (Throwable $e)
{
	// If .env doesn't exist, use default test values
	$_ENV['DB_HOST'] = $_ENV['DB_HOST'] ?? 'localhost';
	$_ENV['DB_PORT'] = $_ENV['DB_PORT'] ?? '5432';
	$_ENV['DB_NAME'] = $_ENV['DB_NAME'] ?? 'calendar_bridge_test';
	$_ENV['DB_USER'] = $_ENV['DB_USER'] ?? 'bridge_user';
	$_ENV['DB_PASS'] = $_ENV['DB_PASS'] ?? 'bridge_password';
}
