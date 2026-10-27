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

use InvalidArgumentException;
use function str_contains;
use const DIRECTORY_SEPARATOR;

/**
 * Controls versioned backups created before an atomic Store replacement.
 */
final class StoreBackupPolicy
{
	/**
	 * @param bool   $enabled    Whether backups should be created.
	 * @param int    $maxBackups Maximum number of backup files to retain.
	 * @param int    $mode       Unix permissions applied to backup files.
	 * @param string $suffix     Suffix placed between the Store path and timestamp.
	 */
	public function __construct(
		private bool   $enabled = true,
		private int    $maxBackups = 3,
		private int    $mode = 0640,
		private string $suffix = '.backup',
	) {
		if ($maxBackups < 1) {
			throw new InvalidArgumentException('A Store backup policy must retain at least one backup.');
		}
		if ($suffix === '' || str_contains($suffix, DIRECTORY_SEPARATOR)) {
			throw new InvalidArgumentException('A Store backup suffix must be a single filename fragment.');
		}
	}

	/**
	 * Disables backup creation.
	 *
	 * @return self Disabled policy.
	 */
	public static function disabled() : self
	{
		return new self(false);
	}

	/**
	 * Creates an enabled policy.
	 *
	 * @param int $maxBackups Number of versions to retain.
	 * @param int $mode       Unix permissions applied to backups.
	 * @return self Enabled policy.
	 */
	public static function keep(int $maxBackups = 3, int $mode = 0640) : self
	{
		return new self(true, $maxBackups, $mode);
	}

	/** @return bool Whether backups are enabled. */
	public function enabled() : bool
	{
		return $this->enabled;
	}

	/** @return int Maximum number of backups to retain. */
	public function maxBackups() : int
	{
		return $this->maxBackups;
	}

	/** @return int Unix permissions applied to backup files. */
	public function mode() : int
	{
		return $this->mode;
	}

	/** @return string Filename suffix used for backups. */
	public function suffix() : string
	{
		return $this->suffix;
	}
}
