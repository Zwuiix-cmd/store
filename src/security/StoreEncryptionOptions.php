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

namespace zwuiix\store\security;

use zwuiix\store\exception\StoreException;

/**
 * Encryption and key derivation settings for new Store documents.
 */
final class StoreEncryptionOptions
{
	private const int MAX_ITERATIONS = 10_000_000;

	private const int MAX_MEMORY_LIMIT = 1_073_741_824;

	private const int MAX_TIME_LIMIT = 10;

	private const int MAX_THREADS = 16;

	public function __construct(
		private StoreCipher $cipher = StoreCipher::Aes256Gcm,
		private StoreKdf    $kdf = StoreKdf::Pbkdf2Sha256,
		private int         $iterations = 150000,
		private int         $memoryLimit = 67108864,
		private int         $timeLimit = 3,
		private int         $threads = 1,
		private bool        $compression = false,
	) {
		if ($this->iterations < 1 || $this->iterations > self::MAX_ITERATIONS) {
			throw new StoreException('PBKDF2 iterations must be between 1 and ' . self::MAX_ITERATIONS . '.');
		}
		if ($this->memoryLimit < 1 || $this->memoryLimit > self::MAX_MEMORY_LIMIT) {
			throw new StoreException('Argon2 memory must be between 1 byte and ' . self::MAX_MEMORY_LIMIT . ' bytes.');
		}
		if ($this->timeLimit < 1 || $this->timeLimit > self::MAX_TIME_LIMIT) {
			throw new StoreException('Argon2 time cost must be between 1 and ' . self::MAX_TIME_LIMIT . '.');
		}
		if ($this->threads < 1 || $this->threads > self::MAX_THREADS) {
			throw new StoreException('Argon2 threads must be between 1 and ' . self::MAX_THREADS . '.');
		}
	}

	/**
	 * Creates default settings for a cipher.
	 *
	 * @param StoreCipher $cipher Encryption algorithm.
	 * @return self        Default settings.
	 */
	public static function forCipher(StoreCipher $cipher) : self
	{
		return new self($cipher, $cipher === StoreCipher::XChaCha20Poly1305 ? StoreKdf::Argon2id : StoreKdf::Pbkdf2Sha256);
	}

	/** @return StoreCipher Encryption cipher. */
	public function cipher() : StoreCipher
	{
		return $this->cipher;
	}

	/** @return StoreKdf Key derivation strategy. */
	public function kdf() : StoreKdf
	{
		return $this->kdf;
	}

	/** @return int PBKDF2 iteration count. */
	public function iterations() : int
	{
		return $this->iterations;
	}

	/** @return int Argon2 memory limit in bytes. */
	public function memoryLimit() : int
	{
		return $this->memoryLimit;
	}

	/** @return int Argon2 time cost. */
	public function timeLimit() : int
	{
		return $this->timeLimit;
	}

	/** @return int Argon2 thread count. */
	public function threads() : int
	{
		return $this->threads;
	}

	/** @return bool Whether payload compression is enabled before encryption. */
	public function compression() : bool
	{
		return $this->compression;
	}

	/**
	 * Converts settings to their transport representation.
	 *
	 * @return array<string, bool|int|string> Serializable settings.
	 */
	public function toArray() : array
	{
		return [
			'cipher' => $this->cipher->value,
			'kdf' => $this->kdf->value,
			'iterations' => $this->iterations,
			'memory' => $this->memoryLimit,
			'time' => $this->timeLimit,
			'threads' => $this->threads,
			'compression' => $this->compression,
		];
	}

	/**
	 * Creates settings from a serialized representation.
	 *
	 * @param array<string, mixed> $data Serialized settings.
	 * @return self                 Parsed settings.
	 * @throws StoreException       If a setting is unsupported or invalid.
	 */
	public static function fromArray(array $data) : self
	{
		try {
			$kdf = (string) ($data['kdf'] ?? '');
			if ($kdf === 'argon2id-or-pbkdf2') {
				$kdf = ($data['cipher'] ?? '') === StoreCipher::XChaCha20Poly1305->value ? StoreKdf::Argon2id->value : StoreKdf::Pbkdf2Sha256->value;
			}

			return new self(
				StoreCipher::from((string) ($data['cipher'] ?? '')),
				StoreKdf::from($kdf),
				(int) ($data['iterations'] ?? 150000),
				(int) ($data['memory'] ?? 67108864),
				(int) ($data['time'] ?? 3),
				(int) ($data['threads'] ?? 1),
				(bool) ($data['compression'] ?? false),
			);
		} catch (\ValueError $exception) {
			throw new StoreException('Unsupported Store encryption settings.', 0, $exception);
		}
	}
}
