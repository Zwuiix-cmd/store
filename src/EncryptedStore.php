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

namespace zwuiix\store;

use pocketmine\promise\Promise;
use pocketmine\promise\PromiseResolver;
use pocketmine\Server;
use zwuiix\store\async\EncryptedStoreKeyringLoadAsyncTask;
use zwuiix\store\exception\StoreException;
use zwuiix\store\security\StoreCipher;
use zwuiix\store\security\StoreEncryptionOptions;
use zwuiix\store\security\StoreKey;
use zwuiix\store\security\StoreKeyProvider;
use zwuiix\store\security\StoreKeyring;
use zwuiix\store\security\StoreKeyType;
use zwuiix\store\storage\StoreBackupPolicy;
use zwuiix\store\storage\StoreFilePolicy;
use function chmod;
use function file_get_contents;
use function is_file;

/**
 * Store with authenticated encryption and synchronous or asynchronous I/O.
 *
 * Existing string keys remain passphrases for compatibility. StoreKey adds
 * explicit raw, machine, file and environment-backed key sources, while
 * StoreCipher selects AES-256-GCM or XChaCha20-Poly1305-IETF for new files.
 */
final class EncryptedStore extends Store
{
	private string $encryptionKey;

	private StoreKey $key;

	private StoreCipher $cipher;

	private StoreEncryptionOptions $encryption;

	/**
	 * Creates an encrypted Store.
	 *
	 * @param string                      $file           File path, or an empty string for an in-memory Store.
	 * @param array<string, mixed>        $defaults       Values used when keys are absent from the file.
	 * @param StoreKey|string|null        $key            Encryption key, passphrase or key source.
	 * @param array<string, mixed>|null   $initialData    Pre-decoded values used by asynchronous loading.
	 * @param StoreCipher                 $cipher         Authenticated encryption algorithm for new files.
	 * @param StoreEncryptionOptions|null $encryption     Encryption and key derivation settings.
	 * @param StoreFilePolicy|null        $filePolicy     File mode and locking policy.
	 * @param StoreBackupPolicy|null      $backupPolicy   Backup policy applied before replacement.
	 * @param bool                        $dispatchEvents Whether public events should be emitted.
	 * @throws StoreException              If the encrypted file cannot be read, decoded or created.
	 */
	public function __construct(
		string                  $file,
		array                   $defaults = [],
		StoreKey|string|null    $key = null,
		?array                  $initialData = null,
		StoreCipher             $cipher = StoreCipher::Aes256Gcm,
		?StoreEncryptionOptions $encryption = null,
		?StoreFilePolicy        $filePolicy = null,
		?StoreBackupPolicy      $backupPolicy = null,
		bool                    $dispatchEvents = true,
	) {
		$this->key = StoreKeyProvider::resolveKey($key);
		$this->encryptionKey = $this->key->material();
		$this->encryption = $encryption ?? StoreEncryptionOptions::forCipher($cipher);
		$this->cipher = $this->encryption->cipher();
		$policy = $filePolicy ?? StoreFilePolicy::secure();
		parent::__construct($file, StoreFormat::Encrypted, $defaults, $this->key->toTransport($this->encryption), $initialData, $policy, $backupPolicy ?? StoreBackupPolicy::disabled(), null, $dispatchEvents);
		if ($file !== '' && is_file($file)) {
			chmod($file, $policy->mode());
		}
	}

