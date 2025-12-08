<?php

/**
 * Config Writer
 *
 * Updates wp-config.php with new database configuration.
 *
 * @package WP_DBAL\Migration
 */

declare(strict_types=1);

namespace WP_DBAL\Migration;

/**
 * Config Writer class.
 */
class ConfigWriter
{
	/**
	 * Update wp-config.php with new database engine.
	 *
	 * @param string $targetEngine Target database engine.
	 * @param array<string, mixed> $connectionParams Connection parameters.
	 * @return array{success: bool, error?: string}
	 */
	public function updateConfig(string $targetEngine, array $connectionParams): array
	{
		
		$configPath = file_exists( ABSPATH . 'wp-config.php') 
			? ABSPATH . 'wp-config.php' 
			: dirname(ABSPATH) . '/wp-config.php';
		
		if (! \file_exists($configPath)) {
			return [
				'success' => false,
				'error' => \__('wp-config.php not found', 'wp-dbal'),
			];
		}

		// Read config file.
		$content = \file_get_contents($configPath);
		if (false === $content) {
			return [
				'success' => false,
				'error' => \__('Failed to read wp-config.php', 'wp-dbal'),
			];
		}

		// Update or add DB_ENGINE constant.
		$content = $this->updateConstant($content, 'DB_ENGINE', $targetEngine);

		// Update engine-specific constants.
		$content = $this->updateEngineConstants($content, $targetEngine, $connectionParams);

		// Write updated config.
		return ( false === \file_put_contents($configPath, $content, LOCK_EX) ) ? [
			'success' => false,
			'error' => \__('Failed to write wp-config.php', 'wp-dbal'),
		] : [
			'success' => true,
		];
	}

	/**
	 * Update or add a constant in wp-config.php.
	 *
	 * @param string $content Config file content.
	 * @param string $constantName Constant name.
	 * @param string|int|bool $value Constant value.
	 * @param bool $isPath Whether this is a path that should use __DIR__.
	 * @return string Updated content.
	 */
	private function updateConstant(string $content, string $constantName, $value, bool $isPath = false): string
	{
		$formattedValue = $isPath ? $this->formatPathValue($value) : $this->formatConstantValue($value);
		
		$replacement = "define( '{$constantName}', {$formattedValue} );";

		// Try to match existing constant - handle both single and double quotes, and various whitespace.
		// Match the entire define() statement including any comments on the same line.
		$pattern = '/define\s*\(\s*([\'"])' . \preg_quote($constantName, '/') . '\1\s*,\s*[^;)]+\s*\)\s*;/i';

		if (\preg_match($pattern, $content)) {
			// Update existing constant - replace the entire matched define() statement.
			$result = \preg_replace($pattern, $replacement, $content, 1);
						
			// If replacement caused syntax error, try line-by-line replacement.
			$lines = \explode("\n", $content);
			$linePattern = '/define\s*\(\s*([\'"])' . \preg_quote($constantName, '/') . '\1\s*,\s*.*?\)\s*;/i';
			
			foreach ($lines as $idx => $line) {
				if (\preg_match($linePattern, $line)) {
					$lines[$idx] = $replacement;
					break;
				}
			}
			
			$result = \implode("\n", $lines);
			
			return $result;
		}
		
		// Add new constant before "That's all, stop editing!" comment.
		// Try different variations of the marker.
		$markers = [
			"/* That's all, stop editing! Happy publishing. */",
			"/* That's all, stop editing! */",
			"/* That's all, stop editing!",
		];
		
		foreach ($markers as $marker) {
			if (\strpos($content, $marker) !== false) {
				$result = \str_replace($marker, $replacement . "\n\n" . $marker, $content);
				return $result;
			}
		}

		// Add at end of file.
		$result = $content . "\n" . $replacement . "\n";
		return $result;
	}

