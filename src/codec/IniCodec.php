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
use function array_map;
use function array_merge;
use function array_values;
use function count;
use function end;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use function preg_match;
use function preg_replace;
use function preg_split;
use function range;
use function rtrim;
use function str_ends_with;
use function str_starts_with;
use function substr;
use function trim;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Encodes and decodes INI Store documents.
 */
final class IniCodec implements StoreCodecInterface
{
	/**
	 * Decodes an INI document into a Store value tree.
	 *
	 * @param string      $contents   INI document.
	 * @param string|null $passphrase Unused by the INI codec.
	 * @return array<string, mixed> Decoded Store values.
	 * @throws StoreException       If the document contains invalid INI data.
	 */
	public function decode(string $contents, ?string $passphrase = null) : array
	{
		$contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
		$data = [];
		$section = [];

		foreach (preg_split('/\R/', $contents) ?: [] as $lineNumber => $line) {
			$line = trim(ValueParser::stripComment($line, ';#'));
			if ($line === '') {
				continue;
			}

			if (str_starts_with($line, '[') && str_ends_with($line, ']')) {
				$section = $this->parsePath(substr($line, 1, -1), $lineNumber + 1);
				PathAccessor::reference($data, $section);

				continue;
			}

			$separator = ValueParser::findSeparator($line, '=');
			if ($separator === null) {
				throw new StoreException('Invalid INI entry on line ' . ($lineNumber + 1) . '.');
			}

			$key = trim(substr($line, 0, $separator));
			if ($key === '') {
				throw new StoreException('INI keys cannot be empty on line ' . ($lineNumber + 1) . '.');
			}

			$value = ValueParser::parse(substr($line, $separator + 1));
			if (preg_match('/^(.+)\[([^]]*)]$/', $key, $matches) === 1) {
				$path = array_merge($section, $this->parsePath($matches[1], $lineNumber + 1));
				$pathString = implode('.', $path);
				if ($matches[2] === '') {
					$found = false;
					$existing = PathAccessor::get($data, $pathString, $found);
					if ($found && !is_array($existing)) {
						throw new StoreException("INI array key '{$key}' is already a scalar on line " . ($lineNumber + 1) . '.');
					}

					$existing ??= [];
					$existing[] = $value;
					PathAccessor::set($data, $pathString, $existing);
				} else {
					PathAccessor::set($data, implode('.', [...$path, $matches[2]]), $value);
				}
			} else {
				$path = array_merge($section, $this->parsePath($key, $lineNumber + 1));
				PathAccessor::set($data, implode('.', $path), $value);
			}
		}

		return $data;
	}

	/**
	 * Encodes Store values as an INI document.
	 *
	 * @param array<string, mixed> $data       Values to encode.
	 * @param string|null          $passphrase Unused by the INI codec.
	 * @return string               INI document.
	 * @throws StoreException       If a value cannot be represented as INI.
	 */
	public function encode(array $data, ?string $passphrase = null) : string
	{
		$lines = [];
		$this->appendSection($data, [], $lines);

		return rtrim(implode("\n", $lines)) . "\n";
	}

	/**
	 * @param list<string> $path
	 * @param list<string> $lines
	 */
	private function appendSection(array $data, array $path, array &$lines) : void
	{
		$scalars = [];
		$sections = [];
		foreach ($data as $key => $value) {
			if (is_array($value) && !$this->isList($value)) {
				$sections[(string) $key] = $value;
			} else {
				$scalars[(string) $key] = $value;
			}
		}

		if ($path !== []) {
			$lines[] = '[' . implode('.', $path) . ']';
		}

		foreach ($scalars as $key => $value) {
			$lines[] = $key . ' = ' . $this->encodeValue($value);
		}

		foreach ($sections as $key => $section) {
			if ($lines !== [] && end($lines) !== '') {
				$lines[] = '';
			}

			$this->appendSection($section, [...$path, $key], $lines);
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
			return '[' . implode(', ', array_map(fn(mixed $item) : string => $this->encodeValue($item), $value)) . ']';
		}

		throw new StoreException('INI values must be scalar values or lists.');
	}

	/**
	 * @return list<string>
	 */
	private function parsePath(string $path, int $line) : array
	{
		$segments = array_map(static fn(string $part) : string => trim($part, " \t\"'"), explode('.', trim($path)));
		if (in_array('', $segments, true)) {
			throw new StoreException('Invalid INI section or key on line ' . $line . '.');
		}

		return array_values($segments);
	}

	private function isList(array $value) : bool
	{
		return $value === [] || array_keys($value) === range(0, count($value) - 1);
	}
}
