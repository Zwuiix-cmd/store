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
use zwuiix\store\storage\StoreRemote;
use zwuiix\store\Store;
use zwuiix\store\StoreFormat;
use function is_string;

/**
 * Downloads and decodes a remote Store document away from the main thread.
 */
final class StoreRemoteLoadAsyncTask extends AsyncTask
{
	private const string PROMISE_KEY = 'promise';

	private string $format;

	/**
	 * @param string                 $url
	 * @param StoreFormat            $format
	 * @param array<string,mixed>    $defaults
	 * @param string|null            $cacheFile
	 * @param int                    $ttl
	 * @param int                    $timeout
	 * @param string|null            $passphrase
	 * @param string|null            $sha256
	 * @param string|null            $signature
	 * @param string|null            $publicKey
	 * @param PromiseResolver<Store> $resolver
	 */
	public function __construct(
		private string  $url,
		StoreFormat     $format,
		private array   $defaults,
		private ?string $cacheFile,
		private int     $ttl,
		private int     $timeout,
		private ?string $passphrase,
		private ?string $sha256,
		private ?string $signature,
		private ?string $publicKey,
		PromiseResolver $resolver,
	) {
		$this->format = $format->value;
		$this->storeLocal(self::PROMISE_KEY, $resolver);
	}

	public function onRun() : void
	{
		try {
			$this->setResult(StoreRemote::fetch($this->url, $this->cacheFile, $this->ttl, $this->timeout, $this->sha256, $this->signature, $this->publicKey));
		} catch (Throwable) {
			$this->setResult(false);
		}
	}

	public function onCompletion() : void
	{
		/** @var PromiseResolver<Store> $resolver */
		$resolver = $this->fetchLocal(self::PROMISE_KEY);
		$contents = $this->getResult();
		if (!is_string($contents)) {
			$resolver->reject();

			return;
		}

		try {
			$resolver->resolve(Store::fromString($contents, StoreFormat::from($this->format), null, $this->passphrase, $this->defaults));
		} catch (Throwable) {
			$resolver->reject();
		}
	}
}
