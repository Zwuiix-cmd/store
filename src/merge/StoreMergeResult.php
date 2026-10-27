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

use zwuiix\store\data\StoreValueTree;
/**
 * Result of comparing local and external Store values against one baseline.
 */
final class StoreMergeResult
{
	/**
	 * @param array<string, mixed>     $values    Merged values.
	 * @param list<StoreMergeConflict> $conflicts Paths that need a decision.
	 */
	public function __construct(
		private array $values,
		private array $conflicts,
		private bool  $applied = false,
	) {
	}

	/** @return array<string, mixed> Values produced by the merge. */
	public function values() : array
	{
		return $this->values;
	}

	/** @return list<StoreMergeConflict> Conflicting paths. */
	public function conflicts() : array
	{
		return $this->conflicts;
	}

	/** @return bool Whether the merge contains no conflicts. */
	public function isClean() : bool
	{
		return $this->conflicts === [];
	}

	/** @return bool Whether the merged values were applied to a Store. */
	public function wasApplied() : bool
	{
		return $this->applied;
	}

	/**
	 * Returns the merged values with every conflict path replaced by its local value.
	 *
	 * @return array<string, mixed> Locally preferred values.
	 */
	public function preferLocal() : array
	{
		$values = $this->values;
		foreach ($this->conflicts as $conflict) {
			StoreValueTree::write($values, $conflict->path(), $conflict->localExists(), $conflict->localValue());
		}

		return $values;
	}

	/**
	 * Returns the merged values with every conflict path replaced by the external value.
	 *
	 * @return array<string, mixed> Externally preferred values.
	 */
	public function preferRemote() : array
	{
		$values = $this->values;
		foreach ($this->conflicts as $conflict) {
			StoreValueTree::write($values, $conflict->path(), $conflict->remoteExists(), $conflict->remoteValue());
		}

		return $values;
	}
}
