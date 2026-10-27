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

use zwuiix\store\exception\StoreException;

/**
 * Encodes and decodes one Store serialization format.
 */
interface StoreCodecInterface
{
	/**
	 * Decodes serialized content into a store map.
	 *
	 * @param string      $contents   Serialized document.
	 * @param string|null $passphrase Optional secret for encrypted codecs.
	 * @return array<string, mixed>
	 * @throws StoreException       If the document is malformed or cannot be decrypted.
	 */
	public function decode(string $contents, ?string $passphrase = null) : array;

	/**
	 * Encodes a store map into serialized content.
	 *
	 * @param array<string, mixed> $data       Values to encode.
	 * @param string|null          $passphrase Optional secret for encrypted codecs.
	 * @return string               Serialized document.
	 * @throws StoreException       If the values cannot be encoded or encrypted.
	 */
	public function encode(array $data, ?string $passphrase = null) : string;
}
