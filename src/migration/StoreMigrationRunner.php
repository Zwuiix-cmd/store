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

namespace zwuiix\store\migration;

use pocketmine\promise\Promise;
use zwuiix\store\event\StoreMigrationEvent;
use zwuiix\store\exception\StoreException;
use zwuiix\store\Store;

use function array_key_exists;
use function array_keys;
use function is_int;
use function ksort;
use function max;
use function sprintf;

/**
 * Applies ordered, forward-only migrations to a Store.
 */
final class StoreMigrationRunner
{
	private const int DEFAULT_VERSION = 0;

	/** @var array<int, StoreMigration> */
	private array $migrations = [];

	/**
	 * @param Store  $store      Store to migrate.
	 * @param string $versionKey Nested key storing the current version.
	 */
	public function __construct(
		private Store  $store,
		private string $versionKey = '_meta.version',
	) {
	}

	/**
	 * Adds an object migration.
	 *
	 * @param StoreMigration $migration Migration to register.
	 * @return self           This runner.
	 * @throws StoreException If the version is invalid or duplicated.
	 */
	public function add(StoreMigration $migration) : self
	{
		$version = $migration->version();
		if ($version < 1 || array_key_exists($version, $this->migrations)) {
			throw new StoreException("Invalid or duplicated Store migration version '{$version}'.");
		}

		$this->migrations[$version] = $migration;

		return $this;
	}

	/**
	 * Registers a migration callback.
	 *
	 * @param int                  $version  Migration version.
	 * @param callable(Store):void $callback Migration callback.
	 * @return self                 This runner.
	 * @throws StoreException       If the version is invalid or duplicated.
	 */
	public function register(int $version, callable $callback) : self
	{
		return $this->add(new class($version, $callback) implements StoreMigration {
			/** @var callable(Store):void */
			private $callback;

			public function __construct(
				private int $version,
				callable    $callback,
			) {
				$this->callback = $callback;
			}

			public function version() : int
			{
				return $this->version;
			}

			public function up(Store $store) : void
			{
				($this->callback)($store);
			}
		});
	}

	/**
	 * Backwards-compatible alias for register().
	 *
	 * @param int                  $version  Migration version.
	 * @param callable(Store):void $callback Migration callback.
	 * @return self                 This runner.
	 * @throws StoreException       If the version is invalid or duplicated.
	 */
	public function addCallback(int $version, callable $callback) : self
	{
		return $this->register($version, $callback);
	}

	/**
	 * Returns the version currently stored in the document.
	 *
	 * @return int            Current migration version.
	 * @throws StoreException If the stored version is not a non-negative integer.
	 */
	public function currentVersion() : int
	{
		$value = $this->store->get($this->versionKey, self::DEFAULT_VERSION);
		if (!is_int($value) || $value < 0) {
			throw new StoreException(sprintf("Store migration version '%s' must be a non-negative integer.", $this->versionKey));
		}

		return $value;
	}

	/**
	 * Runs every registered migration after the current version.
	 *
	 * Migration callbacks always run synchronously because they mutate PHP
	 * objects. When async is true, only the final persistence uses the async
	 * worker pool and the returned Promise resolves after that write.
	 *
	 * @param int|null $targetVersion Highest version to apply, or null for all.
	 * @param bool     $async         Whether the final save should be asynchronous.
	 * @return Promise<bool>|null Async save Promise, or null when no async save is requested.
	 * @throws StoreException     If a migration is missing or fails.
	 */
	public function migrate(?int $targetVersion = null, bool $async = false) : ?Promise
	{
		ksort($this->migrations);
		$current = $this->currentVersion();
		$target = $targetVersion ?? (array_keys($this->migrations) === [] ? $current : max(array_keys($this->migrations)));
		if ($target < $current) {
			throw new StoreException("Cannot migrate Store backwards from '{$current}' to '{$target}'.");
		}

		$snapshot = $this->store->snapshot();
		$autoSave = $this->store->isAutoSaveEnabled();
		if ($autoSave) {
			$this->store->disableAutoSave();
		}

		try {
			for ($version = $current + 1; $version <= $target; $version++) {
				if (!array_key_exists($version, $this->migrations)) {
					throw new StoreException("Missing Store migration version '{$version}'.");
				}
				if (StoreMigrationEvent::hasHandlers()) {
					(new StoreMigrationEvent($this->store, $version, 'before'))->call();
				}

				$this->migrations[$version]->up($this->store);
				$this->store->set($this->versionKey, $version);
				if (StoreMigrationEvent::hasHandlers()) {
					(new StoreMigrationEvent($this->store, $version, 'after'))->call();
				}
			}
		} catch (\Throwable $exception) {
			$this->store->restoreSnapshot($snapshot);
			if ($autoSave) {
				$this->store->enableAutoSave();
			}
			if ($exception instanceof StoreException) {
				throw $exception;
			}

			throw new StoreException('Store migration failed: ' . $exception->getMessage(), 0, $exception);
		}

		if ($current === $target || $this->store->path() === '') {
			if ($autoSave) {
				$this->store->enableAutoSave();
			}

			return null;
		}

		$promise = $this->store->save($async);
		if ($autoSave) {
			$restoreAutoSave = function() : void {
				$this->store->enableAutoSave();
			};
			if ($promise === null) {
				$restoreAutoSave();
			} else {
				$promise->onCompletion($restoreAutoSave, $restoreAutoSave);
			}
		}

		return $promise;
	}
}
