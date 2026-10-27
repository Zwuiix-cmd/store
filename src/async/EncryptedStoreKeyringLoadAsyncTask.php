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
use Throwable;
use zwuiix\store\StoreCodecRegistry;
use zwuiix\store\StoreFormat;

use function array_replace_recursive;
use function file_get_contents;
use function is_array;
use function is_file;
use function is_string;

/**
 * Tries an encrypted Store keyring without blocking the main thread.
 */
final class EncryptedStoreKeyringLoadAsyncTask extends AsyncTask
{
	private const string PROMISE_KEY = 'promise';

	/**
	 * @param string                               $path       Encrypted Store path.
	 * @param array<string, mixed>                 $defaults   Defaults to merge after decryption.
	 * @param list<string>                         $transports Candidate serialized keys.
	 * @param PromiseResolver<array<string,mixed>> $resolver   Completion resolver.
	 */
	public function __construct(
		private string  $path,
		private array   $defaults,
		private array   $transports,
		PromiseResolver $resolver,
	) {
		$this->storeLocal(self::PROMISE_KEY, $resolver);
	}

	/**
	 * Reads and decrypts with each candidate key in order.
	 *
	 * @return void
	 */
	public function onRun() : void
	{
		if (!is_file($this->path)) {
			$this->setResult(false);

			return;
		}

		$contents = file_get_contents($this->path);
		if ($contents === false) {
			$this->setResult(false);

			return;
		}

		foreach ($this->transports as $index => $transport) {
			try {
				$data = StoreCodecRegistry::get(StoreFormat::Encrypted)->decode($contents, $transport);
				$this->setResult([
					'data' => array_replace_recursive($this->defaults, $data),
					'transport' => $transport,
					'index' => $index,
				]);

				return;
			} catch (Throwable) {
				continue;
			}
		}

		$this->setResult(false);
	}

	/**
	 * Resolves the result on the main thread.
	 *
	 * @return void
	 */
	public function onCompletion() : void
	{
		/** @var PromiseResolver<array<string,mixed>> $resolver */
		$resolver = $this->fetchLocal(self::PROMISE_KEY);
		$result = $this->getResult();
		if (!is_array($result) || !is_array($result['data'] ?? null) || !is_string($result['transport'] ?? null)) {
			$resolver->reject();

			return;
		}

		$resolver->resolve($result);
	}
}
