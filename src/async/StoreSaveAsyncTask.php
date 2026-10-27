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
use zwuiix\store\StoreCodecRegistry;
use zwuiix\store\StoreFormat;

use function dirname;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_string;
use function mkdir;
use function tempnam;
use function unlink;

/**
 * Encodes and writes a Store snapshot in PocketMine's async worker pool.
 */
final class StoreSaveAsyncTask extends AsyncTask
{
	private const string PROMISE_KEY = 'promise';

	/**
	 * Creates a worker task that encodes and writes a Store snapshot.
	 *
	 * @param string                  $path       File to write.
	 * @param array<string, mixed>    $values     Snapshot to encode.
	 * @param StoreFormat             $format     Format used for encoding.
	 * @param string|null             $passphrase Secret passed to the codec.
	 * @param PromiseResolver<string> $resolver   Resolver completed with the temporary file path.
	 */
	public function __construct(
		private string  $path,
		array           $values,
		StoreFormat     $format,
		private ?string $passphrase,
		PromiseResolver $resolver,
	) {
		$this->format = $format->value;
		$this->values = new NonThreadSafeValue($values);
		$this->storeLocal(self::PROMISE_KEY, $resolver);
	}

	private string $format;

	/**
	 * Values transported to the worker through PocketMine's thread-safe wrapper.
	 *
	 * NonThreadSafeValue serializes the snapshot internally while keeping the
	 * task property itself thread-safe.
	 */
	private NonThreadSafeValue $values;

	/**
	 * Encodes and writes the snapshot to a temporary file in the worker.
	 *
	 * The final rename is deliberately performed by Store on the main thread
	 * after it verifies that this snapshot is still the newest revision. This
	 * prevents an older async write from overwriting a newer synchronous save.
	 *
	 * @return void
	 */
	public function onRun() : void
	{
		try {
			$values = $this->values->deserialize();
			if (!is_array($values)) {
				$this->setResult(false);

				return;
			}

			$contents = StoreCodecRegistry::get(StoreFormat::from($this->format))->encode($values, $this->passphrase);
			$directory = dirname($this->path);
			if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
				$this->setResult(false);

				return;
			}

			$tempPath = tempnam($directory, '.store-');
			if ($tempPath === false) {
				$this->setResult(false);

				return;
			}

			if (file_put_contents($tempPath, $contents) === false) {
				@unlink($tempPath);
				$this->setResult(false);

				return;
			}

			$this->setResult($tempPath);
		} catch (Throwable) {
			$this->setResult(false);
		}
	}

	/**
	 * Resolves or rejects the public promise on the main thread.
	 *
	 * @return void
	 */
	public function onCompletion() : void
	{
		/** @var PromiseResolver<string> $resolver */
		$resolver = $this->fetchLocal(self::PROMISE_KEY);
		$result = $this->getResult();
		if (is_string($result)) {
			$resolver->resolve($result);
		} else {
			$resolver->reject();
		}
	}
}