	/**
	 * Update engine-specific constants.
	 *
	 * @param string $content Config file content.
	 * @param string $targetEngine Target engine.
	 * @param array<string, mixed> $connectionParams Connection parameters.
	 * @return string Updated content.
	 */
	private function updateEngineConstants(string $content, string $targetEngine, array $connectionParams): string
	{
		switch ($targetEngine) {
			case 'sqlite':
				if (isset($connectionParams['path'])) {
					$content = $this->updateConstant($content, 'DB_SQLITE_PATH', $connectionParams['path'], true);
				}
				break;

			case 'd1':
				if (isset($connectionParams['account_id'])) {
					$content = $this->updateConstant($content, 'DB_D1_ACCOUNT_ID', $connectionParams['account_id']);
				}
				if (isset($connectionParams['database_id'])) {
					$content = $this->updateConstant($content, 'DB_D1_DATABASE_ID', $connectionParams['database_id']);
				}
				if (isset($connectionParams['api_token'])) {
					$content = $this->updateConstant($content, 'DB_D1_API_TOKEN', $connectionParams['api_token']);
				}
				break;

			case 'filedb':
				if (isset($connectionParams['path'])) {
					$content = $this->updateConstant($content, 'DB_FILEDB_PATH', $connectionParams['path'], true);
				}
				if (isset($connectionParams['format'])) {
					$content = $this->updateConstant($content, 'DB_FILEDB_FORMAT', $connectionParams['format']);
				}
				break;

			case 'pgsql':
			case 'postgresql':
				if (isset($connectionParams['port'])) {
					$content = $this->updateConstant($content, 'DB_PORT', $connectionParams['port']);
				}
				// Update DB_DBAL_OPTIONS if provided.
				if (isset($connectionParams['host']) || isset($connectionParams['dbname']) || isset($connectionParams['user']) || isset($connectionParams['password'])) {
					$dbalOptions = \defined('DB_DBAL_OPTIONS') && \is_array(DB_DBAL_OPTIONS) ? DB_DBAL_OPTIONS : [];
					if (isset($connectionParams['host'])) {
						$dbalOptions['host'] = $connectionParams['host'];
					}
					if (isset($connectionParams['port'])) {
						$dbalOptions['port'] = (int) $connectionParams['port'];
					}
					if (isset($connectionParams['dbname'])) {
						$dbalOptions['dbname'] = $connectionParams['dbname'];
					}
					if (isset($connectionParams['user'])) {
						$dbalOptions['user'] = $connectionParams['user'];
					}
					if (isset($connectionParams['password'])) {
						$dbalOptions['password'] = $connectionParams['password'];
					}
					$dbalOptions['driver'] = 'pdo_pgsql';
					$content = $this->updateConstant($content, 'DB_DBAL_OPTIONS', $dbalOptions);
				}
				break;

			case 'mysql':
				// Update MySQL connection constants.
				if (isset($connectionParams['dbname'])) {
					$content = $this->updateConstant($content, 'DB_NAME', $connectionParams['dbname']);
				}
				if (isset($connectionParams['user'])) {
					$content = $this->updateConstant($content, 'DB_USER', $connectionParams['user']);
				}
				if (isset($connectionParams['password'])) {
					$content = $this->updateConstant($content, 'DB_PASSWORD', $connectionParams['password']);
				}
				if (isset($connectionParams['host'])) {
					$content = $this->updateConstant($content, 'DB_HOST', $connectionParams['host']);
				}
				if (isset($connectionParams['charset'])) {
					$content = $this->updateConstant($content, 'DB_CHARSET', $connectionParams['charset']);
				}
				if (isset($connectionParams['collate'])) {
					$content = $this->updateConstant($content, 'DB_COLLATE', $connectionParams['collate']);
				}
				break;
		}

		return $content;
	}

	/**
	 * Format constant value for PHP.
	 *
	 * @param string|int|bool|array $value Value to format.
	 * @return string Formatted value.
	 */
	private function formatConstantValue($value): string
	{
		if (\is_bool($value)) {
			return $value ? 'true' : 'false';
		}

		if (\is_int($value)) {
			return (string) $value;
		}

		if (\is_array($value)) {
			return \var_export($value, true);
		}

		return "'" . \addslashes((string) $value) . "'";
	}

	/**
	 * Format path value for PHP, using __DIR__ for relative paths.
	 *
	 * @param string $path Path value.
	 * @return string Formatted path value.
	 */
	private function formatPathValue(string $path): string
	{
		// If path is already absolute (starts with /) or contains a protocol (http://, etc.), use as-is.
		if (\strpos($path, '/') === 0 || \preg_match('/^[a-z][a-z0-9+.-]*:/i', $path)) {
			return "'" . \addslashes($path) . "'";
		}

		// For relative paths, use __DIR__ . '/path'
		$escapedPath = \addslashes($path);
		return "__DIR__ . '/{$escapedPath}'";
	}

}

