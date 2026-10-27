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

namespace zwuiix\store\codec;

use JsonException;
use zwuiix\store\exception\StoreCorruptedException;
use zwuiix\store\exception\StoreDecryptionException;
use zwuiix\store\exception\StoreException;
use zwuiix\store\security\StoreCipher;
use zwuiix\store\security\StoreEncryptionOptions;
use zwuiix\store\security\StoreKdf;
use zwuiix\store\security\StoreKey;
use zwuiix\store\security\StoreKeyType;
use zwuiix\store\StoreCodecInterface;

use function base64_decode;
use function base64_encode;
use function function_exists;
use function gzcompress;
use function gzuncompress;
use function hash_pbkdf2;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function openssl_decrypt;
use function openssl_encrypt;
use function random_bytes;
use function sodium_crypto_aead_xchacha20poly1305_ietf_decrypt;
use function sodium_crypto_aead_xchacha20poly1305_ietf_encrypt;
use function sodium_crypto_pwhash;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const OPENSSL_RAW_DATA;
use const SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13;

/**
 * Encodes and decodes authenticated encrypted Store documents.
 *
 * STORE/1 remains readable for existing files. New key descriptors use the
 * STORE/2 envelope and can select AES-256-GCM or XChaCha20-Poly1305-IETF.
 */
final class EncryptedCodec implements StoreCodecInterface
{
	private const string LEGACY_HEADER = "STORE/1\n";

	private const string HEADER = "STORE/2\n";

	private const int ITERATIONS = 150000;

	private const int SALT_LENGTH = 16;

	/**
	 * Decodes and decrypts a Store envelope.
	 *
	 * @param string      $contents   Encrypted document.
	 * @param string|null $passphrase Legacy passphrase or key descriptor.
	 * @return array<string, mixed> Decrypted Store values.
	 * @throws StoreException       If the envelope, key or decrypted content is invalid.
	 */
	public function decode(string $contents, ?string $passphrase = null) : array
	{
		$this->assertPassphrase($passphrase);
		$version = str_starts_with($contents, self::LEGACY_HEADER) ? 1 : (str_starts_with($contents, self::HEADER) ? 2 : 0);
		if ($version === 0) {
			throw new StoreCorruptedException('Invalid encrypted Store header.');
		}

		$headerLength = $version === 1 ? strlen(self::LEGACY_HEADER) : strlen(self::HEADER);
		$envelope = $this->decodeEnvelope(substr($contents, $headerLength));
		$decoded = $version === 1
			? $this->decryptLegacy($envelope, $passphrase)
			: $this->decryptV2($envelope, $passphrase);

		if ($version === 2 && (($envelope['compression'] ?? null) === 'gzip')) {
			if (!function_exists('gzuncompress')) {
				throw new StoreCorruptedException('Compressed Store encryption requires ext-zlib.');
			}
			try {
				$decoded = gzuncompress($decoded);
			} catch (\Throwable $exception) {
				throw new StoreCorruptedException('Unable to decompress encrypted Store content.', 0, $exception);
			}
			if ($decoded === false) {
				throw new StoreCorruptedException('Unable to decompress encrypted Store content.');
			}
		}

		try {
			$data = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new StoreCorruptedException('Decrypted store contains invalid data.', 0, $exception);
		}
		if (!is_array($data)) {
			throw new StoreCorruptedException('Decrypted store must contain an object at its root.');
		}

		return $data;
	}

	/**
	 * Encodes and encrypts Store values.
	 *
	 * A normal string keeps the legacy AES-256-GCM format. StoreKey transport
	 * values produce the versioned envelope with the selected cipher.
	 *
	 * @param array<string, mixed> $data       Values to encrypt.
	 * @param string|null          $passphrase Legacy passphrase or key descriptor.
	 * @return string               Encrypted Store envelope.
	 * @throws StoreException       If the values cannot be encoded or encrypted.
	 */
	public function encode(array $data, ?string $passphrase = null) : string
	{
		$this->assertPassphrase($passphrase);
		try {
			$payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new StoreException('Unable to encode encrypted store.', 0, $exception);
		}
		if (!str_starts_with($passphrase, 'STOREKEY/1:')) {
			return $this->encodeLegacy($payload, $passphrase);
		}

		$key = StoreKey::fromTransport($passphrase);
		$options = $this->optionsFromTransport($passphrase);

		return $this->encodeV2($payload, $key, $options);
	}

	/**
	 * @param string $contents Base64 envelope content.
	 * @return array<string, mixed> Decoded envelope.
	 * @throws StoreException       If the envelope is malformed.
	 */
	private function decodeEnvelope(string $contents) : array
	{
		$encoded = base64_decode(trim($contents), true);
		if ($encoded === false) {
			throw new StoreCorruptedException('Invalid base64 data in encrypted Store.');
		}

		try {
			$envelope = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new StoreCorruptedException('Invalid encrypted Store envelope.', 0, $exception);
		}
		if (!is_array($envelope)) {
			throw new StoreCorruptedException('Invalid encrypted Store envelope.');
		}

		return $envelope;
	}

