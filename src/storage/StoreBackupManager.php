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

namespace zwuiix\store\storage;

use zwuiix\store\exception\StoreException;

use function array_reverse;
use function array_slice;
use function array_values;
use function chmod;
use function copy;
use function date;
use function dirname;
use function glob;
use function is_file;
use function microtime;
use function rename;
use function sort;
use function sprintf;
use function tempnam;

use function unlink;
use const GLOB_NOSORT;
use const SORT_STRING;

/**
 * Creates, lists and restores Store backups.
 */
final class StoreBackupManager
{
	private function __construct()
	{
	}

	/**
	 * Creates a backup of an existing Store file and removes old versions.
	 *
	 * @param string            $path   Store path.
	 * @param StoreBackupPolicy $policy Backup policy.
	 * @return string|null       Created backup path, or null when disabled/missing.
	 * @throws StoreException    If the existing file cannot be copied.
	 */
	public static function create(string $path, StoreBackupPolicy $policy) : ?string
	{
		if (!$policy->enabled() || !is_file($path)) {
			return null;
		}

		$backup = sprintf('%s%s.%s.%06d', $path, $policy->suffix(), date('Ymd-His'), (int) (microtime(true) * 1_000_000) % 1_000_000);
		if (!copy($path, $backup) || !chmod($backup, $policy->mode())) {
			@unlink($backup);
			throw new StoreException("Unable to create Store backup '{$backup}'.");
		}

		self::prune($path, $policy);

		return $backup;
	}

	/**
	 * Returns existing backups from newest to oldest.
	 *
	 * @param string            $path   Store path.
	 * @param StoreBackupPolicy $policy Backup policy.
	 * @return list<string>      Backup paths.
	 */
	public static function list(string $path, StoreBackupPolicy $policy) : array
	{
		$files = glob($path . $policy->suffix() . '.*', GLOB_NOSORT);
		if ($files === false) {
			return [];
		}

		sort($files, SORT_STRING);

		return array_values(array_reverse($files));
	}

	/**
	 * Restores the newest backup over the Store path atomically.
	 *
	 * @param string            $path   Store path.
	 * @param StoreBackupPolicy $policy Backup policy.
	 * @return string            Restored backup path.
	 * @throws StoreException    If no backup exists or restoration fails.
	 */
	public static function restoreLatest(string $path, StoreBackupPolicy $policy) : string
	{
		$backup = self::list($path, $policy)[0] ?? null;
		if ($backup === null) {
			throw new StoreException("No Store backup exists for '{$path}'.");
		}

		self::restore($backup, $path, $policy);

		return $backup;
	}

	/**
	 * Restores one known backup over the Store path atomically.
	 *
	 * @param string            $backup Backup path.
	 * @param string            $path   Store path.
	 * @param StoreBackupPolicy $policy Backup policy.
	 * @return void
	 * @throws StoreException If the backup cannot be restored.
	 */
	public static function restore(string $backup, string $path, StoreBackupPolicy $policy) : void
	{
		if (!is_file($backup)) {
			throw new StoreException("Store backup '{$backup}' does not exist.");
		}

		$temporary = tempnam(dirname($path), '.store-recovery-');
		if ($temporary === false || !copy($backup, $temporary) || !rename($temporary, $path) || !chmod($path, $policy->mode())) {
			if ($temporary !== false && is_file($temporary)) {
				@unlink($temporary);
			}

			throw new StoreException("Unable to restore Store backup '{$backup}'.");
		}
	}

	private static function prune(string $path, StoreBackupPolicy $policy) : void
	{
		$backups = self::list($path, $policy);
		foreach (array_slice($backups, $policy->maxBackups()) as $backup) {
			@unlink($backup);
		}
	}
}
