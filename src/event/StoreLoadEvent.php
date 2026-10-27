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
 * Fired after a Store has loaded or created its initial document.
 */
final class StoreLoadEvent extends StoreEvent
{
	public function __construct(
		Store        $store,
		private bool $created,
	) {
		parent::__construct($store);
	}

	/** @return bool Whether the document was created during this load. */
	public function created() : bool
	{
		return $this->created;
	}
}
