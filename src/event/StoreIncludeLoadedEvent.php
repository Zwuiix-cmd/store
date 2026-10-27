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
 * Fired after one file in an include graph has been loaded.
 */
final class StoreIncludeLoadedEvent extends StoreEvent
{
	public function __construct(
		Store $store,
		private string $includedPath,
		private ?string $parentPath,
		private int $depth,
	) {
		parent::__construct($store);
	}

	/** @return string Loaded include path. */
	public function includedPath() : string
	{
		return $this->includedPath;
	}

	/** @return string|null Parent include path. */
	public function parentPath() : ?string
	{
		return $this->parentPath;
	}

	/** @return int Include depth, starting at one for a direct include. */
	public function depth() : int
	{
		return $this->depth;
	}
}
