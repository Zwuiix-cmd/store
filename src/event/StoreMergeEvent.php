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

namespace zwuiix\store\event;

use zwuiix\store\merge\StoreMergeResult;
use zwuiix\store\Store;

/**
 * Fired after a three-way merge has been calculated.
 */
final class StoreMergeEvent extends StoreEvent
{
	public function __construct(
		Store             $store,
		private StoreMergeResult $result,
	) {
		parent::__construct($store);
	}

	/** @return StoreMergeResult Merge result. */
	public function result() : StoreMergeResult
	{
		return $this->result;
	}
}
