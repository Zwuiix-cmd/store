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

use JsonException;
use zwuiix\store\event\StoreChangeEvent;

use function date;
use function dirname;
use function file_put_contents;
use function is_dir;
use function json_encode;
use function mkdir;
use const DATE_ATOM;
use const FILE_APPEND;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;

/**
 * Optional JSONL audit sink fed by Store change events.
 */
final class StoreAuditLog
{
	public function __construct(
		private string $path,
		private bool   $includeValues = false,
	) {
	}

	/** @return string Audit file path. */
	public function path() : string
	{
		return $this->path;
	}

	/** @return bool Whether values are included, with secrets still redacted. */
	public function includesValues() : bool
	{
		return $this->includeValues;
	}

	/**
	 * Appends one change event as a JSON line.
	 *
	 * @param StoreChangeEvent $event Change event to record.
	 * @throws JsonException If the event cannot be serialized.
	 */
	public function record(StoreChangeEvent $event) : void
	{
		$directory = dirname($this->path);
		if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
			throw new \RuntimeException("Unable to create Store audit directory '{$directory}'.");
		}
		$entry = [
			'time' => date(DATE_ATOM),
			'path' => $event->path(),
			'key' => $event->key(),
			'operation' => $event->operation(),
		];
		if ($this->includeValues) {
			$entry['old'] = $event->oldValue();
			$entry['new'] = $event->newValue();
		}
		$line = json_encode($entry, JSON_THROW_ON_ERROR) . "\n";
		if (file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
			throw new \RuntimeException("Unable to append Store audit log '{$this->path}'.");
		}
	}
}
