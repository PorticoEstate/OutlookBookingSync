<?php

namespace App\Utils;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Centralized utility for extracting headers in FastCGI-compatible way.
 * Handles the differences between Apache mod_php and Apache + PHP-FPM environments.
 */
class HeaderUtils
{
	/**
	 * Extract header value from request, trying multiple formats for FastCGI compatibility.
	 *
	 * @param Request $request
	 * @param string $headerName The header name (e.g., 'X-API-Key', 'X-Tenant-Id', 'Authorization')
	 * @return string
	 */
	public static function getHeaderValue(Request $request, string $headerName): string
	{
		// Try standard PSR-7 header format first
		$value = $request->getHeaderLine($headerName);
		if ($value !== '')
		{
			return $value;
		}

		// Try alternate casing for X-prefixed headers
		if (stripos($headerName, 'X-') === 0)
		{
			$altName = str_replace('X-', 'x-', $headerName);
			$value = $request->getHeaderLine($altName);
			if ($value !== '')
			{
				return $value;
			}
		}

		// Get server parameters for FastCGI environment
		$serverParams = $request->getServerParams();
		
		// Convert header name to HTTP_* format (Apache/FastCGI style)
		$httpHeaderName = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
		if (isset($serverParams[$httpHeaderName]))
		{
			return $serverParams[$httpHeaderName];
		}

		// Try redirect variant (sometimes Apache adds REDIRECT_ prefix)
		$redirectHeaderName = 'REDIRECT_' . $httpHeaderName;
		if (isset($serverParams[$redirectHeaderName]))
		{
			return $serverParams[$redirectHeaderName];
		}

		// Special case mappings for common header variations
		$variations = self::getHeaderVariations();
		$normalizedHeaderName = strtolower($headerName);

		if (isset($variations[$normalizedHeaderName]))
		{
			foreach ($variations[$normalizedHeaderName] as $variation)
			{
				if (isset($serverParams[$variation]))
				{
					return $serverParams[$variation];
				}
			}
		}

		return '';
	}

	/**
	 * Extract API key from request using multiple fallback methods.
	 *
	 * @param Request $request
	 * @return string
	 */
	public static function getApiKey(Request $request): string
	{
		// Try common API key header formats
		$apiKeyHeaders = ['X-API-Key', 'X-API-Key', 'x-api-key', 'Authorization'];
		
		foreach ($apiKeyHeaders as $headerName)
		{
			$value = self::getHeaderValue($request, $headerName);
			if ($value !== '')
			{
				// Handle Bearer token format
				if ($headerName === 'Authorization' && stripos($value, 'Bearer ') === 0)
				{
					return substr($value, 7); // Remove 'Bearer ' prefix
				}
				return $value;
			}
		}

		return '';
	}

	/**
	 * Extract tenant ID from request using multiple fallback methods.
	 *
	 * @param Request $request
	 * @return string
	 */
	public static function getTenantId(Request $request): string
	{
		// Try common tenant ID header formats
		$tenantHeaders = ['X-Tenant-Id', 'x-tenant-id', 'Tenant-Id', 'tenant-id'];
		
		foreach ($tenantHeaders as $headerName)
		{
			$value = self::getHeaderValue($request, $headerName);
			if ($value !== '')
			{
				return $value;
			}
		}

		return '';
	}

	/**
	 * Extract CSRF token from request using multiple fallback methods.
	 *
	 * @param Request $request
	 * @return string
	 */
	public static function getCsrfToken(Request $request): string
	{
		// Try common CSRF token header formats
		$csrfHeaders = ['X-CSRF-Token', 'x-csrf-token', 'CSRF-Token', 'csrf-token'];
		
		foreach ($csrfHeaders as $headerName)
		{
			$value = self::getHeaderValue($request, $headerName);
			if ($value !== '')
			{
				return $value;
			}
		}

		return '';
	}

	/**
	 * Get predefined header variations for FastCGI compatibility.
	 *
	 * @return array<string, array<string>>
	 */
	private static function getHeaderVariations(): array
	{
		return [
			'X-API-Key' => [
				'HTTP_API_KEY',
				'HTTP_X_API_KEY', 
				'REDIRECT_HTTP_API_KEY',
				'REDIRECT_HTTP_X_API_KEY'
			],
			'x-api-key' => [
				'HTTP_X_API_KEY',
				'HTTP_API_KEY',
				'REDIRECT_HTTP_X_API_KEY',
				'REDIRECT_HTTP_API_KEY'
			],
			'x-tenant-id' => [
				'HTTP_X_TENANT_ID',
				'HTTP_TENANT_ID',
				'REDIRECT_HTTP_X_TENANT_ID',
				'REDIRECT_HTTP_TENANT_ID'
			],
			'tenant-id' => [
				'HTTP_TENANT_ID',
				'HTTP_X_TENANT_ID',
				'REDIRECT_HTTP_TENANT_ID',
				'REDIRECT_HTTP_X_TENANT_ID'
			],
			'x-csrf-token' => [
				'HTTP_X_CSRF_TOKEN',
				'HTTP_CSRF_TOKEN',
				'REDIRECT_HTTP_X_CSRF_TOKEN',
				'REDIRECT_HTTP_CSRF_TOKEN'
			],
			'csrf-token' => [
				'HTTP_CSRF_TOKEN',
				'HTTP_X_CSRF_TOKEN',
				'REDIRECT_HTTP_CSRF_TOKEN',
				'REDIRECT_HTTP_X_CSRF_TOKEN'
			],
			'authorization' => [
				'HTTP_AUTHORIZATION',
				'REDIRECT_HTTP_AUTHORIZATION'
			]
		];
	}

	/**
	 * Debug method to show all available headers (for development/troubleshooting).
	 *
	 * @param Request $request
	 * @return array
	 */
	public static function debugHeaders(Request $request): array
	{
		$headers = [];
		
		// PSR-7 headers
		foreach ($request->getHeaders() as $name => $values)
		{
			$headers['psr7'][$name] = implode(', ', $values);
		}
		
		// Server parameters
		$serverParams = $request->getServerParams();
		foreach ($serverParams as $key => $value)
		{
			if (strpos($key, 'HTTP_') === 0 || strpos($key, 'REDIRECT_HTTP_') === 0)
			{
				$headers['server'][$key] = $value;
			}
		}
		
		return $headers;
	}
}