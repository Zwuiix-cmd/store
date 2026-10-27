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

/**
 * Immutable in-memory record of one Store mutation.
 */
final class StoreChangeRecord
{
	public function __construct(
		private ?string $path,
		private mixed   $oldValue,
		private mixed   $newValue,
		private string  $operation,
		private int     $revision,
		private float   $time,
	) {
	}

	/** @return string|null Changed path. */
	public function path() : ?string
	{
		return $this->path;
	}

	/** @return mixed Value before the change. */
	public function oldValue() : mixed
	{
		return $this->oldValue;
	}

	/** @return mixed Value after the change. */
	public function newValue() : mixed
	{
		return $this->newValue;
	}

	/** @return string Mutation type. */
	public function operation() : string
	{
		return $this->operation;
	}

	/** @return int Store revision after the change. */
	public function revision() : int
	{
		return $this->revision;
	}

	/** @return float Unix timestamp with microseconds. */
	public function time() : float
	{
		return $this->time;
	}
}
