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

namespace zwuiix\store\validation;

use zwuiix\store\security\StoreSecret;

use function array_is_list;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/**
 * Value types accepted by StoreSchema rules.
 */
enum StoreValueType : string
{
	case Any = 'any';
	case String = 'string';
	case Integer = 'integer';
	case Float = 'float';
	case Boolean = 'boolean';
	case ArrayValue = 'array';
	case List = 'list';
	case Secret = 'secret';

	/**
	 * Checks whether a value matches this type.
	 *
	 * @param mixed $value Value to inspect.
	 * @return bool  True when the value matches this type.
	 */
	public function matches(mixed $value) : bool
	{
		return match ($this) {
			self::Any => true,
			self::String => is_string($value),
			self::Integer => is_int($value),
			self::Float => is_float($value) || is_int($value),
			self::Boolean => is_bool($value),
			self::ArrayValue => is_array($value),
			self::List => is_array($value) && array_is_list($value),
			self::Secret => $value instanceof StoreSecret || is_string($value),
		};
	}
}
