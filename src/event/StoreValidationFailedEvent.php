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
use zwuiix\store\validation\StoreSchema;
use zwuiix\store\validation\StoreValidationResult;

/**
 * Fired when validation produces one or more errors.
 */
final class StoreValidationFailedEvent extends StoreEvent
{
	public function __construct(
		Store $store,
		private StoreSchema $schema,
		private StoreValidationResult $result,
	) {
		parent::__construct($store);
	}

	/** @return StoreSchema Schema that rejected the values. */
	public function schema() : StoreSchema
	{
		return $this->schema;
	}

	/** @return StoreValidationResult Validation errors and warnings. */
	public function result() : StoreValidationResult
	{
		return $this->result;
	}
}
