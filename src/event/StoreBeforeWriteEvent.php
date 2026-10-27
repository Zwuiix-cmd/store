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

use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use zwuiix\store\Store;

/**
 * Fired before a Store serializes and writes its current values.
 */
final class StoreBeforeWriteEvent extends StoreEvent implements Cancellable
{
	use CancellableTrait;

	public function __construct(
		Store $store,
		private bool $async,
	) {
		parent::__construct($store);
	}

	/** @return bool Whether the write uses the async worker. */
	public function isAsync() : bool
	{
		return $this->async;
	}
}
