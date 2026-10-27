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
 * Fired when a Store operation fails.
 */
final class StoreErrorEvent extends StoreEvent
{
	public function __construct(
		Store              $store,
		private string     $operation,
		private \Throwable $exception,
	) {
		parent::__construct($store);
	}

	/** @return string Failed operation name. */
	public function operation() : string
	{
		return $this->operation;
	}

	/** @return \Throwable Failure that was raised. */
	public function exception() : \Throwable
	{
		return $this->exception;
	}
}
