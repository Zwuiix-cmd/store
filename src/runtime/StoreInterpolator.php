<?php

/*
 *
 * Licensed under the Apache License, Version 2.0.
 *
 * You may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * @author Zwuiix
 * @link https://github.com/Zwuiix-cmd/store
 *
 */

declare(strict_types=1);

namespace zwuiix\store\runtime;

use zwuiix\store\exception\StoreException;

use function array_key_exists;
use function explode;
use function getenv;
use function in_array;
use function is_array;
use function is_string;
use function json_encode;

use function preg_match;
use function preg_replace_callback;
use function str_starts_with;
use function strpos;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Resolves references such as ${database.host} and ${APP_ENV} in a value tree.
 */
final class StoreInterpolator
{
	private function __construct()
	{
	}

	/**
	 * Resolves references without changing the original tree.
	 *
	 * A complete reference keeps the referenced type. Embedded references are
	 * converted to text. Unknown references remain untouched by default.
	 *
	 * @param array<string, mixed> $values      Value tree to resolve.
	 * @param bool                 $environment Whether process variables may be used.
	 * @param bool                 $keepUnknown Whether unknown references stay visible.
	 * @return array<string, mixed> Resolved value tree.
	 * @throws StoreException       If references form a cycle.
	 */
	public static function resolve(array $values, bool $environment = true, bool $keepUnknown = true) : array
	{
		$result = [];
		foreach ($values as $key => $value) {
			$result[$key] = self::resolveValue($value, $values, (string) $key, [], $environment, $keepUnknown);
		}

		return $result;
	}

	/**
	 * Resolves one value recursively.
	 *
	 * @param mixed               $value
	 * @param array<string,mixed> $root
	 * @param list<string>        $stack
	 */
	private static function resolveValue(
		mixed  $value,
		array  $root,
		string $path,
		array  $stack,
		bool   $environment,
		bool   $keepUnknown,
	) : mixed {
		if (is_array($value)) {
			$result = [];
			foreach ($value as $key => $item) {
				$result[$key] = self::resolveValue($item, $root, $path . '.' . (string) $key, $stack, $environment, $keepUnknown);
			}

			return $result;
		}

		if (!is_string($value)) {
			return $value;
		}

		$pattern = '/\$\{([^}]+)\}|\$([A-Za-z_][A-Za-z0-9_]*)/';
		if (preg_match('/^' . substr($pattern, 1, -1) . '$/', $value, $complete) === 1) {
			$expression = $complete[1] !== '' ? $complete[1] : $complete[2];
			$resolved = self::resolveExpression($expression, $root, $path, $stack, $environment, $keepUnknown);
			if ($resolved['found']) {
				return $resolved['value'];
			}

			return $keepUnknown ? $value : '';
		}

		return preg_replace_callback($pattern, static function(array $matches) use ($root, $path, $stack, $environment, $keepUnknown) : string {
			$expression = $matches[1] !== '' ? $matches[1] : $matches[2];
			$resolved = self::resolveExpression($expression, $root, $path, $stack, $environment, $keepUnknown);
			if (!$resolved['found']) {
				return $keepUnknown ? $matches[0] : '';
			}

			if (is_string($resolved['value'])) {
				return $resolved['value'];
			}

			return json_encode($resolved['value'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
		}, $value) ?? $value;
	}

	/**
	 * Resolves one expression, including environment variables, defaults and functions.
	 *
	 * Supported forms include `${ENV:NAME}`, `${store.database.host}`,
	 * `${ENV:NAME:-fallback}` and `${lower:store.database.host}`.
	 *
	 * @param array<string,mixed> $root
	 * @param list<string>        $stack
	 * @return array{found: bool, value: mixed}
	 */
	private static function resolveExpression(
		string $expression,
		array $root,
		string $path,
		array $stack,
		bool $environment,
		bool $keepUnknown,
	) : array {
		[$expression, $default] = self::splitDefault($expression);
		$function = null;
		$argument = $expression;
		if (preg_match('/^(lower|upper|trim)(?::|\()(.+?)(?:\))?$/i', $expression, $matches) === 1) {
			$function = strtolower($matches[1]);
			$argument = $matches[2];
		}

		$environmentName = null;
		if (str_starts_with(strtoupper($argument), 'ENV:')) {
			$environmentName = substr($argument, 4);
			$argument = $environmentName;
		}
		if (str_starts_with(strtolower($argument), 'store.')) {
			$argument = substr($argument, 6);
		}

		$found = false;
		$reference = null;
		if ($environment && $environmentName !== null) {
			$reference = getenv($argument);
			$found = $reference !== false;
		} else {
			$reference = self::lookup($root, $argument, $found);
			if (!$found && $environment && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $argument) === 1) {
				$reference = getenv($argument);
				$found = $reference !== false;
			}
		}

		if (!$found && $default !== null) {
			$reference = $default;
			$found = true;
		}
		if (!$found) {
			return ['found' => false, 'value' => null];
		}
		if (in_array($argument, $stack, true) || $argument === $path) {
			throw new StoreException("Circular Store reference involving '{$argument}'.");
		}

		$value = self::resolveValue($reference, $root, $argument, [...$stack, $path], $environment, $keepUnknown);
		if ($function !== null) {
			$value = match ($function) {
				'lower' => strtolower((string) $value),
				'upper' => strtoupper((string) $value),
				'trim' => trim((string) $value),
				default => (string) $value,
			};
		}

		return ['found' => true, 'value' => $value];
	}

	/**
	 * Splits `expression:-default` while keeping the default text intact.
	 *
	 * @return array{0: string, 1: string|null}
	 */
	private static function splitDefault(string $expression) : array
	{
		$offset = strpos($expression, ':-');
		if ($offset === false) {
			return [$expression, null];
		}

		return [substr($expression, 0, $offset), substr($expression, $offset + 2)];
	}

	/**
	 * Looks up a dot-separated path without throwing for an unknown reference.
	 *
	 * @param array<string,mixed> $root
	 * @param string              $path
	 * @param-out bool             $found
	 * @return mixed
	 */
	private static function lookup(array $root, string $path, bool &$found) : mixed
	{
		if (array_key_exists($path, $root)) {
			$found = true;

			return $root[$path];
		}

		$found = false;
		$value = $root;
		foreach (explode('.', $path) as $segment) {
			if (!is_array($value) || !array_key_exists($segment, $value)) {
				return null;
			}

			$value = $value[$segment];
		}

		$found = true;

		return $value;
	}
}