	/**
	 * Opens an encrypted Store file.
	 *
	 * @param string                      $file           File to open.
	 * @param StoreFormat|int|null        $format         Must be null or StoreFormat::Encrypted.
	 * @param array<string, mixed>        $defaults       Values used when keys are absent from the file.
	 * @param string|null                 $passphrase     Legacy passphrase argument.
	 * @param StoreKey|string|null        $key            Explicit key, passphrase or key source.
	 * @param StoreCipher                 $cipher         Cipher used for new files.
	 * @param StoreEncryptionOptions|null $encryption     Encryption settings.
	 * @param StoreFilePolicy|null        $filePolicy     File policy.
	 * @param StoreBackupPolicy|null      $backupPolicy   Backup policy.
	 * @param bool                        $dispatchEvents Whether public events should be emitted.
	 * @return self                        Loaded encrypted Store.
	 * @throws StoreException              If the format or file is invalid.
	 */
	public static function open(
		string                  $file,
		StoreFormat|int|null    $format = null,
		array                   $defaults = [],
		?string                 $passphrase = null,
		StoreKey|string|null    $key = null,
		StoreCipher             $cipher = StoreCipher::Aes256Gcm,
		?StoreEncryptionOptions $encryption = null,
		?StoreFilePolicy        $filePolicy = null,
		?StoreBackupPolicy      $backupPolicy = null,
		bool                    $dispatchEvents = true,
	) : self {
		self::assertEncryptedFormat($format);

		return new self($file, $defaults, $key ?? $passphrase, null, $cipher, $encryption, $filePolicy, $backupPolicy, $dispatchEvents);
	}

	/**
	 * Opens an encrypted Store with an explicit key source.
	 *
	 * @param string                      $file           File to open.
	 * @param StoreKey|string|null        $key            Explicit key, passphrase or key source.
	 * @param array<string, mixed>        $defaults       Values used when keys are absent.
	 * @param StoreCipher                 $cipher         Cipher used for new files.
	 * @param StoreEncryptionOptions|null $encryption     Encryption settings.
	 * @param StoreFilePolicy|null        $filePolicy     File policy.
	 * @param StoreBackupPolicy|null      $backupPolicy   Backup policy.
	 * @param bool                        $dispatchEvents Whether public events should be emitted.
	 * @param bool                        $rotate         Rewrite old-key files with the primary key.
	 * @return self                        Loaded encrypted Store.
	 */
	public static function openWithKey(
		string                  $file,
		StoreKey|string|null    $key = null,
		array                   $defaults = [],
		StoreCipher             $cipher = StoreCipher::Aes256Gcm,
		?StoreEncryptionOptions $encryption = null,
		?StoreFilePolicy        $filePolicy = null,
		?StoreBackupPolicy      $backupPolicy = null,
		bool                    $dispatchEvents = true,
		bool                    $rotate = true,
	) : self {
		return new self($file, $defaults, $key, null, $cipher, $encryption, $filePolicy, $backupPolicy, $dispatchEvents);
	}

	/**
	 * Opens a file by trying the registered keys in order.
	 *
	 * @param string                      $file           File to open.
	 * @param StoreKeyring                $keyring        Candidate keys in lookup order.
	 * @param array<string, mixed>        $defaults       Values used when keys are absent.
	 * @param StoreCipher                 $cipher         Cipher used for new files.
	 * @param StoreEncryptionOptions|null $encryption     Encryption settings.
	 * @param StoreBackupPolicy|null      $backupPolicy   Backup policy.
	 * @param bool                        $dispatchEvents Whether public events should be emitted.
	 * @return self                        Loaded encrypted Store.
	 * @throws StoreException              If every key fails.
	 */
	public static function openWithKeyring(
		string                  $file,
		StoreKeyring            $keyring,
		array                   $defaults = [],
		StoreCipher             $cipher = StoreCipher::Aes256Gcm,
		?StoreEncryptionOptions $encryption = null,
		?StoreBackupPolicy      $backupPolicy = null,
		bool                    $dispatchEvents = true,
		bool                    $rotate = true,
	) : self {
		if (!is_file($file)) {
			return new self($file, $defaults, $keyring->primary(), null, $cipher, $encryption, null, $backupPolicy, $dispatchEvents);
		}

		$contents = file_get_contents($file);
		if ($contents === false) {
			throw new StoreException("Unable to read encrypted Store file '{$file}'.");
		}

		$lastException = null;
		$index = 0;
		foreach ($keyring->all() as $key) {
			try {
				$store = self::fromString($contents, StoreFormat::Encrypted, $file, null, $defaults, $key, $cipher, $encryption, null, $backupPolicy, $dispatchEvents);
				if ($rotate && $index > 0) {
					$store->rotateKey($keyring->primary());
				}

				return $store;
			} catch (StoreException $exception) {
				$lastException = $exception;
			}
			$index++;
		}

		throw $lastException ?? new StoreException('No usable Store key was registered.');
	}

