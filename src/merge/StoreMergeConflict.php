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

namespace zwuiix\store\merge;

/**
 * Describes one path changed differently by the local and external values.
 */
final class StoreMergeConflict
{
	public function __construct(
		private string $path,
		private bool   $baseExists,
		private mixed  $baseValue,
		private bool   $localExists,
		private mixed  $localValue,
		private bool   $remoteExists,
		private mixed  $remoteValue,
	) {
	}

	/** @return string Conflicting dot-separated path. */
	public function path() : string
	{
		return $this->path;
	}

	/** @return bool Whether the baseline contained this path. */
	public function baseExists() : bool
	{
		return $this->baseExists;
	}

	/** @return mixed Baseline value. */
	public function baseValue() : mixed
	{
		return $this->baseValue;
	}

	/** @return bool Whether the local tree contains this path. */
	public function localExists() : bool
	{
		return $this->localExists;
	}

	/** @return mixed Local value. */
	public function localValue() : mixed
	{
		return $this->localValue;
	}

	/** @return bool Whether the external tree contains this path. */
	public function remoteExists() : bool
	{
		return $this->remoteExists;
	}

	/** @return mixed External value. */
	public function remoteValue() : mixed
	{
		return $this->remoteValue;
	}
}
