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
 * Immutable in-memory checkpoint for a Store value tree.
 */
final class StoreSnapshot
{
	/**
	 * @param array<string,mixed> $values Values captured at snapshot time.
	 */
	public function __construct(
		private array $values,
		private int   $revision,
	) {
	}

	/** @return array<string,mixed> Captured values. */
	public function values() : array
	{
		return $this->values;
	}

	/** @return int Store revision at capture time. */
	public function revision() : int
	{
		return $this->revision;
	}
}
