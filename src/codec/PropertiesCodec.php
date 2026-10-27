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

use zwuiix\store\data\PathAccessor;
use zwuiix\store\data\ValueParser;
use zwuiix\store\exception\StoreException;
use zwuiix\store\StoreCodecInterface;

use function array_keys;
use function count;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use function preg_match;
use function preg_split;
use function range;
use function rtrim;
use function substr;
use function trim;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PREG_OFFSET_CAPTURE;

/**
 * Encodes and decodes Java properties Store documents.
 */
final class PropertiesCodec implements StoreCodecInterface
{
	/**
	 * Decodes a Java properties document into a Store value tree.
	 *
	 * @param string      $contents   Properties document.
	 * @param string|null $passphrase Unused by the properties codec.
	 * @return array<string, mixed> Decoded Store values.
	 * @throws StoreException       If a property entry is malformed.
	 */
	public function decode(string $contents, ?string $passphrase = null) : array
	{
		$data = [];
		foreach (preg_split('/\R/', $contents) ?: [] as $lineNumber => $line) {
			$line = trim(ValueParser::stripComment($line, '#;!'));
			if ($line === '') {
				continue;
			}

			$separator = ValueParser::findSeparator($line, '=') ?? ValueParser::findSeparator($line, ':');
			if ($separator === null && preg_match('/\s+/', $line, $matches, PREG_OFFSET_CAPTURE) === 1) {
				$separator = $matches[0][1];
			}
			if ($separator === null) {
				throw new StoreException('Invalid properties entry on line ' . ($lineNumber + 1) . '.');
			}

			$key = trim(substr($line, 0, $separator));
			if ($key === '') {
				throw new StoreException('Properties keys cannot be empty on line ' . ($lineNumber + 1) . '.');
			}

			PathAccessor::set($data, $key, ValueParser::parse(substr($line, $separator + 1)));
		}

		return $data;
	}

	/**
	 * Encodes Store values as a Java properties document.
	 *
	 * @param array<string, mixed> $data       Values to encode.
	 * @param string|null          $passphrase Unused by the properties codec.
	 * @return string               Properties document.
	 * @throws StoreException       If a value cannot be represented as a property.
	 */
	public function encode(array $data, ?string $passphrase = null) : string
	{
		$lines = [];
		$this->append($data, '', $lines);

		return rtrim(implode("\n", $lines)) . "\n";
	}

	/** @param list<string> $lines */
	private function append(array $data, string $prefix, array &$lines) : void
	{
		foreach ($data as $key => $value) {
			$name = $prefix === '' ? (string) $key : $prefix . '.' . $key;
			if (is_array($value) && !$this->isList($value)) {
				$this->append($value, $name, $lines);
			} else {
				$lines[] = $name . ' = ' . $this->encodeValue($value);
			}
		}
	}

	private function encodeValue(mixed $value) : string
	{
		if (is_string($value)) {
			return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '""';
		}
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}
		if ($value === null) {
			return 'null';
		}
		if (is_int($value) || is_float($value)) {
			return (string) $value;
		}
		if (is_array($value)) {
			return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
		}

		throw new StoreException('Properties values must be scalar values or lists.');
	}

	private function isList(array $value) : bool
	{
		return $value === [] || array_keys($value) === range(0, count($value) - 1);
	}
}
