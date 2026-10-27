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

namespace zwuiix\store\async;

use pocketmine\promise\PromiseResolver;
use pocketmine\scheduler\AsyncTask;
use pocketmine\thread\NonThreadSafeValue;
use Throwable;
use zwuiix\store\event\StoreLoadEvent;
use zwuiix\store\Store;
use zwuiix\store\StoreCodecRegistry;
use zwuiix\store\StoreFormat;

use function array_replace_recursive;
use function chmod;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_file;
use function mkdir;
use function rename;
use function tempnam;
use function unlink;

/**
 * Loads and decodes a Store document in PocketMine's async worker pool.
 */
final class StoreLoadAsyncTask extends AsyncTask
{
	private const string PROMISE_KEY = 'promise';

	/**
	 * Creates a worker task that loads and decodes a Store file.
	 *
	 * @param string                 $path           File to load.
	 * @param StoreFormat            $format         Format used for decoding.
	 * @param array<string, mixed>   $defaults       Values merged into the decoded data.
	 * @param string|null            $passphrase     Secret passed to the codec.
	 * @param bool                   $dispatchEvents Whether the completed Store should emit its load event.
	 * @param PromiseResolver<Store> $resolver       Resolver completed by the task.
	 */
	public function __construct(
		private string  $path,
		StoreFormat     $format,
		array           $defaults,
		private ?string $passphrase,
		private bool    $dispatchEvents,
		PromiseResolver $resolver,
	) {
		$this->format = $format->value;
		$this->defaults = new NonThreadSafeValue($defaults);
		$this->storeLocal(self::PROMISE_KEY, $resolver);
	}

	private string $format;

	/**
	 * Defaults transported to the worker through PocketMine's thread-safe wrapper.
	 *
	 * NonThreadSafeValue serializes the array internally while keeping the task
	 * property itself thread-safe.
	 */
	private NonThreadSafeValue $defaults;

	/**
	 * Loads, decodes and, when necessary, creates the Store file in the worker.
	 * The Store object itself is created only during completion, after the worker
	 * has finished reading and decoding the file.
	 *
	 * @return void
	 */
	public function onRun() : void
	{
		try {
			$defaults = $this->defaults->deserialize();
		} catch (Throwable) {
			$this->setResult(['ok' => false]);

			return;
		}
		if (!is_array($defaults)) {
			$this->setResult(['ok' => false]);

			return;
		}

		if (!is_file($this->path)) {
			try {
				$data = $defaults;
				$directory = dirname($this->path);
				if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
					$this->setResult(['ok' => false]);

					return;
				}

				$tempPath = tempnam($directory, '.store-');
				if ($tempPath === false) {
					$this->setResult(['ok' => false]);

					return;
				}

				$contents = StoreCodecRegistry::get(StoreFormat::from($this->format))->encode($data, $this->passphrase);
				$success = file_put_contents($tempPath, $contents) !== false && chmod($tempPath, $this->format === StoreFormat::Encrypted->value ? 0600 : 0640) && rename($tempPath, $this->path);
				if (is_file($tempPath)) {
					@unlink($tempPath);
				}

				$this->setResult($success ? ['ok' => true, 'data' => $data, 'created' => true] : ['ok' => false]);
			} catch (Throwable) {
				$this->setResult(['ok' => false]);
			}

			return;
		}

		$contents = file_get_contents($this->path);
		if ($contents === false) {
			$this->setResult(['ok' => false, 'missing' => false]);

			return;
		}

		try {
			chmod($this->path, $this->format === StoreFormat::Encrypted->value ? 0600 : 0640);
			$data = StoreCodecRegistry::get(StoreFormat::from($this->format))->decode($contents, $this->passphrase);
			if (!is_array($data)) {
				$this->setResult(['ok' => false, 'missing' => false]);

				return;
			}
			$this->setResult([
				'ok' => true,
				'data' => array_replace_recursive($defaults, $data),
			]);
		} catch (Throwable) {
			$this->setResult(['ok' => false, 'missing' => false]);
		}
	}

	/**
	 * Resolves or rejects the public promise on the main thread.
	 *
	 * @return void
	 */
	public function onCompletion() : void
	{
		/** @var PromiseResolver<Store> $resolver */
		$resolver = $this->fetchLocal(self::PROMISE_KEY);
		$result = $this->getResult();
		if (!is_array($result) || !($result['ok'] ?? false)) {
			$resolver->reject();

			return;
		}

		$data = $result['data'] ?? null;
		if (!is_array($data)) {
			$resolver->reject();

			return;
		}

		try {
			$store = new Store(
				$this->path,
				StoreFormat::from($this->format),
				[],
				$this->passphrase,
				$data,
				dispatchEvents: false,
			);
			if ($this->dispatchEvents && StoreLoadEvent::hasHandlers()) {
				(new StoreLoadEvent($store, (bool) ($result['created'] ?? false)))->call();
			}

			$resolver->resolve($store);
		} catch (Throwable) {
			$resolver->reject();
		}
	}
}
