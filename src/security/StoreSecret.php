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

namespace zwuiix\store\security;

use JsonSerializable;

use function array_map;
use function is_array;
use function strlen;

/**
 * Holds a sensitive configuration value without exposing it in debug output.
 *
 * Store unwraps this value only while encoding a document. Calling reveal()
 * remains an explicit action for application code that needs the secret.
 */
final class StoreSecret implements JsonSerializable
{
	public function __construct(
		private string $value,
	) {
	}

	/**
	 * Creates a secret value.
	 *
	 * @param string $value Secret text.
	 * @return self   Wrapped secret.
	 */
	public static function from(string $value) : self
	{
		return new self($value);
	}

	/**
	 * Returns the secret text.
	 *
	 * @return string Secret text.
	 */
	public function reveal() : string
	{
		return $this->value;
	}

	/**
	 * Returns whether the secret is empty.
	 *
	 * @return bool True when no characters are stored.
	 */
	public function isEmpty() : bool
	{
		return $this->value === '';
	}

	/**
	 * Returns the length without revealing the value.
	 *
	 * @return int Number of bytes in the secret.
	 */
	public function length() : int
	{
		return strlen($this->value);
	}

	/**
	 * Returns a safe display value.
	 *
	 * @return string Redacted marker.
	 */
	public function redacted() : string
	{
		return '[REDACTED]';
	}

	/** @return array{value: string} Redacted debug data. */
	public function __debugInfo() : array
	{
		return ['value' => $this->redacted()];
	}

	/** @return string Redacted JSON value. */
	public function jsonSerialize() : string
	{
		return $this->redacted();
	}

	/**
	 * Removes secret wrappers before passing a value tree to a codec.
	 *
	 * @param mixed $value Value or value tree to unwrap.
	 * @return mixed Codec-safe value.
	 */
	public static function unwrap(mixed $value) : mixed
	{
		if ($value instanceof self) {
			return $value->reveal();
		}
		if (!is_array($value)) {
			return $value;
		}

		return array_map(self::unwrap(...), $value);
	}
}
