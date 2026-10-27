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

use function base64_decode;
use function base64_encode;
use function file_get_contents;
use function getenv;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function random_bytes;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;
use const JSON_THROW_ON_ERROR;

/**
 * Describes the source of an encryption key.
 *
 * A key object keeps the public API explicit: a normal string is a
 * passphrase, while raw binary keys, key files, environment variables and
 * machine-derived keys can be selected deliberately.
 */
final class StoreKey
{
	private function __construct(
		private StoreKeyType $type,
		private string       $value,
		private bool         $resolved = false,
	) {
	}

	/**
	 * Creates a passphrase key. The cipher derives a cryptographic key from it.
	 *
	 * @param string $passphrase Human-readable secret.
	 * @return self           Passphrase key.
	 * @throws StoreException If the passphrase is empty.
	 */
	public static function passphrase(string $passphrase) : self
	{
		if (trim($passphrase) === '') {
			throw new StoreException('A passphrase cannot be empty.');
		}

		return new self(StoreKeyType::Passphrase, $passphrase);
	}

	/**
	 * Creates a raw key. AES and XChaCha20 keys must contain exactly 32 bytes.
	 *
	 * @param string $key Raw binary key material.
	 * @return self           Raw key.
	 * @throws StoreException If the key length is invalid.
	 */
	public static function raw(string $key) : self
	{
		if (strlen($key) !== 32) {
			throw new StoreException('A raw Store key must contain exactly 32 bytes.');
		}

		return new self(StoreKeyType::Raw, $key);
	}

	/**
	 * Generates a cryptographically secure random raw key.
	 *
	 * @return self A new 32-byte raw key.
	 */
	public static function random() : self
	{
		return self::raw(random_bytes(32));
	}

	/**
	 * Creates a key derived from the current machine identity.
	 *
	 * @return self Machine-derived key.
	 */
	public static function machine() : self
	{
		return new self(StoreKeyType::Machine, '');
	}

	/**
	 * Creates a key loaded from a file.
	 *
	 * @param string $path Path containing the secret.
	 * @return self   File-backed key.
	 */
	public static function file(string $path) : self
	{
		if (trim($path) === '') {
			throw new StoreException('A Store key file path cannot be empty.');
		}

		return new self(StoreKeyType::File, $path);
	}

	/**
	 * Creates a key loaded from an environment variable.
	 *
	 * @param string $name Environment variable name.
	 * @return self   Environment-backed key.
	 */
	public static function environment(string $name) : self
	{
		if (trim($name) === '') {
			throw new StoreException('A Store key environment name cannot be empty.');
		}

		return new self(StoreKeyType::Environment, $name);
	}

	/**
	 * Returns the key source type.
	 *
	 * @return StoreKeyType Key source type.
	 */
	public function type() : StoreKeyType
	{
		return $this->type;
	}

	/**
	 * Resolves this key into binary material.
	 *
	 * @return string         Resolved secret material.
	 * @throws StoreException If the external key source cannot be read.
	 */
	public function material() : string
	{
		if ($this->resolved) {
			return $this->value;
		}

		return match ($this->type) {
			StoreKeyType::Passphrase, StoreKeyType::Raw => $this->value,
			StoreKeyType::Machine => StoreKeyProvider::machineKey(),
			StoreKeyType::File => $this->readFile(),
			StoreKeyType::Environment => $this->readEnvironment(),
		};
	}

	/**
	 * Creates a scalar key description for async Store tasks.
	 *
	 * @param StoreEncryptionOptions $options Encryption settings for new documents.
	 * @return string                 Encoded key description.
	 * @throws StoreException         If the key cannot be resolved or encoded.
	 */
	public function toTransport(StoreEncryptionOptions $options) : string
	{
		try {
			return 'STOREKEY/1:' . base64_encode(json_encode([
				'type' => $this->type->value,
				'options' => $options->toArray(),
				'material' => base64_encode($this->material()),
			], JSON_THROW_ON_ERROR));
		} catch (\JsonException $exception) {
			throw new StoreException('Unable to encode the Store key description.', 0, $exception);
		}
	}

	/**
	 * Reads a key description produced by toTransport().
	 *
	 * @param string $transport Encoded key description.
	 * @return self           Resolved key.
	 * @throws StoreException If the description is invalid.
	 */
	public static function fromTransport(string $transport) : self
	{
		if (!str_starts_with($transport, 'STOREKEY/1:')) {
			return self::passphrase($transport);
		}

		$encoded = base64_decode(substr($transport, 11), true);
		if ($encoded === false) {
			throw new StoreException('Invalid Store key description.');
		}

		try {
			$data = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $exception) {
			throw new StoreException('Invalid Store key description.', 0, $exception);
		}
		if (!is_array($data) || !is_string($data['type'] ?? null) || !is_string($data['material'] ?? null)) {
			throw new StoreException('Invalid Store key description fields.');
		}

		try {
			$type = StoreKeyType::from($data['type']);
		} catch (\ValueError $exception) {
			throw new StoreException('Unsupported Store key type.', 0, $exception);
		}

		$material = base64_decode($data['material'], true);
		if ($material === false || $material === '' || ($type === StoreKeyType::Raw && strlen($material) !== 32)) {
			throw new StoreException('Invalid Store key material.');
		}

		return new self($type, $material, true);
	}

	private function readFile() : string
	{
		$contents = file_get_contents($this->value);
		if ($contents === false || trim($contents) === '') {
			throw new StoreException("Unable to read Store key file '{$this->value}'.");
		}

		return trim($contents);
	}

	private function readEnvironment() : string
	{
		$value = getenv($this->value);
		if ($value === false || trim($value) === '') {
			throw new StoreException("Store key environment variable '{$this->value}' is missing.");
		}

		return $value;
	}
}