	/**
	 * Opens a file by trying the registered keys in an async worker.
	 *
	 * The key source that decrypts the file becomes the active write key. This
	 * keeps key rotation safe while moving file reading and decryption off the
	 * main thread.
	 *
	 * @param string                      $file           File to open.
	 * @param StoreKeyring                $keyring        Candidate keys in lookup order.
	 * @param array<string, mixed>        $defaults       Values used when keys are absent.
	 * @param StoreCipher                 $cipher         Cipher used for new files.
	 * @param StoreEncryptionOptions|null $encryption     Encryption settings.
	 * @param StoreFilePolicy|null        $filePolicy     File policy.
	 * @param StoreBackupPolicy|null      $backupPolicy   Backup policy.
	 * @param bool                        $dispatchEvents Whether public events should be emitted.
	 * @param bool                        $rotate         Rewrite old-key files with the primary key.
	 * @return Promise<self>               Promise resolving to the decrypted Store.
	 */
	public static function openWithKeyringAsync(
		string                  $file,
		StoreKeyring            $keyring,
		array                   $defaults = [],
		StoreCipher             $cipher = StoreCipher::Aes256Gcm,
		?StoreEncryptionOptions $encryption = null,
		?StoreFilePolicy        $filePolicy = null,
		?StoreBackupPolicy      $backupPolicy = null,
		bool                    $dispatchEvents = true,
		bool                    $rotate = true,
	) : Promise {
		if (!is_file($file)) {
			return self::openAsync($file, StoreFormat::Encrypted, $defaults, null, $keyring->primary(), $cipher, $encryption, $filePolicy, $backupPolicy, $dispatchEvents);
		}

		$settings = $encryption ?? StoreEncryptionOptions::forCipher($cipher);
		$transports = [];
		foreach ($keyring->all() as $key) {
			$transports[] = $key->toTransport($settings);
		}

		$resolver = new PromiseResolver();
		$workerResolver = new PromiseResolver();
		$primary = $keyring->primary();
		$workerResolver->getPromise()->onCompletion(function(array $result) use ($file, $defaults, $cipher, $settings, $filePolicy, $backupPolicy, $dispatchEvents, $resolver, $primary, $rotate) : void {
			try {
				$store = new self(
					$file,
					$defaults,
					StoreKey::fromTransport($result['transport']),
					null,
					$cipher,
					$settings,
					$filePolicy,
					$backupPolicy,
					$dispatchEvents,
				);
				if ($rotate && (($result['index'] ?? 0) > 0)) {
					$store->rotateKey($primary);
				}
				$resolver->resolve($store);
			} catch (\Throwable) {
				$resolver->reject();
			}
		}, static function() use ($resolver) : void {
			$resolver->reject();
		});
		Server::getInstance()->getAsyncPool()->submitTask(new EncryptedStoreKeyringLoadAsyncTask($file, $defaults, $transports, $workerResolver));

		return $resolver->getPromise();
	}

