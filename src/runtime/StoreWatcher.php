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

use pocketmine\scheduler\Task;
use pocketmine\scheduler\TaskHandler;
use pocketmine\scheduler\TaskScheduler;
use zwuiix\store\event\StoreWatchEvent;
use zwuiix\store\Store;

use function clearstatcache;
use function count;
use function filemtime;
use function filesize;
use function hash;
use function hash_file;
use function implode;
use function is_file;
use function max;

/**
 * Main-thread watcher that reloads a Store after an external file change.
 */
final class StoreWatcher
{
	private ?int $lastModified = null;

	private ?int $lastSize = null;

	private ?string $lastHash = null;

	private ?TaskHandler $task = null;

	/**
	 * @param Store                $store    Store to watch.
	 * @param callable(Store):void $callback Called after a successful reload.
	 */
	public function __construct(
		private Store $store,
		private       $callback,
	) {
		$this->rememberFileState();
	}

	/**
	 * Starts periodic checking on the supplied main-thread scheduler.
	 *
	 * @param TaskScheduler $scheduler     Scheduler that owns the polling task.
	 * @param int           $intervalTicks Poll interval in ticks.
	 * @return self          This watcher.
	 */
	public function start(TaskScheduler $scheduler, int $intervalTicks = 20) : self
	{
		if ($this->task !== null) {
			return $this;
		}

		$intervalTicks = max(1, $intervalTicks);
		$this->task = $scheduler->scheduleRepeatingTask(new class($this) extends Task {
			public function __construct(private StoreWatcher $watcher)
			{
			}

			public function onRun() : void
			{
				$this->watcher->poll();
			}
		}, $intervalTicks);

		return $this;
	}

	/**
	 * Stops periodic checking.
	 *
	 * @return void
	 */
	public function stop() : void
	{
		if ($this->task === null) {
			return;
		}

		$this->task->cancel();
		$this->task = null;
	}

	/**
	 * Checks the file once and reloads it when it changed.
	 *
	 * Dirty Stores are left untouched so an external update cannot overwrite
	 * local changes that have not been saved yet.
	 *
	 * @return bool True when a reload was performed.
	 */
	public function poll() : bool
	{
		$path = $this->store->path();
		if ($path === '') {
			return false;
		}

		clearstatcache(true, $path);
		if (!is_file($path)) {
			$this->lastModified = null;
			$this->lastSize = null;
			$this->lastHash = null;

			return false;
		}

		[$modified, $size, $hash] = $this->fileState();
		if ($modified === $this->lastModified && $size === $this->lastSize && $hash === $this->lastHash) {
			return false;
		}
		if ($this->store->isDirty()) {
			return false;
		}

		try {
			if (count($this->store->dependencies()) > 1) {
				$this->store->reloadDependencies();
			} else {
				$this->store->reload();
			}
		} catch (\Throwable) {
			$this->rememberFileState();

			return false;
		}

		$this->rememberFileState();
		if (StoreWatchEvent::hasHandlers()) {
			(new StoreWatchEvent($this->store))->call();
		}

		($this->callback)($this->store);

		return true;
	}

	private function rememberFileState() : void
	{
		$path = $this->store->path();
		clearstatcache(true, $path);
		[$this->lastModified, $this->lastSize, $this->lastHash] = $this->fileState();
	}

	/** @return array{0: int|null, 1: int|null, 2: string|null} */
	private function fileState() : array
	{
		$modified = 0;
		$size = 0;
		$hashes = [];
		foreach ($this->store->dependencies() as $path) {
			clearstatcache(true, $path);
			if (!is_file($path)) {
				$hashes[] = 'missing:' . $path;
				continue;
			}
			$modified = max($modified, filemtime($path) ?: 0);
			$size += filesize($path) ?: 0;
			$hashes[] = $path . ':' . (hash_file('sha256', $path) ?: '');
		}
		if ($hashes === []) {
			return [null, null, null];
		}

		return [$modified, $size, hash('sha256', implode("\0", $hashes))];
	}
}
