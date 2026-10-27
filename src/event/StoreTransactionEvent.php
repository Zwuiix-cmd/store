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

use zwuiix\store\Store;

/**
 * Fired after a transaction is committed or rolled back.
 */
final class StoreTransactionEvent extends StoreEvent
{
	public function __construct(
		Store        $store,
		private bool $committed,
		private bool $changed,
		private int  $depth,
	) {
		parent::__construct($store);
	}

	/** @return bool Whether the transaction was committed. */
	public function committed() : bool
	{
		return $this->committed;
	}

	/** @return bool Whether the transaction changed values. */
	public function changed() : bool
	{
		return $this->changed;
	}

	/** @return int Remaining transaction depth. */
	public function depth() : int
	{
		return $this->depth;
	}
}