	private function decryptLegacy(array $envelope, string $passphrase) : string
	{
		if (($envelope['version'] ?? null) !== 1 || ($envelope['cipher'] ?? null) !== StoreCipher::Aes256Gcm->value) {
			throw new StoreException('Unsupported encrypted Store version.');
		}

		foreach (['salt', 'iv', 'tag', 'data'] as $field) {
			if (!is_string($envelope[$field] ?? null)) {
				throw new StoreException("Encrypted store field '{$field}' is missing.");
			}
		}

		$salt = $this->decodeBase64($envelope['salt']);
		$iv = $this->decodeBase64($envelope['iv']);
		$tag = $this->decodeBase64($envelope['tag']);
		$data = $this->decodeBase64($envelope['data']);
		if (strlen($salt) !== self::SALT_LENGTH || strlen($iv) !== 12 || strlen($tag) !== 16) {
			throw new StoreCorruptedException('Encrypted Store contains invalid AES metadata.');
		}

		$material = str_starts_with($passphrase, 'STOREKEY/1:') ? StoreKey::fromTransport($passphrase)->material() : $passphrase;
		$key = hash_pbkdf2('sha256', $material, $salt, (int) ($envelope['iterations'] ?? self::ITERATIONS), 32, true);
		$decoded = openssl_decrypt($data, StoreCipher::Aes256Gcm->value, $key, OPENSSL_RAW_DATA, $iv, $tag);
		if ($decoded === false) {
			throw new StoreDecryptionException('Unable to decrypt store. The key may be invalid.');
		}

		return $decoded;
	}

	private function decryptV2(array $envelope, string $transport) : string
	{
		if (($envelope['version'] ?? null) !== 2 || !is_string($envelope['cipher'] ?? null)) {
			throw new StoreException('Unsupported encrypted Store version.');
		}

		$options = StoreEncryptionOptions::fromArray($envelope);
		$cipher = $options->cipher();
		$key = StoreKey::fromTransport($transport);
		$salt = $this->decodeBase64Field($envelope, 'salt', self::SALT_LENGTH);
		$nonce = $this->decodeBase64Field($envelope, 'nonce', $cipher === StoreCipher::Aes256Gcm ? 12 : 24);
		$data = $this->decodeBase64Field($envelope, 'data');
		$secret = $this->deriveKey($key, $salt, $options);
		$aad = isset($envelope['aad']) ? $this->authenticatedMetadata($envelope) : '';

		$decoded = match ($cipher) {
			StoreCipher::Aes256Gcm => $this->decryptAes($envelope, $data, $nonce, $secret, $aad),
			StoreCipher::XChaCha20Poly1305 => $this->decryptXChaCha($data, $nonce, $secret, $aad),
		};
		if ($decoded === false) {
			throw new StoreDecryptionException('Unable to decrypt store. The key may be invalid.');
		}

		return $decoded;
	}

	private function encodeLegacy(string $payload, string $passphrase) : string
	{
		$salt = random_bytes(self::SALT_LENGTH);
		$iv = random_bytes(12);
		$key = hash_pbkdf2('sha256', $passphrase, $salt, self::ITERATIONS, 32, true);
		$tag = '';
		$encrypted = openssl_encrypt($payload, StoreCipher::Aes256Gcm->value, $key, OPENSSL_RAW_DATA, $iv, $tag);
		if ($encrypted === false) {
			throw new StoreException('Unable to encrypt store with AES-256-GCM.');
		}

		return self::LEGACY_HEADER . base64_encode(json_encode([
			'version' => 1,
			'cipher' => StoreCipher::Aes256Gcm->value,
			'iterations' => self::ITERATIONS,
			'salt' => base64_encode($salt),
			'iv' => base64_encode($iv),
			'tag' => base64_encode($tag),
			'data' => base64_encode($encrypted),
		], JSON_THROW_ON_ERROR)) . "\n";
	}

