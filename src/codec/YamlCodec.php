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

namespace zwuiix\store\codec;

use zwuiix\store\exception\StoreException;
use zwuiix\store\StoreCodecInterface;

use function function_exists;
use function is_array;
use function is_string;
use function yaml_emit;
use function yaml_parse;

/**
 * Encodes and decodes YAML Store documents.
 */
final class YamlCodec implements StoreCodecInterface
{
	/**
	 * Decodes a YAML document into a Store value tree.
	 *
	 * @param string      $contents   YAML document.
	 * @param string|null $passphrase Unused by the YAML codec.
	 * @return array<string, mixed> Decoded Store values.
	 * @throws StoreException       If YAML support is unavailable or the document is invalid.
	 */
	public function decode(string $contents, ?string $passphrase = null) : array
	{
		if (!function_exists('yaml_parse')) {
			throw new StoreException('YAML support requires the ext-yaml PHP extension.');
		}

		$value = yaml_parse($contents);
		if (!is_array($value)) {
			throw new StoreException('A YAML store document must contain a mapping at its root.');
		}

		return $value;
	}

	/**
	 * Encodes Store values as a YAML document.
	 *
	 * @param array<string, mixed> $data       Values to encode.
	 * @param string|null          $passphrase Unused by the YAML codec.
	 * @return string               YAML document.
	 * @throws StoreException       If YAML support is unavailable or a value cannot be encoded.
	 */
	public function encode(array $data, ?string $passphrase = null) : string
	{
		if (!function_exists('yaml_emit')) {
			throw new StoreException('YAML support requires the ext-yaml PHP extension.');
		}

		$value = yaml_emit($data);

		return $this->requireString($value);
	}

	private function requireString(mixed $value) : string
	{
		if (!is_string($value)) {
			throw new StoreException('Unable to encode YAML store.');
		}

		return $value;
	}
}
