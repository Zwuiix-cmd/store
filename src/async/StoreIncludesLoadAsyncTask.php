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
use zwuiix\store\storage\StoreIncludeLoader;
use zwuiix\store\StoreFormat;
use function is_array;

/**
 * Loads an include graph and all its codecs in PocketMine's async pool.
 */
final class StoreIncludesLoadAsyncTask extends AsyncTask
{
	private const string PROMISE_KEY = 'promise';

	private string $format;

	public function __construct(
		private string $path,
		StoreFormat $format,
		private ?string $passphrase,
		PromiseResolver $resolver,
	) {
		$this->format = $format->value;
		$this->storeLocal(self::PROMISE_KEY, $resolver);
	}

	public function onRun() : void
	{
		try {
			$loaded = StoreIncludeLoader::load($this->path, StoreFormat::from($this->format), $this->passphrase);
			$this->setResult($loaded);
		} catch (Throwable) {
			$this->setResult(false);
		}
	}

	public function onCompletion() : void
	{
		/** @var PromiseResolver<array<string,mixed>> $resolver */
		$resolver = $this->fetchLocal(self::PROMISE_KEY);
		$result = $this->getResult();
		if (is_array($result) && is_array($result['values'] ?? null) && is_array($result['files'] ?? null) && is_array($result['graph'] ?? null)) {
			$resolver->resolve($result);

			return;
		}
		$resolver->reject();
	}
}