	private function encodeV2(string $payload, StoreKey $key, StoreEncryptionOptions $options) : string
	{
		$cipher = $options->cipher();
		if ($options->kdf() === StoreKdf::Raw && $key->type() !== StoreKeyType::Raw) {
			throw new StoreException('The raw KDF requires a StoreKey::raw() key.');
		}
		if ($cipher === StoreCipher::XChaCha20Poly1305 && !function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
			throw new StoreException('XChaCha20-Poly1305 requires ext-sodium.');
		}
		$compressed = false;
		if ($options->compression()) {
			if (!function_exists('gzcompress')) {
				throw new StoreException('Compressed Store encryption requires ext-zlib.');
			}
			$payload = gzcompress($payload, 6);
			if ($payload === false) {
				throw new StoreException('Unable to compress Store content.');
			}
			$compressed = true;
		}

		$salt = random_bytes(self::SALT_LENGTH);
		$nonce = random_bytes($cipher === StoreCipher::Aes256Gcm ? 12 : 24);
		$secret = $this->deriveKey($key, $salt, $options);
		$tag = '';
		$metadata = [
			'version' => 2,
			'cipher' => $cipher->value,
			'key_type' => $key->type()->value,
			'kdf' => $key->type() === StoreKeyType::Raw ? StoreKdf::Raw->value : $options->kdf()->value,
			'iterations' => $options->iterations(),
			'memory' => $options->memoryLimit(),
			'time' => $options->timeLimit(),
			'threads' => $options->threads(),
			'salt' => base64_encode($salt),
			'nonce' => base64_encode($nonce),
		];
		if ($compressed) {
			$metadata['compression'] = 'gzip';
		}
		$metadata['aad'] = $this->authenticatedMetadata($metadata);
		$aad = $metadata['aad'];
		$encrypted = match ($cipher) {
			StoreCipher::Aes256Gcm => openssl_encrypt($payload, $cipher->value, $secret, OPENSSL_RAW_DATA, $nonce, $tag, $aad),
			StoreCipher::XChaCha20Poly1305 => sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($payload, $aad, $nonce, $secret),
		};
		if ($encrypted === false) {
			throw new StoreException('Unable to encrypt store.');
		}

		return self::HEADER . base64_encode(json_encode($metadata + [
			'tag' => base64_encode($tag),
			'data' => base64_encode($encrypted),
		], JSON_THROW_ON_ERROR)) . "\n";
	}

	private function deriveKey(StoreKey $key, string $salt, StoreEncryptionOptions $options) : string
	{
		$material = $key->material();
		if ($key->type() === StoreKeyType::Raw || $options->kdf() === StoreKdf::Raw) {
			return $material;
		}

		if ($options->kdf() === StoreKdf::Argon2id) {
			if (!function_exists('sodium_crypto_pwhash')) {
				throw new StoreException('XChaCha20-Poly1305 requires Argon2 support from ext-sodium.');
			}

			return sodium_crypto_pwhash(32, $material, $salt, $options->timeLimit(), $options->memoryLimit(), SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
		}

		return hash_pbkdf2('sha256', $material, $salt, $options->iterations(), 32, true);
	}

	private function decryptAes(array $envelope, string $data, string $nonce, string $secret, string $aad) : string|false
	{
		$tag = $this->decodeBase64Field($envelope, 'tag', 16);

		return openssl_decrypt($data, StoreCipher::Aes256Gcm->value, $secret, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
	}

	private function decryptXChaCha(string $data, string $nonce, string $secret, string $aad) : string|false
	{
		if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
			throw new StoreException('XChaCha20-Poly1305 requires ext-sodium.');
		}

		$decoded = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($data, $aad, $nonce, $secret);

		return $decoded === false ? false : $decoded;
	}

	private function optionsFromTransport(string $transport) : StoreEncryptionOptions
	{
		$encoded = base64_decode(substr($transport, 11), true);
		if ($encoded === false) {
			throw new StoreException('Invalid Store key description.');
		}

		try {
			$data = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
			if (!is_array($data)) {
				throw new StoreException('Invalid Store encryption settings.');
			}

			return StoreEncryptionOptions::fromArray(is_array($data['options'] ?? null) ? $data['options'] : $data);
		} catch (JsonException|\ValueError $exception) {
			throw new StoreException('Invalid Store encryption cipher.', 0, $exception);
		}
	}

	private function authenticatedMetadata(array $envelope) : string
	{
		$metadata = [
			'version' => $envelope['version'] ?? null,
			'cipher' => $envelope['cipher'] ?? null,
			'key_type' => $envelope['key_type'] ?? null,
			'kdf' => $envelope['kdf'] ?? null,
			'iterations' => $envelope['iterations'] ?? null,
			'memory' => $envelope['memory'] ?? null,
			'time' => $envelope['time'] ?? null,
			'threads' => $envelope['threads'] ?? null,
			'compression' => $envelope['compression'] ?? null,
			'salt' => $envelope['salt'] ?? null,
			'nonce' => $envelope['nonce'] ?? null,
		];
		try {
			$aad = json_encode($metadata, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new StoreException('Unable to authenticate Store metadata.', 0, $exception);
		}
		if (isset($envelope['aad']) && $envelope['aad'] !== $aad) {
			throw new StoreCorruptedException('Store encryption metadata is corrupted.');
		}

		return $aad;
	}

	private function decodeBase64Field(array $envelope, string $field, ?int $length = null) : string
	{
		if (!is_string($envelope[$field] ?? null)) {
			throw new StoreException("Encrypted store field '{$field}' is missing.");
		}

		$value = $this->decodeBase64($envelope[$field]);
		if ($length !== null && strlen($value) !== $length) {
			throw new StoreCorruptedException("Encrypted Store field '{$field}' has an invalid length.");
		}

		return $value;
	}

	private function decodeBase64(string $value) : string
	{
		$decoded = base64_decode($value, true);
		if ($decoded === false) {
			throw new StoreException('Encrypted store contains invalid base64 data.');
		}

		return $decoded;
	}

	private function assertPassphrase(?string $passphrase) : void
	{
		if ($passphrase === null || $passphrase === '') {
			throw new StoreException('An encryption key is required.');
		}
	}
}
