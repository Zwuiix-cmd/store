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

use JsonException;
use zwuiix\store\exception\StoreException;
use zwuiix\store\StoreCodecInterface;

use function in_array;
use function is_array;
use function json_decode;
use function json_encode;
use function strlen;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Encodes and decodes JSON and JSONC Store documents.
 */
final class JsonCodec implements StoreCodecInterface
{
	/**
	 * Decodes JSON or JSONC content into a Store value tree.
	 *
	 * @param string      $contents   JSON document.
	 * @param string|null $passphrase Unused by the JSON codec.
	 * @return array<string, mixed> Decoded Store values.
	 * @throws StoreException       If the JSON document is invalid or has a non-object root.
	 */
	public function decode(string $contents, ?string $passphrase = null) : array
	{
		try {
			$value = json_decode($this->stripTrailingCommas($this->stripComments($contents)), true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new StoreException('Invalid JSON store: ' . $exception->getMessage(), 0, $exception);
		}
		if (!is_array($value)) {
			throw new StoreException('A store document must contain an object at its root.');
		}

		return $value;
	}

	/**
	 * Encodes Store values as pretty-printed JSON.
	 *
	 * @param array<string, mixed> $data       Values to encode.
	 * @param string|null          $passphrase Unused by the JSON codec.
	 * @return string               JSON document.
	 * @throws StoreException       If the values cannot be encoded as JSON.
	 */
	public function encode(array $data, ?string $passphrase = null) : string
	{
		try {
			return json_encode(
				$data,
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
			) . "\n";
		} catch (JsonException $exception) {
			throw new StoreException('Unable to encode JSON store: ' . $exception->getMessage(), 0, $exception);
		}
	}

	private function stripComments(string $contents) : string
	{
		$output = '';
		$quote = null;
		$escaped = false;
		$length = strlen($contents);

		for ($index = 0; $index < $length; $index++) {
			$character = $contents[$index];
			$next = $contents[$index + 1] ?? '';

			if ($quote !== null) {
				$output .= $character;
				if ($escaped) {
					$escaped = false;
				} elseif ($character === '\\') {
					$escaped = true;
				} elseif ($character === $quote) {
					$quote = null;
				}

				continue;
			}

			if ($character === '"') {
				$quote = $character;
				$output .= $character;
			} elseif ($character === '#' || ($character === '/' && $next === '/')) {
				while ($index < $length && $contents[$index] !== "\n") {
					$index++;
				}

				$output .= "\n";
			} elseif ($character === '/' && $next === '*') {
				$index += 2;
				while ($index < $length && !($contents[$index] === '*' && ($contents[$index + 1] ?? '') === '/')) {
					$index++;
				}

				$index++;
			} else {
				$output .= $character;
			}
		}

		return $output;
	}

	/** Removes JSONC trailing commas without touching commas inside strings. */
	private function stripTrailingCommas(string $contents) : string
	{
		$output = '';
		$quote = false;
		$escaped = false;
		$length = strlen($contents);
		for ($index = 0; $index < $length; $index++) {
			$character = $contents[$index];
			if ($quote) {
				$output .= $character;
				if ($escaped) {
					$escaped = false;
				} elseif ($character === '\\') {
					$escaped = true;
				} elseif ($character === '"') {
					$quote = false;
				}
				continue;
			}
			if ($character === '"') {
				$quote = true;
				$output .= $character;
				continue;
			}
			if ($character === ',') {
				$lookahead = $index + 1;
				while ($lookahead < $length && in_array($contents[$lookahead], [" ", "\t", "\r", "\n"], true)) {
					$lookahead++;
				}
				if (($contents[$lookahead] ?? '') === '}' || ($contents[$lookahead] ?? '') === ']') {
					continue;
				}
			}
			$output .= $character;
		}

		return $output;
	}
}
