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

namespace zwuiix\store;

use function pathinfo;
use function str_starts_with;
use function strtolower;
use const PATHINFO_BASENAME;
use const PATHINFO_EXTENSION;

/**
 * Supported Store serialization formats.
 */
enum StoreFormat : string
{
	case Json = 'json';
	case Env = 'env';
	case Ini = 'ini';
	case Properties = 'properties';
	case Toml = 'toml';
	case Yaml = 'yaml';
	case Xml = 'xml';
	case Encrypted = 'encrypted';

	/**
	 * Detects a format from a file extension.
	 *
	 * @param string $path File path whose extension should be inspected.
	 * @return self   The detected format, defaulting to JSON.
	 */
	public static function fromPath(string $path) : self
	{
		$basename = strtolower(pathinfo($path, PATHINFO_BASENAME));
		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		if ($basename === '.env' || str_starts_with($basename, '.env.')) {
			return self::Env;
		}

		return match ($extension) {
			'env' => self::Env,
			'ini' => self::Ini,
			'properties', 'props' => self::Properties,
			'toml' => self::Toml,
			'yaml', 'yml' => self::Yaml,
			'xml' => self::Xml,
			'enc', 'encrypted', 'store' => self::Encrypted,
			default => self::Json,
		};
	}
}
