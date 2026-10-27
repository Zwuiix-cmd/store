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
 * Fired after values have been hydrated from or serialized to a PHP object.
 */
final class StoreMappedEvent extends StoreEvent
{
	public function __construct(
		Store $store,
		private string $path,
		private string $class,
		private object $object,
		private string $operation,
	) {
		parent::__construct($store);
	}

	/** @return string Store path mapped to the object. */
	public function mappedPath() : string
	{
		return $this->path;
	}

	/** @return string Mapped object class. */
	public function className() : string
	{
		return $this->class;
	}

	/** @return object Hydrated or serialized object. */
	public function object() : object
	{
		return $this->object;
	}

	/** @return string Operation name: get or set. */
	public function operation() : string
	{
		return $this->operation;
	}
}
