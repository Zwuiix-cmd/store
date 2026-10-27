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

namespace zwuiix\store\data;

use InvalidArgumentException;
use function array_key_exists;
use function array_map;
use function array_pop;
use function array_values;
use function explode;
use function in_array;
use function is_array;
use function trim;

/**
 * Resolves and mutates dot separated paths in Store value trees.
 */
final class PathAccessor
{
	private function __construct()
	{
	}

	/**
	 * Reads a nested path from a value tree.
	 *
	 * @param array<string, mixed> $data Value tree to inspect.
	 * @param string               $path Dot-separated path to read.
	 * @param-out bool             $found Set to true when the path exists.
	 * @return mixed                    The value at the path, or null when it does not exist.
	 * @throws InvalidArgumentException If the path is empty or contains an empty segment.
	 */
	public static function get(array $data, string $path, bool &$found) : mixed
	{
		$found = true;
		$value = $data;
		foreach (self::segments($path) as $segment) {
			if (!is_array($value) || !array_key_exists($segment, $value)) {
				$found = false;

				return null;
			}

			$value = $value[$segment];
		}

		return $value;
	}

	/**
	 * Writes a value at a nested path, creating intermediate maps as needed.
	 *
	 * @param array<string, mixed> $data  Value tree to mutate.
	 * @param string               $path  Dot-separated path to write.
	 * @param mixed                $value Value to assign.
	 * @throws InvalidArgumentException If the path is empty or contains an empty segment.
	 */
	public static function set(array &$data, string $path, mixed $value) : void
	{
		$reference = &self::reference($data, self::segments($path));
		$reference = $value;
	}

	/**
	 * Removes a nested path and reports whether a value was removed.
	 *
	 * @param array<string, mixed> $data Value tree to mutate.
	 * @param string               $path Dot-separated path to remove.
	 * @return bool                     True when a value was removed.
	 * @throws InvalidArgumentException If the path is empty or contains an empty segment.
	 */
	public static function remove(array &$data, string $path) : bool
	{
		$segments = self::segments($path);
		$last = array_pop($segments);
		if ($last === null) {
			throw new InvalidArgumentException('A Store path cannot be empty.');
		}

		$reference = &self::reference($data, $segments, false);
		if (!is_array($reference) || !array_key_exists($last, $reference)) {
			return false;
		}

		unset($reference[$last]);

		return true;
	}

	/**
	 * Returns a reference to an intermediate value in a value tree.
	 *
	 * @param array<string, mixed> $data     Value tree to inspect or mutate.
	 * @param array<string|int>    $segments Path segments to traverse.
	 * @param bool                 $create   Whether missing intermediate maps may be created.
	 * @return mixed                A reference to the selected value, or a null reference when absent.
	 */
	public static function &reference(array &$data, array $segments, bool $create = true) : mixed
	{
		$reference = &$data;
		foreach ($segments as $segment) {
			if (!is_array($reference)) {
				if (!$create) {
					$null = null;

					return $null;
				}

				$reference = [];
			}

			if ($create && !array_key_exists($segment, $reference)) {
				$reference[$segment] = [];
			}

			if (!$create && !array_key_exists($segment, $reference)) {
				$null = null;

				return $null;
			}

			$reference = &$reference[$segment];
		}

		return $reference;
	}

	/**
	 * Splits and validates a dot-separated path.
	 *
	 * @param string $path Path to split.
	 * @return list<string>             Trimmed path segments.
	 * @throws InvalidArgumentException If the path is empty or contains an empty segment.
	 */
	private static function segments(string $path) : array
	{
		$path = trim($path, " .\t\n\r\0\x0B");
		if ($path === '') {
			throw new InvalidArgumentException('A Store path cannot be empty.');
		}

		$segments = array_map('trim', explode('.', $path));
		if (in_array('', $segments, true)) {
			throw new InvalidArgumentException("Invalid Store path '{$path}'.");
		}

		return array_values($segments);
	}
}
