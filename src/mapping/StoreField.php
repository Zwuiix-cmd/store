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

namespace zwuiix\store\mapping;

use Attribute;

/**
 * Overrides the Store path used by a mapped constructor parameter or property.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class StoreField
{
	/**
	 * @param string|null $path      Relative path, or the reflected name when null.
	 * @param string|null $itemClass Class used for array items.
	 */
	public function __construct(
		public readonly ?string $path = null,
		public readonly ?string $itemClass = null,
	) {
	}
}
