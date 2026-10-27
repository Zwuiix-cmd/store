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

use BackedEnum;
use DateTimeImmutable;
use zwuiix\store\mapping\StoreMapper;
use zwuiix\store\Store;
use zwuiix\store\StoreFormat;
use zwuiix\store\validation\StoreSchema;
use function array_keys;
use function array_map;
use function is_array;

/**
 * Read-only view of a Store that can safely be handed to another service.
 */
final class StoreView
{
	public function __construct(
		private Store $store,
		private string $prefix = '',
	)
	{
	}

	/** @return string Associated file path, or an empty string for memory-only data. */
	public function path() : string
	{
		return $this->store->path();
	}

	/** @return string Root path represented by this view. */
	public function keyPath() : string
	{
		return $this->prefix;
	}

	/** @return StoreFormat Serialization format. */
	public function format() : StoreFormat
	{
		return $this->store->format();
	}

	/** @return bool Whether a path exists. */
	public function has(string $path) : bool
	{
		return $this->store->has($this->resolve($path));
	}

	/** @return mixed Value at the path, or the default when absent. */
	public function get(string $path, mixed $default = null) : mixed
	{
		return $this->store->get($this->resolve($path), $default);
	}

	/** @return bool Boolean value at the path. */
	public function getBool(string $path, ?bool $default = null) : ?bool
	{
		return $this->store->getBool($this->resolve($path), $default);
	}

	/** @return int Integer value at the path. */
	public function getInt(string $path, ?int $default = null) : ?int
	{
		return $this->store->getInt($this->resolve($path), $default);
	}

	/** @return float Floating point value at the path. */
	public function getFloat(string $path, ?float $default = null) : ?float
	{
		return $this->store->getFloat($this->resolve($path), $default);
	}

	/** @return string String value at the path. */
	public function getString(string $path, ?string $default = null) : ?string
	{
		return $this->store->getString($this->resolve($path), $default);
	}

	/** @return BackedEnum|null Backed enum value at the branch path. */
	public function getEnum(string $path, string $enum, ?BackedEnum $default = null) : ?BackedEnum
	{
		return $this->store->getEnum($this->resolve($path), $enum, $default);
	}

	/** @return array<mixed>|null List value at the branch path. */
	public function getList(string $path, ?array $default = null) : ?array
	{
		return $this->store->getList($this->resolve($path), $default);
	}

	/** @return array<string|int,mixed>|null Map value at the branch path. */
	public function getMap(string $path, ?array $default = null) : ?array
	{
		return $this->store->getMap($this->resolve($path), $default);
	}

	/** @return array<mixed>|null Array value at the branch path. */
	public function getArray(string $path, ?array $default = null) : ?array
	{
		return $this->store->getArray($this->resolve($path), $default);
	}

	/** @return list<string> String list at the branch path. */
	public function getStringList(string $path, array $default = []) : array
	{
		return $this->store->getStringList($this->resolve($path), $default);
	}

	/** @return DateTimeImmutable|null Date and time value at the branch path. */
	public function getDateTime(string $path, ?DateTimeImmutable $default = null) : ?DateTimeImmutable
	{
		return $this->store->getDateTime($this->resolve($path), $default);
	}

	/** @param list<string> $paths @return bool Whether at least one path exists. */
	public function hasAny(array $paths) : bool
	{
		return $this->store->hasAny(array_map(fn(string $path) : string => $this->resolve($path), $paths));
	}

	/** @param list<string> $paths @return bool Whether every path exists. */
	public function hasAll(array $paths) : bool
	{
		return $this->store->hasAll(array_map(fn(string $path) : string => $this->resolve($path), $paths));
	}

	/**
	 * Hydrates an immutable branch into a typed object.
	 *
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T
	 */
	public function getMapped(string $path, string $class, ?StoreSchema $schema = null) : object
	{
		return StoreMapper::get($this->store, $this->resolve($path), $class, $schema);
	}

	/** @return self Immutable child view. */
	public function view(string $path = '') : self
	{
		return new self($this->store, $this->resolve($path));
	}

	/** @return array<string,mixed> A copy of every value. */
	public function all() : array
	{
		if ($this->prefix === '') {
			return $this->store->all();
		}

		$value = $this->store->get($this->prefix, []);
		if (!is_array($value)) {
			return [];
		}

		return $value;
	}

	/** @return list<string> Top-level keys. */
	public function keys() : array
	{
		return array_keys($this->all());
	}

	private function resolve(string $path) : string
	{
		if ($this->prefix === '') {
			return $path;
		}
		if ($path === '') {
			return $this->prefix;
		}

		return $this->prefix . '.' . $path;
	}
}
