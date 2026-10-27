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

namespace zwuiix\store\storage;

use zwuiix\store\exception\StoreException;

use function base64_decode;
use function dirname;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function fopen;
use function function_exists;
use function hash;
use function hash_equals;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;

use function mkdir;
use function parse_url;
use function preg_match;
use function preg_quote;
use function rename;
use function sodium_crypto_sign_verify_detached;
use function stream_context_create;
use function stream_get_contents;
use function stream_get_meta_data;
use function strlen;
use function strtolower;
use function tempnam;
use function time;
use function touch;
use function trim;
use function unlink;
use const JSON_THROW_ON_ERROR;
use const PHP_URL_HOST;
use const PHP_URL_SCHEME;
use const SODIUM_CRYPTO_SIGN_BYTES;
use const SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;

/**
 * Fetches remote Store documents with an optional stale cache fallback.
 */
final class StoreRemote
{
	private function __construct()
	{
	}

	/**
	 * Downloads a remote document or returns a usable cached copy.
	 *
	 * @param string      $url       HTTP or HTTPS URL.
	 * @param string|null $cacheFile Local cache file.
	 * @param int         $ttl       Fresh cache lifetime in seconds.
	 * @param int         $timeout   Network timeout in seconds.
	 * @param string|null $sha256    Expected SHA-256 content signature.
	 * @param string|null $signature Ed25519 detached signature, base64 encoded.
	 * @param string|null $publicKey Ed25519 public key, raw or base64 encoded.
	 * @return string         Remote or cached content.
	 * @throws StoreException If the URL and cache are unusable.
	 */
	public static function fetch(
		string $url,
		?string $cacheFile = null,
		int $ttl = 300,
		int $timeout = 5,
		?string $sha256 = null,
		?string $signature = null,
		?string $publicKey = null,
	) : string
	{
		$scheme = parse_url($url, PHP_URL_SCHEME);
		$host = parse_url($url, PHP_URL_HOST);
		if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true) || !is_string($host) || $host === '') {
			throw new StoreException('Store remote sources require a valid HTTP or HTTPS URL.');
		}
		if ($ttl < 0 || $timeout < 1) {
			throw new StoreException('Store remote cache TTL and timeout must be valid positive values.');
		}
		if ($sha256 !== null && preg_match('/^[a-f0-9]{64}$/i', $sha256) !== 1) {
			throw new StoreException('A remote Store SHA-256 signature must contain 64 hexadecimal characters.');
		}
		if (($signature === null) !== ($publicKey === null)) {
			throw new StoreException('A remote Store Ed25519 signature requires both a signature and a public key.');
		}

		if ($cacheFile !== null && is_file($cacheFile) && filemtime($cacheFile) !== false && filemtime($cacheFile) + $ttl >= time()) {
			return self::readCache($cacheFile, $sha256, $signature, $publicKey);
		}

		$metadata = $cacheFile === null ? [] : self::readMetadata($cacheFile);
		$headers = "User-Agent: Store/1.0\r\n";
		if (is_string($metadata['etag'] ?? null) && $metadata['etag'] !== '') {
			$headers .= 'If-None-Match: ' . $metadata['etag'] . "\r\n";
		}
		if (is_string($metadata['last_modified'] ?? null) && $metadata['last_modified'] !== '') {
			$headers .= 'If-Modified-Since: ' . $metadata['last_modified'] . "\r\n";
		}

		$context = stream_context_create([
			'http' => [
				'timeout' => $timeout,
				'ignore_errors' => true,
				'header' => $headers,
			],
			'ssl' => [
				'verify_peer' => true,
				'verify_peer_name' => true,
			],
		]);
		$stream = @fopen($url, 'rb', false, $context);
		$responseHeaders = [];
		$contents = false;
		if ($stream !== false) {
			$metadata = stream_get_meta_data($stream);
			$responseHeaders = is_array($metadata['wrapper_data'] ?? null) ? $metadata['wrapper_data'] : [];
			$contents = stream_get_contents($stream);
			fclose($stream);
		}
		$status = self::statusCode($responseHeaders);
		if ($status === 304 && $cacheFile !== null && is_file($cacheFile)) {
			$cached = self::readCache($cacheFile, $sha256, $signature, $publicKey);
			@touch($cacheFile);

			return $cached;
		}
		if ($status >= 400) {
			$contents = false;
		}
		if ($contents !== false && $contents !== '') {
			if ($cacheFile !== null) {
				self::assertContent($contents, $sha256, $signature, $publicKey);
				self::writeCache($cacheFile, $contents, [
					'etag' => self::header($responseHeaders, 'etag'),
					'last_modified' => self::header($responseHeaders, 'last-modified'),
				]);
			} else {
				self::assertContent($contents, $sha256, $signature, $publicKey);
			}

			return $contents;
		}

		if ($cacheFile !== null && is_file($cacheFile)) {
			return self::readCache($cacheFile, $sha256, $signature, $publicKey);
		}

		throw new StoreException("Unable to download Store source '{$url}'.");
	}

	/**
	 * Stores a downloaded document atomically.
	 *
	 * @param string $path     Cache path.
	 * @param string $contents Document content.
	 * @return void
	 * @throws StoreException If the cache cannot be written.
	 */
	private static function writeCache(string $path, string $contents, array $metadata = []) : void
	{
		$directory = dirname($path);
		if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
			throw new StoreException("Unable to create Store remote cache directory '{$directory}'.");
		}

		$temp = tempnam($directory, '.store-remote-');
		if ($temp === false || file_put_contents($temp, $contents) === false || !rename($temp, $path)) {
			if ($temp !== false && is_file($temp)) {
				@unlink($temp);
			}

			throw new StoreException("Unable to write Store remote cache '{$path}'.");
		}
		if ($metadata !== []) {
			try {
				$encoded = json_encode($metadata, JSON_THROW_ON_ERROR);
			} catch (\JsonException $exception) {
				throw new StoreException('Unable to encode Store remote cache metadata.', 0, $exception);
			}
			if (file_put_contents($path . '.meta.json', $encoded) === false) {
				throw new StoreException("Unable to write Store remote cache metadata '{$path}.meta.json'.");
			}
		}
	}

	/** @return array<string,mixed> */
	private static function readMetadata(string $cacheFile) : array
	{
		$contents = @file_get_contents($cacheFile . '.meta.json');
		if ($contents === false) {
			return [];
		}

		$metadata = json_decode($contents, true);

		return is_array($metadata) ? $metadata : [];
	}

	private static function readCache(string $path, ?string $sha256, ?string $signature, ?string $publicKey) : string
	{
		$cached = file_get_contents($path);
		if ($cached === false) {
			throw new StoreException("Unable to read Store remote cache '{$path}'.");
		}
		self::assertContent($cached, $sha256, $signature, $publicKey);

		return $cached;
	}

	private static function assertHash(string $contents, ?string $sha256) : void
	{
		if ($sha256 !== null && !hash_equals(strtolower($sha256), hash('sha256', $contents))) {
			throw new StoreException('Remote Store content does not match its expected SHA-256 signature.');
		}
	}

	private static function assertContent(string $contents, ?string $sha256, ?string $signature, ?string $publicKey) : void
	{
		self::assertHash($contents, $sha256);
		if ($signature === null || $publicKey === null) {
			return;
		}
		if (!function_exists('sodium_crypto_sign_verify_detached')) {
			throw new StoreException('Ed25519 verification requires the sodium extension.');
		}

		$decodedSignature = base64_decode($signature, true);
		$decodedKey = base64_decode($publicKey, true);
		$decodedSignature = $decodedSignature !== false && strlen($decodedSignature) === SODIUM_CRYPTO_SIGN_BYTES
			? $decodedSignature
			: $signature;
		$decodedKey = $decodedKey !== false && strlen($decodedKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
			? $decodedKey
			: $publicKey;
		if (strlen($decodedSignature) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($decodedKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || !sodium_crypto_sign_verify_detached($decodedSignature, $contents, $decodedKey)) {
			throw new StoreException('Remote Store content has an invalid Ed25519 signature.');
		}
	}

	/** @param list<string> $headers */
	private static function statusCode(array $headers) : int
	{
		$status = 0;
		foreach ($headers as $line) {
			if (preg_match('/^HTTP\/\S+\s+(\d{3})/i', $line, $matches) === 1) {
				$status = (int) $matches[1];
			}
		}

		return $status;
	}

	/** @param list<string> $headers */
	private static function header(array $headers, string $name) : ?string
	{
		$value = null;
		foreach ($headers as $line) {
			if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/i', $line, $matches) === 1) {
				$value = trim($matches[1]);
			}
		}

		return $value;
	}
}
