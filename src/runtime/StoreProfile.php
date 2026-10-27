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
use zwuiix\store\Store;
use zwuiix\store\StoreFormat;

use function array_replace_recursive;
use function array_reverse;
use function is_file;
use function pathinfo;
use function preg_match;
use const DIRECTORY_SEPARATOR;
use const PATHINFO_DIRNAME;
use const PATHINFO_EXTENSION;
use const PATHINFO_FILENAME;

/**
 * Composes a base Store with an optional profile overlay.
 *
 * For config.yml and the "production" profile, both config.production.yml and
 * profiles/production/config.yml are accepted. The latter takes precedence.
 */
final class StoreProfile
{
	private function __construct()
	{
	}

	/**
	 * Opens a base file and applies a profile file when it exists.
	 *
	 * @param string               $baseFile   Base configuration file.
	 * @param string               $profile    Profile name.
	 * @param StoreFormat|int|null $format     Optional explicit format.
	 * @param array<string,mixed>  $defaults   Defaults for the base Store.
	 * @param string|null          $passphrase Secret passed to supported codecs.
	 * @return Store                Composed Store.
	 */
	public static function open(
		string               $baseFile,
		string               $profile,
		StoreFormat|int|null $format = null,
		array                $defaults = [],
		?string              $passphrase = null,
	) : Store {
		$base = Store::open($baseFile, $format, $defaults, $passphrase);
		$profileFile = self::findFile($baseFile, $profile);
		if ($profileFile === null) {
			return $base;
		}

		$overlay = Store::open($profileFile, $format, [], $passphrase);

		return Store::fromArray(
			array_replace_recursive($base->all(), $overlay->all()),
			$base->format(),
			$base->path(),
			$passphrase,
		);
	}

	/**
	 * Recursively merges configuration layers from left to right.
	 *
	 * @param array<string,mixed> ...$layers Layers ordered from lowest to highest priority.
	 * @return array<string,mixed> Merged configuration.
	 */
	public static function merge(array ...$layers) : array
	{
		$result = [];
		foreach ($layers as $layer) {
			$result = array_replace_recursive($result, $layer);
		}

		return $result;
	}

	/**
	 * Returns the profile file that exists for a base file.
	 *
	 * @param string $baseFile Base file.
	 * @param string $profile  Profile name.
	 * @return string|null    Existing profile path.
	 * @throws StoreException If the profile name is invalid.
	 */
	public static function findFile(string $baseFile, string $profile) : ?string
	{
		if (preg_match('/^[A-Za-z0-9_.-]+$/', $profile) !== 1) {
			throw new StoreException("Invalid Store profile name '{$profile}'.");
		}

		$directory = pathinfo($baseFile, PATHINFO_DIRNAME);
		$filename = pathinfo($baseFile, PATHINFO_FILENAME);
		$extension = pathinfo($baseFile, PATHINFO_EXTENSION);
		$suffix = $extension === '' ? '' : '.' . $extension;
		$candidates = [
			$directory . DIRECTORY_SEPARATOR . $filename . '.' . $profile . $suffix,
			$directory . DIRECTORY_SEPARATOR . 'profiles' . DIRECTORY_SEPARATOR . $profile . DIRECTORY_SEPARATOR . $filename . $suffix,
		];

		foreach (array_reverse($candidates) as $candidate) {
			if (is_file($candidate)) {
				return $candidate;
			}
		}

		return null;
	}
}
