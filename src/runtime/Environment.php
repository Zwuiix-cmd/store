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

namespace zwuiix\store\runtime;

use pocketmine\promise\Promise;
use pocketmine\promise\PromiseResolver;
use zwuiix\store\data\ValueParser;
use zwuiix\store\exception\StoreException;
use zwuiix\store\Store;
use zwuiix\store\StoreFormat;

use function array_key_exists;
use function array_values;
use function getenv;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use function preg_match;
use function putenv;

/**
 * Convenient access to dotenv files and process environment variables.
 *
 * Environment keeps values flat, supports typed accessors and can optionally
 * export loaded values into $_ENV, $_SERVER and the process environment.
 */
final class Environment
{
	private function __construct(private Store $store)
	{
	}

	/**
	 * Opens a dotenv file synchronously.
	 *
	 * @param string               $file     Dotenv file path.
	 * @param array<string, mixed> $defaults Values used when keys are absent.
	 * @return self                 Loaded environment.
	 * @throws StoreException       If the file cannot be read or created.
	 */
	public static function open(string $file, array $defaults = []) : self
	{
		return new self(Store::open($file, StoreFormat::Env, $defaults));
	}

	/**
	 * Opens a base dotenv file with a profile overlay.
	 *
	 * @param string              $file     Base dotenv file.
	 * @param string              $profile  Profile name.
	 * @param array<string,mixed> $defaults Base defaults.
	 * @return self                Loaded environment.
	 */
	public static function openProfile(string $file, string $profile, array $defaults = []) : self
	{
		return new self(Store::openProfile($file, $profile, StoreFormat::Env, $defaults));
	}

	/**
	 * Loads a dotenv file synchronously or asynchronously.
	 *
	 * @param string               $file     Dotenv file path.
	 * @param array<string, mixed> $defaults Values used when keys are absent.
	 * @param bool                 $async    Whether to load in PocketMine's async pool.
	 * @return self|Promise<self>   Environment or Promise resolving to it.
	 * @throws StoreException       If synchronous loading fails.
	 */
	public static function load(string $file, array $defaults = [], bool $async = false) : self|Promise
	{
		if (!$async) {
			return self::open($file, $defaults);
		}

		$resolver = new PromiseResolver();
		$loaded = Store::load($file, StoreFormat::Env, $defaults, async: true);
		if (!$loaded instanceof Promise) {
			throw new StoreException('Asynchronous Environment loading did not return a Promise.');
		}
		$loaded->onCompletion(static function(Store $store) use ($resolver) : void {
			$resolver->resolve(new self($store));
		}, static function() use ($resolver) : void {
			$resolver->reject();
		});

		return $resolver->getPromise();
	}

	/**
	 * Creates an Environment from dotenv content.
	 *
	 * @param string               $contents Dotenv content.
	 * @param array<string, mixed> $defaults Values used when keys are absent.
	 * @return self                 Decoded environment.
	 * @throws StoreException       If the content is malformed.
	 */
	public static function fromString(string $contents, array $defaults = []) : self
	{
		return new self(Store::fromString($contents, StoreFormat::Env, null, null, $defaults));
	}

	/**
	 * Creates an in-memory Environment from values.
	 *
	 * @param array<string, mixed> $values Initial environment values.
	 * @return self                 New environment.
	 * @throws StoreException       If a key or value cannot be represented.
	 */
	public static function fromArray(array $values) : self
	{
		return new self(Store::fromArray($values, StoreFormat::Env));
	}

	/**
	 * Returns the wrapped Store for advanced operations.
	 *
	 * @return Store Wrapped Store.
	 */
	public function store() : Store
	{
		return $this->store;
	}

	/**
	 * Returns a value from the dotenv file, then from the process environment.
	 *
	 * @param string $key                    Environment variable name.
	 * @param mixed  $default                Fallback value.
	 * @param bool   $readProcessEnvironment Whether process variables are allowed.
	 * @return mixed  Stored or fallback value.
	 */
	public function get(string $key, mixed $default = null, bool $readProcessEnvironment = true) : mixed
	{
		$this->assertKey($key);
		if ($this->store->has($key)) {
			return $this->store->get($key);
		}
		if ($readProcessEnvironment && array_key_exists($key, $_ENV)) {
			return ValueParser::parse((string) $_ENV[$key]);
		}
		if ($readProcessEnvironment && array_key_exists($key, $_SERVER)) {
			return ValueParser::parse((string) $_SERVER[$key]);
		}

		if ($readProcessEnvironment) {
			$value = getenv($key);
			if ($value !== false) {
				return ValueParser::parse($value);
			}
		}

		return $default;
	}

	/**
	 * Checks the dotenv file and process environment for a key.
	 *
	 * @param string $key                    Environment variable name.
	 * @param bool   $readProcessEnvironment Whether process variables are allowed.
	 * @return bool   True when the key exists.
	 */
	public function has(string $key, bool $readProcessEnvironment = true) : bool
	{
		$this->assertKey($key);

		return $this->store->has($key) || ($readProcessEnvironment && (array_key_exists($key, $_ENV) || array_key_exists($key, $_SERVER) || getenv($key) !== false));
	}

