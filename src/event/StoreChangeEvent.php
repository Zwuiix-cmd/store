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
 * Fired before a value is changed and can cancel the mutation.
 */
final class StoreChangeEvent extends StoreEvent implements Cancellable
{
	use CancellableTrait;

	public function __construct(
		Store           $store,
		private ?string $key,
		private mixed   $oldValue,
		private mixed   $newValue,
		private string  $operation,
	) {
		parent::__construct($store);
	}

	/** @return string|null Changed path, or null for a complete replacement. */
	public function key() : ?string
	{
		return $this->key;
	}

	/** @return mixed Value before the operation. */
	public function oldValue() : mixed
	{
		return $this->oldValue;
	}

	/** @return mixed Value after the operation. */
	public function newValue() : mixed
	{
		return $this->newValue;
	}

	/** @return string Operation name: set, remove or replace. */
	public function operation() : string
	{
		return $this->operation;
	}
}
