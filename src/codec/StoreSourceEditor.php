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

use zwuiix\store\StoreFormat;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function count;
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

use function preg_match_all;
use function preg_quote;
use function preg_replace_callback;
use function preg_split;
use function rtrim;
use function str_starts_with;
use function strlen;
use function strpos;
use function strrpos;
use function substr;
use function trim;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PREG_OFFSET_CAPTURE;
use const PREG_SPLIT_DELIM_CAPTURE;

/**
 * Preserves comments and layout for safe scalar edits in line-based formats.
 */
final class StoreSourceEditor
{
	/**
	 * Applies simple scalar changes to the original source.
	 *
	 * A null result means that the change requires a complete codec rewrite.
	 * This conservative behavior keeps comments safe when keys are added,
	 * removed, duplicated or changed from a scalar to a collection.
	 *
	 * @param StoreFormat          $format Source format.
	 * @param string               $source Original source.
	 * @param array<string, mixed> $before Values represented by the source.
	 * @param array<string, mixed> $after  Current values.
	 * @return string|null          Edited source, or null when unsupported.
	 */
	public static function update(StoreFormat $format, string $source, array $before, array $after) : ?string
	{
		if ($format === StoreFormat::Json) {
			return self::updateJson($source, $before, $after);
		}
		if ($format === StoreFormat::Yaml) {
			return self::updateYaml($source, $before, $after);
		}
		if (!in_array($format, [StoreFormat::Env, StoreFormat::Ini, StoreFormat::Properties, StoreFormat::Toml], true)) {
			return null;
		}

		$set = [];
		$removed = [];
		self::diff($after, $before, '', $set, $removed);
		if ($removed !== [] || $set === []) {
			return null;
		}

		foreach ($set as $value) {
			if (is_array($value) || (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value) && $value !== null)) {
				return null;
			}
		}

		$lines = preg_split('/(\r\n|\n|\r)/', $source, -1, PREG_SPLIT_DELIM_CAPTURE);
		if ($lines === false) {
			return null;
		}

		$section = [];
		$found = [];
		for ($index = 0, $length = count($lines); $index < $length; $index++) {
			$line = $lines[$index];
			if ($line === "\n" || $line === "\r" || $line === "\r\n") {
				continue;
			}

			$body = $line;
			$ending = '';
			if ($index + 1 < $length && in_array($lines[$index + 1], ["\n", "\r", "\r\n"], true)) {
				$ending = $lines[++$index];
			}

			$trimmed = trim($body);
			if ($format !== StoreFormat::Env && preg_match('/^\[([^]]+)]$/', $trimmed, $matches) === 1) {
				$section = array_values(array_filter(array_map('trim', explode('.', $matches[1])), static fn(string $part) : bool => $part !== ''));

				continue;
			}

			$parsed = self::parseAssignment($format, $body);
			if ($parsed === null) {
				continue;
			}

			[$key, , $valueOffset, $commentOffset] = $parsed;
			$path = $format === StoreFormat::Properties || $format === StoreFormat::Env
				? $key
				: implode('.', [...$section, ...array_values(array_filter(explode('.', $key), static fn(string $part) : bool => $part !== ''))]);
			if (!array_key_exists($path, $set)) {
				continue;
			}

			$comment = $commentOffset === null ? '' : substr($body, $commentOffset);
			$prefix = substr($body, 0, $valueOffset);
			$valuePart = substr($body, $valueOffset, $commentOffset === null ? null : $commentOffset - $valueOffset);
			$spacing = substr($valuePart, strlen(rtrim($valuePart, " \t")));
			$lineIndex = $index - ($ending !== '' ? 1 : 0);
			$lines[$lineIndex] = $prefix . self::encode($set[$path]) . $spacing;
			if ($comment !== '') {
				$lines[$lineIndex] .= $comment;
			}

			$found[$path] = ($found[$path] ?? 0) + 1;
		}

		foreach (array_keys($set) as $path) {
			if (($found[$path] ?? 0) !== 1) {
				return null;
			}
		}

