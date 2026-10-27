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
 * Fired after a versioned backup has been created.
 */
final class StoreBackupEvent extends StoreEvent
{
	public function __construct(
		Store          $store,
		private string $backupPath,
	) {
		parent::__construct($store);
	}

	/** @return string Created backup path. */
	public function backupPath() : string
	{
		return $this->backupPath;
	}
}