	/**
	 * Creates an encrypted Store from an array.
	 *
	 * @param array<string, mixed>        $values         Initial Store values.
	 * @param StoreFormat|int|null        $format         Must be null or StoreFormat::Encrypted.
	 * @param string|null                 $path           Optional path associated with the Store.
	 * @param string|null                 $passphrase     Legacy passphrase argument.
	 * @param StoreKey|string|null        $key            Explicit key, passphrase or key source.
	 * @param StoreCipher                 $cipher         Cipher used for new files.
	 * @param StoreEncryptionOptions|null $encryption     Encryption settings.
	 * @param StoreFilePolicy|null        $filePolicy     File policy.
	 * @param StoreBackupPolicy|null      $backupPolicy   Backup policy.
	 * @param bool                        $dispatchEvents Whether public events should be emitted.
	 * @return self                        Encrypted Store instance.
	 */
	public static function fromArray(
		array                   $values,
		StoreFormat|int|null    $format = null,
		?string                 $path = null,
		?string                 $passphrase = null,
		StoreKey|string|null    $key = null,
		StoreCipher             $cipher = StoreCipher::Aes256Gcm,
		?StoreEncryptionOptions $encryption = null,
		?StoreFilePolicy        $filePolicy = null,
		?StoreBackupPolicy      $backupPolicy = null,
		bool                    $dispatchEvents = true,
	) : self {
		self::assertEncryptedFormat($format);

		return new self($path ?? '', [], $key ?? $passphrase, $values, $cipher, $encryption, $filePolicy, $backupPolicy, $dispatchEvents);
	}

	/**
	 * Creates an encrypted Store from encoded content.
	 *
	 * @param string                      $contents       Encrypted Store content.
	 * @param StoreFormat                 $format         Must be StoreFormat::Encrypted.
	 * @param string|null                 $path           Optional path associated with the Store.
	 * @param string|null                 $passphrase     Legacy passphrase argument.
	 * @param array<string, mixed>        $defaults       Values used when keys are absent.
	 * @param StoreKey|string|null        $key            Explicit key, passphrase or key source.
	 * @param StoreCipher                 $cipher         Cipher used for new files.
	 * @param StoreEncryptionOptions|null $encryption     Encryption settings.
	 * @param StoreFilePolicy|null        $filePolicy     File policy.
	 * @param StoreBackupPolicy|null      $backupPolicy   Backup policy.
	 * @param bool                        $dispatchEvents Whether public events should be emitted.
	 * @return self                        Decrypted Store instance.
	 */
	public static function fromString(
		string                  $contents,
		StoreFormat             $format = StoreFormat::Encrypted,
		?string                 $path = null,
		?string                 $passphrase = null,
		array                   $defaults = [],
		StoreKey|string|null    $key = null,
		StoreCipher             $cipher = StoreCipher::Aes256Gcm,
		?StoreEncryptionOptions $encryption = null,
		?StoreFilePolicy        $filePolicy = null,
		?StoreBackupPolicy      $backupPolicy = null,
		bool                    $dispatchEvents = true,
	) : self {
		self::assertEncryptedFormat($format);
		$resolvedKey = StoreKeyProvider::resolveKey($key ?? $passphrase);
		$settings = $encryption ?? StoreEncryptionOptions::forCipher($cipher);
		$data = StoreCodecRegistry::get(StoreFormat::Encrypted)->decode($contents, $resolvedKey->toTransport($settings));

		return new self($path ?? '', $defaults, $key ?? $passphrase, $data, $cipher, $encryption, $filePolicy, $backupPolicy, $dispatchEvents);
	}