		return implode('', $lines);
	}

	/**
	 * Replaces unique JSON/JSONC scalar assignments and leaves comments intact.
	 *
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 */
	private static function updateJson(string $source, array $before, array $after) : ?string
	{
		$set = [];
		$removed = [];
		self::diff($after, $before, '', $set, $removed);
		if ($removed !== [] || $set === []) {
			return null;
		}

		foreach ($set as $path => $value) {
			if (is_array($value)) {
				return null;
			}
			$oldFound = false;
			$oldValue = self::valueAt($before, $path, $oldFound);
			if (!$oldFound) {
				return null;
			}
			$separator = strrpos($path, '.');
			$key = substr($path, $separator === false ? 0 : $separator + 1);
			$oldEncoded = self::encode($oldValue);
			$encoded = self::encode($value);
			$pattern = '/("' . preg_quote($key, '/') . '"\s*:\s*)' . preg_quote($oldEncoded, '/') . '(?=\s*(?:,|}|\/\/|\/\*|$))/m';
			$count = 0;
			$updated = preg_replace_callback($pattern, static function(array $matches) use ($encoded) : string {
				return $matches[1] . $encoded;
			}, $source, 1, $count);
			if ($updated === null || $count !== 1) {
				return null;
			}
			$source = $updated;
		}

		return $source;
	}

	/**
	 * Replaces unique YAML scalar assignments while keeping inline comments.
	 *
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 */
	private static function updateYaml(string $source, array $before, array $after) : ?string
	{
		$set = [];
		$removed = [];
		self::diff($after, $before, '', $set, $removed);
		if ($removed !== [] || $set === []) {
			return null;
		}

		foreach ($set as $path => $value) {
			if (is_array($value)) {
				return null;
			}
			$separator = strrpos($path, '.');
			$key = substr($path, $separator === false ? 0 : $separator + 1);
			$encoded = self::encode($value);
			$pattern = '/^(\s*' . preg_quote($key, '/') . '\s*:\s*)([^#\r\n]*?)(\s*(?:#.*)?\R|$)/m';
			if (preg_match_all($pattern, $source) !== 1) {
				return null;
			}
			$count = 0;
			$updated = preg_replace_callback($pattern, static function(array $matches) use ($encoded) : string {
				return $matches[1] . $encoded . $matches[3];
			}, $source, 1, $count);
			if ($updated === null || $count !== 1) {
				return null;
			}
			$source = $updated;
		}

		return $source;
	}

	/**
	 * @param StoreFormat $format Source format.
	 * @param string      $line   Source line without its line ending.
	 * @return array{0: string, 1: int, 2: int, 3: int|null}|null Parsed assignment.
	 */
	private static function parseAssignment(StoreFormat $format, string $line) : ?array
	{
		$offset = strpos($line, '=');
		if ($offset === false) {
			if ($format === StoreFormat::Properties && preg_match('/\s+/', $line, $matches, PREG_OFFSET_CAPTURE) === 1) {
				$offset = $matches[0][1];
			} else {
				return null;
			}
		}

		$left = trim(substr($line, 0, $offset));
		if ($format === StoreFormat::Env && str_starts_with($left, 'export ')) {
			$left = trim(substr($left, 7));
		}
		if ($left === '' || str_starts_with($left, '#') || str_starts_with($left, ';') || str_starts_with($left, '!')) {
			return null;
		}

		$valueOffset = $offset + 1;
		while (($line[$valueOffset] ?? '') === ' ' || ($line[$valueOffset] ?? '') === "\t") {
			$valueOffset++;
		}

		return [$left, $offset, $valueOffset, self::commentOffset($line, $valueOffset)];
	}

	private static function commentOffset(string $line, int $start) : ?int
	{
		$quote = null;
		$escaped = false;
		$length = strlen($line);
		for ($index = $start; $index < $length; $index++) {
			$character = $line[$index];
			if ($quote !== null) {
				if ($escaped) {
					$escaped = false;
				} elseif ($character === '\\') {
					$escaped = true;
				} elseif ($character === $quote) {
					$quote = null;
				}

				continue;
			}

			if ($character === '"' || $character === "'") {
				$quote = $character;
			} elseif (($character === '#' || $character === ';' || $character === '!') && ($index === $start || $line[$index - 1] === ' ' || $line[$index - 1] === "\t")) {
				return $index;
			}
		}

		return null;
	}

	private static function encode(mixed $value) : string
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

		return (string) $value;
	}

	/** @param array<string,mixed> $values @param-out bool $found */
	private static function valueAt(array $values, string $path, bool &$found) : mixed
	{
		$value = $values;
		foreach (explode('.', $path) as $segment) {
			if (!is_array($value) || !array_key_exists($segment, $value)) {
				$found = false;

				return null;
			}
			$value = $value[$segment];
		}
		$found = true;

		return $value;
	}

	/**
	 * @param array<string, mixed> $current
	 * @param array<string, mixed> $previous
	 * @param string               $prefix
	 * @param array<string, mixed> $set
	 * @param list<string>         $removed
	 */
	private static function diff(array $current, array $previous, string $prefix, array &$set, array &$removed) : void
	{
		$keys = array_unique([...array_keys($current), ...array_keys($previous)]);
		foreach ($keys as $key) {
			$path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
			$hasCurrent = array_key_exists($key, $current);
			$hasPrevious = array_key_exists($key, $previous);
			if (!$hasCurrent) {
				$removed[] = $path;
			} elseif (!$hasPrevious) {
				$set[$path] = $current[$key];
			} elseif (is_array($current[$key]) && is_array($previous[$key])) {
				self::diff($current[$key], $previous[$key], $path, $set, $removed);
			} elseif ($current[$key] !== $previous[$key]) {
				$set[$path] = $current[$key];
			}
		}
	}
}
