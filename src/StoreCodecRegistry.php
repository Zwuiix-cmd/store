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

use zwuiix\store\codec\EncryptedCodec;
use zwuiix\store\codec\EnvCodec;
use zwuiix\store\codec\IniCodec;
use zwuiix\store\codec\JsonCodec;
use zwuiix\store\codec\PropertiesCodec;
use zwuiix\store\codec\TomlCodec;
use zwuiix\store\codec\XmlCodec;
use zwuiix\store\codec\YamlCodec;
use function ltrim;
use function strtolower;

/**
 * Lazily creates and stores the codecs used by Store.
 */
final class StoreCodecRegistry
{
	/** @var array<string, StoreCodecInterface> */
	private static array $codecs = [];

	/** @var array<string, StoreCodecInterface> */
	private static array $extensions = [];

	private function __construct()
	{
	}

	/**
	 * Returns the codec registered for a format, creating the default codec lazily.
	 *
	 * @param StoreFormat $format Format whose codec should be returned.
	 * @return StoreCodecInterface The codec assigned to the format.
	 */
	public static function get(StoreFormat $format) : StoreCodecInterface
	{
		return self::$codecs[$format->value] ??= match ($format) {
			StoreFormat::Json => new JsonCodec(),
			StoreFormat::Env => new EnvCodec(),
			StoreFormat::Ini => new IniCodec(),
			StoreFormat::Properties => new PropertiesCodec(),
			StoreFormat::Toml => new TomlCodec(),
			StoreFormat::Yaml => new YamlCodec(),
			StoreFormat::Xml => new XmlCodec(),
			StoreFormat::Encrypted => new EncryptedCodec(),
		};
	}

	/**
	 * Replaces or adds a codec for a format.
	 *
	 * @param StoreFormat         $format Format handled by the codec.
	 * @param StoreCodecInterface $codec  Codec implementation to register.
	 * @return void
	 */
	public static function register(StoreFormat $format, StoreCodecInterface $codec) : void
	{
		self::$codecs[$format->value] = $codec;
	}

	/**
	 * Registers a codec for a custom file extension.
	 *
	 * The extension is normalized without a leading dot. Use Store::openWithCodec
	 * when the custom format is not represented by a built-in StoreFormat.
	 *
	 * @param string              $extension Extension such as `conf`.
	 * @param StoreCodecInterface $codec     Codec implementation.
	 */
	public static function registerExtension(string $extension, StoreCodecInterface $codec) : void
	{
		$extension = strtolower(ltrim($extension, '.'));
		if ($extension === '') {
			throw new \InvalidArgumentException('A custom Store codec extension cannot be empty.');
		}
		self::$extensions[$extension] = $codec;
	}

	/** @return StoreCodecInterface|null Codec registered for an extension. */
	public static function extension(string $extension) : ?StoreCodecInterface
	{
		return self::$extensions[strtolower(ltrim($extension, '.'))] ?? null;
	}
}
