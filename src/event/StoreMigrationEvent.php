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
 * Fired before and after each registered Store migration.
 */
final class StoreMigrationEvent extends StoreEvent
{
	public function __construct(
		Store          $store,
		private int    $version,
		private string $phase,
	) {
		parent::__construct($store);
	}

	/** @return int Migration version being applied. */
	public function version() : int
	{
		return $this->version;
	}

	/** @return string Migration phase: before or after. */
	public function phase() : string
	{
		return $this->phase;
	}
}
