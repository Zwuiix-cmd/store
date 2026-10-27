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

use zwuiix\store\exception\StoreException;

use function array_key_first;
use function trim;

/**
 * Ordered collection of keys used during key rotation and recovery.
 */
final class StoreKeyring
{
	/** @var array<string, StoreKey> */
	private array $keys = [];

	/**
	 * Adds or replaces a named key.
	 *
	 * @param string          $name Key identifier.
	 * @param StoreKey|string $key  Key descriptor or legacy passphrase.
	 * @return self            This keyring.
	 * @throws StoreException  If the name is empty or only contains whitespace.
	 */
	public function add(string $name, StoreKey|string $key) : self
	{
		$name = trim($name);
		if ($name === '') {
			throw new StoreException('A Store keyring name cannot be empty.');
		}

		$this->keys[$name] = StoreKeyProvider::resolveKey($key);

		return $this;
	}

	/**
	 * Moves a registered key to the front of the lookup order.
	 *
	 * This is useful after a key rotation when the new key should be used for
	 * future writes while older keys remain available for existing files.
	 *
	 * @param string $name Key identifier.
	 * @return self           This keyring.
	 * @throws StoreException If the key does not exist.
	 */
	public function setPrimary(string $name) : self
	{
		$key = $this->get($name);
		$this->keys = [$name => $key] + $this->keys;

		return $this;
	}

	/**
	 * Returns all keys in lookup order.
	 *
	 * @return array<string, StoreKey> Registered keys.
	 */
	public function all() : array
	{
		return $this->keys;
	}

	/**
	 * Checks whether a named key is registered.
	 *
	 * @param string $name Key identifier.
	 * @return bool   True when the key exists.
	 */
	public function has(string $name) : bool
	{
		$name = trim($name);

		return isset($this->keys[$name]);
	}

	/**
	 * Removes a named key.
	 *
	 * @param string $name Key identifier.
	 * @return self   This keyring.
	 */
	public function remove(string $name) : self
	{
		$name = trim($name);
		unset($this->keys[$name]);

		return $this;
	}

	/**
	 * Returns the name of the primary key.
	 *
	 * @return string         Primary key name.
	 * @throws StoreException If the keyring is empty.
	 */
	public function primaryName() : string
	{
		$name = array_key_first($this->keys);
		if ($name === null) {
			throw new StoreException('A Store keyring must contain at least one key.');
		}

		return $name;
	}

	/**
	 * Returns the first registered key, used as the primary write key.
	 *
	 * @return StoreKey       Primary key.
	 * @throws StoreException If the keyring is empty.
	 */
	public function primary() : StoreKey
	{
		return $this->keys[$this->primaryName()];
	}

	/**
	 * Returns a named key.
	 *
	 * @param string $name Key identifier.
	 * @return StoreKey       Key descriptor.
	 * @throws StoreException If the key does not exist.
	 */
	public function get(string $name) : StoreKey
	{
		if (!isset($this->keys[$name])) {
			throw new StoreException("Store key '{$name}' is not registered.");
		}

		return $this->keys[$name];
	}
}
