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

namespace zwuiix\store\data;

use zwuiix\store\exception\StoreException;
use function array_filter;
use function array_map;
use function array_values;
use function floatval;
use function in_array;
use function intval;
use function is_string;
use function json_decode;
use function preg_match;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function stripcslashes;
use function strlen;
use function strtolower;
use function substr;
use function trim;

/**
 * Parses scalar, list and inline-table values shared by text codecs.
 */
final class ValueParser
{
	private function __construct()
	{
	}

	/**
	 * Parses a scalar or a structured textual value.
	 *
	 * @param string $value Textual value to parse.
	 * @return mixed          A string, integer, float, boolean, null, list or map.
	 * @throws StoreException If an inline table is malformed.
	 */
	public static function parse(string $value) : mixed
	{
		$value = trim($value);
		if ($value === '') {
			return '';
		}
		if (self::isQuoted($value, "'")) {
			return substr($value, 1, -1);
		}

		if (self::isQuoted($value, '"')) {
			$decoded = json_decode($value, true);
			if (is_string($decoded)) {
				return $decoded;
			}

			return stripcslashes(substr($value, 1, -1));
		}

		$lower = strtolower($value);
		if (in_array($lower, ['true', 'on', 'yes'], true)) {
			return true;
		}
		if (in_array($lower, ['false', 'off', 'no'], true)) {
			return false;
		}
		if (in_array($lower, ['null', 'none'], true)) {
			return null;
		}
		if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
			return self::parseList(substr($value, 1, -1));
		}
		if (str_starts_with($value, '{') && str_ends_with($value, '}')) {
			return self::parseTable(substr($value, 1, -1));
		}
		if (preg_match('/^[+-]?\d+$/', $value) === 1) {
			return intval($value);
		}
		if (preg_match('/^[+-]?(?:\d+\.\d*|\d*\.\d+)(?:[eE][+-]?\d+)?$/', $value) === 1) {
			return floatval($value);
		}

		return $value;
	}

	/**
	 * @return list<mixed>
	 */
	private static function parseList(string $value) : array
	{
		if (trim($value) === '') {
			return [];
		}

		return array_map(static fn(string $part) : mixed => self::parse($part), self::split($value));
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function parseTable(string $value) : array
	{
		$table = [];
		foreach (self::split($value) as $part) {
			$separator = self::findSeparator($part, '=');
			if ($separator === null) {
				throw new StoreException("Invalid inline table entry '{$part}'.");
			}

			$key = trim(substr($part, 0, $separator));
			if ($key === '') {
				throw new StoreException('Inline table keys cannot be empty.');
			}

			$table[trim($key, " \t\"'")] = self::parse(substr($part, $separator + 1));
		}

		return $table;
	}

	/**
	 * Splits a comma-separated value while respecting nested structures and quotes.
	 *
	 * @param string $value Comma-separated value to split.
	 * @return list<string> Non-empty trimmed parts.
	 */
	public static function split(string $value) : array
	{
		$parts = [];
		$start = 0;
		$depth = 0;
		$quote = null;
		$escaped = false;
		$length = strlen($value);

		for ($index = 0; $index < $length; $index++) {
			$character = $value[$index];
			if ($quote !== null) {
				if ($escaped) {
					$escaped = false;
				} elseif ($character === '\\' && $quote === '"') {
					$escaped = true;
				} elseif ($character === $quote) {
					$quote = null;
				}

				continue;
			}

			if ($character === '"' || $character === "'") {
				$quote = $character;
			} elseif ($character === '[' || $character === '{') {
				$depth++;
			} elseif ($character === ']' || $character === '}') {
				$depth--;
			} elseif ($character === ',' && $depth === 0) {
				$parts[] = trim(substr($value, $start, $index - $start));
				$start = $index + 1;
			}
		}

		$parts[] = trim(substr($value, $start));

		return array_values(array_filter($parts, static fn(string $part) : bool => $part !== ''));
	}

	/**
	 * Finds a separator outside nested structures and quoted strings.
	 *
	 * @param string $value     Text to inspect.
	 * @param string $separator Single-character separator to find.
	 * @return int|null Zero-based separator position, or null when absent.
	 */
	public static function findSeparator(string $value, string $separator) : ?int
	{
		$quote = null;
		$depth = 0;
		$escaped = false;
		$length = strlen($value);

		for ($index = 0; $index < $length; $index++) {
			$character = $value[$index];
			if ($quote !== null) {
				if ($escaped) {
					$escaped = false;
				} elseif ($character === '\\' && $quote === '"') {
					$escaped = true;
				} elseif ($character === $quote) {
					$quote = null;
				}

				continue;
			}

			if ($character === '"' || $character === "'") {
				$quote = $character;
			} elseif ($character === '[' || $character === '{') {
				$depth++;
			} elseif ($character === ']' || $character === '}') {
				$depth--;
			} elseif ($character === $separator && $depth === 0) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Removes comments while preserving comment characters inside quoted values.
	 *
	 * @param string $line       Text line to process.
	 * @param string $characters Comment characters to recognize.
	 * @return string The line without its trailing comment.
	 */
	public static function stripComment(string $line, string $characters) : string
	{
		$quote = null;
		$escaped = false;
		$length = strlen($line);

		for ($index = 0; $index < $length; $index++) {
			$character = $line[$index];
			if ($quote !== null) {
				if ($escaped) {
					$escaped = false;
				} elseif ($character === '\\' && $quote === '"') {
					$escaped = true;
				} elseif ($character === $quote) {
					$quote = null;
				}

				continue;
			}

			if ($character === '"' || $character === "'") {
				$quote = $character;
			} elseif (str_contains($characters, $character)) {
				return rtrim(substr($line, 0, $index));
			}
		}

		return rtrim($line);
	}

	private static function isQuoted(string $value, string $quote) : bool
	{
		return strlen($value) >= 2 && str_starts_with($value, $quote) && str_ends_with($value, $quote);
	}
}
