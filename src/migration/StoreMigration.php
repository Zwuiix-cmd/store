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

namespace zwuiix\store\migration;

use zwuiix\store\Store;

/**
 * One forward-only Store data migration.
 */
interface StoreMigration
{
	/**
	 * Returns the unique version reached by this migration.
	 *
	 * @return int Positive migration version.
	 */
	public function version() : int;

	/**
	 * Applies this migration to a Store.
	 *
	 * @param Store $store Store being upgraded.
	 * @return void
	 */
	public function up(Store $store) : void;
}
