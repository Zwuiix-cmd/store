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
use zwuiix\store\merge\StoreConflictPolicy;

/**
 * File permissions and locking policy used by Store writes.
 */
final class StoreFilePolicy
{
	public function __construct(
		private int                 $mode = 0640,
		private bool                $locking = true,
		private StoreConflictPolicy $conflict = StoreConflictPolicy::Reject,
		private float               $lockTimeout = 5.0,
		private int                 $lockPollMicros = 10_000,
		private bool                $readOnly = false,
	) {
		if ($lockTimeout < 0) {
			throw new InvalidArgumentException('A Store lock timeout cannot be negative.');
		}
		if ($lockPollMicros < 100) {
			throw new InvalidArgumentException('A Store lock poll interval must be at least 100 microseconds.');
		}
	}

	/**
	 * Creates a private policy suitable for encrypted documents.
	 *
	 * @return self Private file policy.
	 */
	public static function secure() : self
	{
		return new self(0600);
	}

	/**
	 * Creates a policy that allows reads but rejects every write operation.
	 *
	 * @return self Read-only policy.
	 */
	public static function readOnly() : self
	{
		return new self(readOnly: true);
	}

	/** @return int Unix permission mode. */
	public function mode() : int
	{
		return $this->mode;
	}

	/** @return bool Whether writes use an exclusive lock. */
	public function locking() : bool
	{
		return $this->locking;
	}

	/** @return StoreConflictPolicy Conflict handling mode. */
	public function conflict() : StoreConflictPolicy
	{
		return $this->conflict;
	}

	/** @return float Maximum time spent waiting for the lock. */
	public function lockTimeout() : float
	{
		return $this->lockTimeout;
	}

	/** @return int Delay between non-blocking lock attempts in microseconds. */
	public function lockPollMicros() : int
	{
		return $this->lockPollMicros;
	}

	/** @return bool Whether mutations and writes are forbidden. */
	public function isReadOnly() : bool
	{
		return $this->readOnly;
	}
}