	/**
	 * Opens an encrypted Store without reading on the main thread.
	 *
	 * @param string                      $file           File to open.
	 * @param StoreFormat|int|null        $format         Must be null or StoreFormat::Encrypted.
	 * @param array<string, mixed>        $defaults       Values used when keys are absent.
	 * @param string|null                 $passphrase     Legacy passphrase argument.
	 * @param StoreKey|string|null        $key            Explicit key, passphrase or key source.
	 * @param StoreCipher                 $cipher         Cipher used for new files.
	 * @param StoreEncryptionOptions|null $encryption     Encryption settings.
	 * @param StoreFilePolicy|null        $filePolicy     File policy.
	 * @param StoreBackupPolicy|null      $backupPolicy   Backup policy.
	 * @param bool                        $dispatchEvents Whether public events should be emitted.
	 * @return Promise<self>               Promise resolving to the encrypted Store.
	 */
	public static function openAsync(
		string                  $file,
		StoreFormat|int|null    $format = null,
		array                   $defaults = [],
		?string                 $passphrase = null,
		StoreKey|string|null    $key = null,
		StoreCipher             $cipher = StoreCipher::Aes256Gcm,
		?StoreEncryptionOptions $encryption = null,
		?StoreFilePolicy        $filePolicy = null,
		?StoreBackupPolicy      $backupPolicy = null,
		bool                    $dispatchEvents = true,
	) : Promise {
		self::assertEncryptedFormat($format);
		$resolver = new PromiseResolver();
		$resolvedKey = StoreKeyProvider::resolveKey($key ?? $passphrase);
		$settings = $encryption ?? StoreEncryptionOptions::forCipher($cipher);
		$transport = $resolvedKey->toTransport($settings);
		$loaded = parent::openAsyncInternal($file, StoreFormat::Encrypted, $defaults, $transport, false);
		$loaded->onCompletion(static function(Store $store) use ($file, $transport, $cipher, $settings, $filePolicy, $backupPolicy, $dispatchEvents, $resolver) : void {
			try {
				$resolver->resolve(new self($file, [], StoreKey::fromTransport($transport), $store->all(), $cipher, $settings, $filePolicy, $backupPolicy, $dispatchEvents));
			} catch (\Throwable) {
				$resolver->reject();
			}
		}, static function() use ($resolver) : void {
			$resolver->reject();
		});

		return $resolver->getPromise();
	}

	/**
	 * Returns whether the key was derived from the local machine identity.
	 *
	 * @return bool True when machine mode is active.
	 */
	public function usesMachineKey() : bool
	{
		return $this->key->type() === StoreKeyType::Machine;
	}

	/**
	 * Returns the configured key source type.
	 *
	 * @return StoreKeyType Key source type.
	 */
	public function keyType() : StoreKeyType
	{
		return $this->key->type();
	}

	/**
	 * Returns the cipher used for newly written documents.
	 *
	 * @return StoreCipher Configured cipher.
	 */
	public function cipher() : StoreCipher
	{
		return $this->cipher;
	}

	/**
	 * Returns a safe fingerprint for the active encryption key.
	 *
	 * @return string A stable sixteen-character hexadecimal fingerprint.
	 */
	public function keyFingerprint() : string
	{
		return StoreKeyProvider::fingerprint($this->encryptionKey);
	}

	/**
	 * Re-encrypts the current values with a new key and optionally new settings.
	 *
	 * @param StoreKey|string|null        $key        New key or machine mode.
	 * @param StoreCipher|null            $cipher     New cipher, or the current one.
	 * @param StoreEncryptionOptions|null $encryption New encryption settings.
	 * @param bool                        $async      Whether the replacement is async.
	 * @return Promise<bool>|null          Async result, or null after a sync write.
	 */
	public function rotateKey(
		StoreKey|string|null    $key,
		?StoreCipher            $cipher = null,
		?StoreEncryptionOptions $encryption = null,
		bool                    $async = false,
	) : ?Promise {
		$this->key = StoreKeyProvider::resolveKey($key);
		$this->encryptionKey = $this->key->material();
		$this->encryption = $encryption ?? ($cipher === null ? $this->encryption : StoreEncryptionOptions::forCipher($cipher));
		$this->cipher = $this->encryption->cipher();
		$this->replaceCodecSecret($this->key->toTransport($this->encryption));
		if ($this->path() === '') {
			return null;
		}
		if ($async) {
			return $this->save(async: true);
		}

		$this->save();

		return null;
	}

	/**
	 * Returns the active encryption settings.
	 *
	 * @return StoreEncryptionOptions Encryption settings.
	 */
	public function encryption() : StoreEncryptionOptions
	{
		return $this->encryption;
	}

	private static function assertEncryptedFormat(StoreFormat|int|null $format) : void
	{
		if ($format !== null && $format !== StoreFormat::Encrypted) {
			throw new StoreException('EncryptedStore only supports the encrypted format.');
		}
	}
}
