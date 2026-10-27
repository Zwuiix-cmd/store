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

use pocketmine\event\Event;
use zwuiix\store\Store;
use zwuiix\store\StoreFormat;

/**
 * Base class for events emitted by a Store.
 */
abstract class StoreEvent extends Event
{
	public function __construct(
		protected Store $store,
	) {
	}

	/** @return Store Store that emitted the event. */
	public function store() : Store
	{
		return $this->store;
	}

	/** @return string Store file path, or an empty string for memory stores. */
	public function path() : string
	{
		return $this->store->path();
	}

	/** @return StoreFormat Active serialization format. */
	public function format() : StoreFormat
	{
		return $this->store->format();
	}
}
