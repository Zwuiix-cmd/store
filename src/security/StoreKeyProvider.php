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

use function file_get_contents;
use function gethostname;
use function hash;
use function is_file;
use function is_string;
use function php_uname;
use function substr;
use function trim;

/**
 * Resolves encryption keys for encrypted Store files.
 *
 * An explicit key should be used when an encrypted file must be shared between
 * machines. When no key is supplied, a stable key is derived from the local
 * machine identity so the file can be opened again by the same installation.
 */
final class StoreKeyProvider
{
	private const array MACHINE_ID_FILES = [
		'/etc/machine-id',
		'/var/lib/dbus/machine-id',
	];

	private function __construct()
	{
	}

	/**
	 * Resolves an explicit key or derives one for the current machine.
	 *
	 * @param string|null $key Explicit encryption key, or null for machine mode.
	 */
	public static function resolve(?string $key = null) : string
	{
		if ($key !== null && trim($key) !== '') {
			return $key;
		}

		return self::machineKey();
	}

	/**
	 * Resolves a public StoreKey or legacy string into a StoreKey instance.
	 *
	 * @param StoreKey|string|null $key Explicit key, passphrase or machine mode.
	 * @return StoreKey             Resolved key descriptor.
	 */
	public static function resolveKey(StoreKey|string|null $key = null) : StoreKey
	{
		if ($key instanceof StoreKey) {
			return $key;
		}

		return $key === null ? StoreKey::machine() : StoreKey::passphrase($key);
	}

	/**
	 * Returns the stable key derived from the current machine identity.
	 *
	 * @return string Machine-derived key material.
	 */
	public static function machineKey() : string
	{
		return hash('sha256', 'store-key-v1:' . self::machineId());
	}

	/**
	 * Returns a short fingerprint that can be logged without exposing the key.
	 *
	 * @param string $key Encryption key to fingerprint.
	 * @return string The first sixteen hexadecimal characters of the key hash.
	 */
	public static function fingerprint(string $key) : string
	{
		return substr(hash('sha256', $key), 0, 16);
	}

	private static function machineId() : string
	{
		foreach (self::MACHINE_ID_FILES as $path) {
			if (!is_file($path)) {
				continue;
			}

			$value = file_get_contents($path);
			if (is_string($value) && trim($value) !== '') {
				return trim($value);
			}
		}

		$host = gethostname();
		if (is_string($host) && trim($host) !== '') {
			return trim($host);
		}

		return trim(php_uname('n')) ?: 'store-local-machine';
	}
}
