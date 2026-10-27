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

use zwuiix\store\data\ValueParser;
use zwuiix\store\exception\StoreException;
use zwuiix\store\StoreCodecInterface;

use function array_key_exists;
use function ctype_space;
use function getenv;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;
use function preg_replace_callback;
use function preg_split;
use function rtrim;
use function str_ends_with;
use function str_starts_with;
use function stripcslashes;
use function strlen;
use function strpos;
use function substr;
use function trim;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Encodes and decodes dotenv files.
 *
 * The codec supports comments, export prefixes, quoted values, typed scalar
 * values and ${KEY}/$KEY interpolation from the same document or process
 * environment. Values are intentionally kept flat because dotenv keys are
 * environment variable names rather than nested configuration paths.
 */
final class EnvCodec implements StoreCodecInterface
{
	/**
	 * Decodes a dotenv document.
	 *
	 * @param string      $contents   Dotenv document.
	 * @param string|null $passphrase Unused by the dotenv codec.
	 * @return array<string, mixed> Decoded environment values.
	 * @throws StoreException       If a line or key is malformed.
	 */
	public function decode(string $contents, ?string $passphrase = null) : array
	{
		$values = [];
		foreach (preg_split('/\R/', $contents) ?: [] as $lineNumber => $line) {
			$line = trim($line);
			if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
				continue;
			}
			if (str_starts_with($line, 'export ')) {
				$line = trim(substr($line, 7));
			}

			$separator = strpos($line, '=');
			if ($separator === false) {
				throw new StoreException('Invalid dotenv entry on line ' . ($lineNumber + 1) . '.');
			}

			$key = trim(substr($line, 0, $separator));
			if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
				throw new StoreException("Invalid dotenv key '{$key}' on line " . ($lineNumber + 1) . '.');
			}

			$rawValue = trim($this->stripComment(substr($line, $separator + 1)));
			$values[$key] = $this->parseValue($rawValue);
		}

		$resolved = [];
		foreach ($values as $key => $value) {
			$resolved[$key] = $this->resolveValue($value, $values, $key, []);
		}

		return $resolved;
	}

	/**
	 * Encodes values as a dotenv document.
	 *
	 * @param array<string, mixed> $data       Values to encode.
	 * @param string|null          $passphrase Unused by the dotenv codec.
	 * @return string               Dotenv document.
	 * @throws StoreException       If a key or value cannot be represented.
	 */
	public function encode(array $data, ?string $passphrase = null) : string
	{
		$lines = [];
		foreach ($data as $key => $value) {
			if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $key) !== 1) {
				throw new StoreException("Invalid dotenv key '{$key}'.");
			}

			$lines[] = $key . '=' . $this->encodeValue($value);
		}

		return rtrim(implode("\n", $lines)) . "\n";
	}

	private function parseValue(string $value) : mixed
	{
		if (strlen($value) >= 2 && str_starts_with($value, "'") && str_ends_with($value, "'")) {
			return substr($value, 1, -1);
		}
		if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
			return json_decode($value, true) ?? stripcslashes(substr($value, 1, -1));
		}

		if (str_starts_with($value, '[') || str_starts_with($value, '{')) {
			$decoded = json_decode($value, true);
			if (is_array($decoded)) {
				return $decoded;
			}
		}

		return ValueParser::parse($value);
	}

	/**
	 * @param array<string, mixed> $values
	 * @param list<string>         $stack
	 */
	private function resolveValue(mixed $value, array $values, string $key, array $stack) : mixed
	{
		if (!is_string($value)) {
			return $value;
		}
		if (in_array($key, $stack, true)) {
			throw new StoreException("Circular dotenv variable reference involving '{$key}'.");
		}

		return preg_replace_callback('/\$\{([A-Za-z_][A-Za-z0-9_]*)\}|\$([A-Za-z_][A-Za-z0-9_]*)/', function(array $matches) use ($values, $key, $stack) : string {
			$name = $matches[1] !== '' ? $matches[1] : $matches[2];
			if (array_key_exists($name, $values)) {
				$replacement = $this->resolveValue($values[$name], $values, $name, [...$stack, $key]);

				return is_string($replacement) ? $replacement : (string) json_encode($replacement);
			}

			$environment = getenv($name);

			return $environment === false ? '' : $environment;
		}, $value) ?? $value;
	}

	private function stripComment(string $value) : string
	{
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
			} elseif (($character === '#' || $character === ';') && ($index === 0 || ctype_space($value[$index - 1]))) {
				return rtrim(substr($value, 0, $index));
			}
		}

		return rtrim($value);
	}

	private function encodeValue(mixed $value) : string
	{
		if (is_string($value)) {
			return preg_match('~^[A-Za-z0-9_./:@%+,-]+$~', $value) === 1 ? $value : (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '""');
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

		throw new StoreException('Dotenv values must be scalar values or arrays.');
	}
}
