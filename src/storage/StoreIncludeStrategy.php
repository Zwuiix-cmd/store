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

namespace zwuiix\store\storage;

use function strtolower;

/**
 * Merge policy for one included Store document.
 */
enum StoreIncludeStrategy : string
{
	/**
	 * The root document or later layer wins recursively.
	 */
	case RootWins = 'root_wins';

	/**
	 * The included document wins recursively over earlier layers.
	 */
	case IncludeWins = 'include_wins';

	/**
	 * The included document replaces the accumulated layer.
	 */
	case Replace = 'replace';

	public static function fromValue(string $value) : self
	{
		return match (strtolower($value)) {
			'root', 'root_wins', 'deep' => self::RootWins,
			'include', 'include_wins' => self::IncludeWins,
			'replace' => self::Replace,
			default => throw new \InvalidArgumentException("Unknown Store include strategy '{$value}'."),
		};
	}
}