	/**
	 * Returns all values from the dotenv file.
	 *
	 * @return array<string, mixed> Dotenv values.
	 */
	public function all() : array
	{
		return $this->store->all();
	}

	/**
	 * Returns a string value.
	 *
	 * @param string      $key     Environment variable name.
	 * @param string|null $default Fallback value.
	 * @return string|null    String value or fallback.
	 * @throws StoreException If the resolved value is not a string.
	 */
	public function getString(string $key, ?string $default = null) : ?string
	{
		$value = $this->get($key, $default);
		if ($value !== null && !is_string($value)) {
			throw new StoreException("Environment value '{$key}' must be a string.");
		}

		return $value;
	}

	/**
	 * Returns an integer value.
	 *
	 * @param string   $key     Environment variable name.
	 * @param int|null $default Fallback value.
	 * @return int|null       Integer value or fallback.
	 * @throws StoreException If the resolved value is not an integer.
	 */
	public function getInt(string $key, ?int $default = null) : ?int
	{
		$value = $this->get($key, $default);
		if ($value !== null && !is_int($value)) {
			throw new StoreException("Environment value '{$key}' must be an integer.");
		}

		return $value;
	}

	/**
	 * Returns a boolean value.
	 *
	 * @param string    $key     Environment variable name.
	 * @param bool|null $default Fallback value.
	 * @return bool|null      Boolean value or fallback.
	 * @throws StoreException If the resolved value is not a boolean.
	 */
	public function getBool(string $key, ?bool $default = null) : ?bool
	{
		$value = $this->get($key, $default);
		if ($value !== null && !is_bool($value)) {
			throw new StoreException("Environment value '{$key}' must be a boolean.");
		}

		return $value;
	}

	/**
	 * Returns a floating-point value.
	 *
	 * @param string     $key     Environment variable name.
	 * @param float|null $default Fallback value.
	 * @return float|null     Float value or fallback.
	 * @throws StoreException If the resolved value is not numeric.
	 */
	public function getFloat(string $key, ?float $default = null) : ?float
	{
		$value = $this->get($key, $default);
		if ($value !== null && !is_float($value) && !is_int($value)) {
			throw new StoreException("Environment value '{$key}' must be a number.");
		}

		return $value === null ? null : (float) $value;
	}

	/**
	 * Returns an array value.
	 *
	 * @param string     $key     Environment variable name.
	 * @param array|null $default Fallback value.
	 * @return array|null     Array value or fallback.
	 * @throws StoreException If the resolved value is not an array.
	 */
	public function getArray(string $key, ?array $default = null) : ?array
	{
		$value = $this->get($key, $default);
		if ($value !== null && !is_array($value)) {
			throw new StoreException("Environment value '{$key}' must be an array.");
		}

		return $value;
	}

	/**
	 * Returns an array containing only strings.
	 *
	 * @param string       $key     Environment variable name.
	 * @param list<string> $default Fallback values.
	 * @return list<string>   String values.
	 * @throws StoreException If an item is not a string.
	 */
	public function getStringList(string $key, array $default = []) : array
	{
		$value = $this->getArray($key, $default) ?? [];
		foreach ($value as $item) {
			if (!is_string($item)) {
				throw new StoreException("Environment value '{$key}' must contain only strings.");
			}
		}

		return array_values($value);
	}

	/**
	 * Sets a dotenv value.
	 *
	 * @param string $key   Environment variable name.
	 * @param mixed  $value Value to store.
	 * @return self   This environment.
	 */
	public function set(string $key, mixed $value) : self
	{
		$this->assertKey($key);
		$this->store->set($key, $value);

		return $this;
	}

	/**
	 * Removes a dotenv value.
	 *
	 * @param string $key Environment variable name.
	 * @return bool   True when a value was removed.
	 */
	public function remove(string $key) : bool
	{
		$this->assertKey($key);
		$exists = $this->store->has($key);
		$this->store->remove($key);

		return $exists;
	}

	/**
	 * Saves the dotenv file synchronously or asynchronously.
	 *
	 * @param bool $async Whether to use PocketMine's async pool.
	 * @return Promise<bool>|null A Promise for async saves, or null after a synchronous save.
	 * @throws StoreException     If a synchronous save cannot be completed.
	 */
	public function save(bool $async = false) : ?Promise
	{
		return $this->store->save($async);
	}

	/**
	 * Exports dotenv values into the process environment.
	 *
	 * @param bool $overwrite Whether existing process variables may be replaced.
	 * @return self This environment.
	 */
	public function loadIntoEnvironment(bool $overwrite = false) : self
	{
		foreach ($this->all() as $key => $value) {
			if (!$overwrite && $this->hasInProcessEnvironment($key)) {
				continue;
			}

			$string = is_string($value) ? $value : (string) json_encode($value);
			putenv($key . '=' . $string);
			$_ENV[$key] = $value;
			$_SERVER[$key] = $value;
		}

		return $this;
	}

	private function assertKey(string $key) : void
	{
		if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
			throw new StoreException("Invalid environment key '{$key}'.");
		}
	}

	private function hasInProcessEnvironment(string $key) : bool
	{
		return array_key_exists($key, $_ENV) || array_key_exists($key, $_SERVER) || getenv($key) !== false;
	}
}
