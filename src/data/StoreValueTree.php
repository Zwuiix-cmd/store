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

use zwuiix\store\merge\StoreMergeConflict;
use function array_key_exists;
use function array_keys;
use function array_pop;
use function array_unique;
use function explode;
use function is_array;

/**
 * Shared dot-path helpers used by merge and snapshot features.
 */
final class StoreValueTree
{
	private function __construct()
	{
	}

	/**
	 * Merges local and external trees against a common baseline.
	 *
	 * @param array<string,mixed>      $base      Last loaded values.
	 * @param array<string,mixed>      $local     Current in-memory values.
	 * @param array<string,mixed>      $remote    Values read externally.
	 * @param list<StoreMergeConflict> $conflicts Conflicts collected by reference.
	 * @return array<string,mixed>             Merged values.
	 */
	public static function merge(array $base, array $local, array $remote, array &$conflicts) : array
	{
		$result = self::mergeValue(true, $base, true, $local, true, $remote, '', $conflicts);

		return is_array($result[1]) ? $result[1] : [];
	}

	/**
	 * Writes or removes a dot-separated path.
	 *
	 * @param array<string,mixed> $values Values to update.
	 * @param string              $path   Dot-separated path.
	 * @param bool                $exists Whether the path should exist.
	 * @param mixed               $value  Value to write.
	 */
	public static function write(array &$values, string $path, bool $exists, mixed $value) : void
	{
		if ($path === '') {
			return;
		}

		$parts = explode('.', $path);
		$last = array_pop($parts);

		$target = &$values;
		foreach ($parts as $part) {
			if (!isset($target[$part]) || !is_array($target[$part])) {
				$target[$part] = [];
			}

			$target = &$target[$part];
		}

		if ($exists) {
			$target[$last] = $value;
		} else {
			unset($target[$last]);
		}
	}

	/**
	 * @param bool                     $baseExists
	 * @param mixed                    $base
	 * @param bool                     $localExists
	 * @param mixed                    $local
	 * @param bool                     $remoteExists
	 * @param mixed                    $remote
	 * @param string                   $path
	 * @param list<StoreMergeConflict> $conflicts
	 * @return array{0: bool, 1: mixed}
	 */
	private static function mergeValue(
		bool $baseExists,
		mixed $base,
		bool $localExists,
		mixed $local,
		bool $remoteExists,
		mixed $remote,
		string $path,
		array &$conflicts,
	) : array {
		if (self::same($localExists, $local, $baseExists, $base)) {
			return [$remoteExists, $remote];
		}
		if (self::same($remoteExists, $remote, $baseExists, $base) || self::same($localExists, $local, $remoteExists, $remote)) {
			return [$localExists, $local];
		}

		if (is_array($local) && is_array($remote)) {
			$baseArray = is_array($base) ? $base : [];
			$localArray = $local;
			$remoteArray = $remote;
			$result = [];
			$keys = array_unique([...array_keys($baseArray), ...array_keys($localArray), ...array_keys($remoteArray)]);
			foreach ($keys as $key) {
				$childPath = $path === '' ? (string) $key : $path . '.' . $key;
				$child = self::mergeValue(
					array_key_exists($key, $baseArray),
					$baseArray[$key] ?? null,
					array_key_exists($key, $localArray),
					$localArray[$key] ?? null,
					array_key_exists($key, $remoteArray),
					$remoteArray[$key] ?? null,
					$childPath,
					$conflicts,
				);
				if ($child[0]) {
					$result[$key] = $child[1];
				}
			}

			return [true, $result];
		}

		$conflicts[] = new StoreMergeConflict($path, $baseExists, $base, $localExists, $local, $remoteExists, $remote);

		return [$localExists, $local];
	}

	private static function same(bool $leftExists, mixed $left, bool $rightExists, mixed $right) : bool
	{
		return $leftExists === $rightExists && (!$leftExists || $left === $right);
	}
}
