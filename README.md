<p align="center">
  <img src="./icon.png" alt="store logo" width="150">
</p>

<h1 align="center">store</h1>

<p align="center">
  A PocketMine-MP 5 virion for format-independent configuration and persistence.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PocketMine--MP-5.x-6f42c1" alt="PocketMine-MP 5.x">
  <img src="https://img.shields.io/badge/PHP-8.4-777bb4" alt="PHP 8.4 only">
  <img src="https://img.shields.io/badge/license-Apache--2.0-blue" alt="Apache-2.0 license">
</p>

`store` provides one API for nested configuration data, typed accessors, validation, migrations, asynchronous file operations, backups, events and multiple serialization formats.

## Features

- JSON, JSONC, dotenv, INI, Java properties, TOML, YAML, XML and encrypted STORE/1 files.
- Nested dot-path accessors with typed reads and immutable views.
- Synchronous and PocketMine asynchronous load, save and reload operations.
- Atomic writes, optional file locks, backups and recovery.
- Defaults, profiles, includes, environment interpolation and remote sources.
- Transactions, patches, snapshots, merge/conflict handling and change history.
- Schema validation and mapping to typed PHP objects.
- Encryption, keyrings, custom codecs, audit logs and lifecycle events.

## Requirements

- PocketMine-MP 5.x (`api: 5.0.0`).
- PHP 8.4 (other PHP versions are not supported).
- Composer and the Phar extension when building the virion from source.
- PHP `ext-yaml` when using YAML files.

Development is validated against the `axolotl-pm/pocketmine-mp` fork locked by this repository. Re-test the virion when targeting another PocketMine distribution or updating the server.

## Installation

`store` is a virion, not a standalone plugin. Its PHAR must be injected into a plugin with a virion-compatible build workflow; do not place it directly in the server's `plugins` directory.

Build the virion from the project root:

```bash
composer make
```

The command installs the development dependencies and creates `store.phar` in the project root. This repository does not provide an injector, so use the injection workflow of the consuming plugin.

## Quick start

`Store` can open a file, read typed values and save changes synchronously:

```php
<?php

declare(strict_types=1);

use zwuiix\store\Store;

$options = Store::open($dataFolder . '/database.toml');
$options->withDefaults([
	'database' => [
		'host' => '127.0.0.1',
		'port' => 3306,
	],
]);

$host = $options->getString('database.host');
$options->set('database.port', 3307);
$options->save();
```

Use the named `async` option for normal runtime work so parsing and file I/O stay outside the main thread:

```php
$promise = Store::load($dataFolder . '/database.toml', async: true);
$promise->onCompletion(static function (Store $options) : void {
	$options->set('database.last_loaded', time());
	$options->save(async: true);
});
```

`save()` and `reload()` accept the same named option:

```php
$options->save(async: true);
$options->reload(async: true);
```

## Profiles, references and auto-save

Profile files can be layered over a base configuration:

```php
$options = Store::openProfile($dataFolder . '/config.yml', 'production');
$options->overlay([
	'logging' => ['level' => 'debug'],
]);
$options->interpolate();
```

References use `${path.to.value}` for Store values and `${ENV_NAME}` for process environment variables. Cycles throw a `StoreException` instead of silently producing a partial configuration.

Auto-save is opt-in and batches mutations while an asynchronous write is active:

```php
$options->enableAutoSave();
$options->set('database.host', '127.0.0.1');
$options->set('database.port', 3306);
```

## Transactions, validation and events

Several related mutations can be committed as one operation. A failed callback is rolled back automatically:

```php
$options->transaction(static function (Store $options) : void {
	$options->set('database.host', 'db.internal');
	$options->set('database.port', 5432);
}, save: true, async: true);
```

`diff()` returns dot-path `set` and `remove` operations, and `applyPatch()` applies them atomically. Schemas validate values before they are saved or reloaded, while `StoreMapper` hydrates typed PHP objects from nested data.

PocketMine plugins can listen to public events in `zwuiix\\store\\event`, including `StoreLoadEvent`, `StoreSaveEvent`, `StoreReloadEvent`, `StoreChangeEvent`, `StoreErrorEvent`, `StoreBackupEvent` and `StoreTransactionEvent`.

## Backups, recovery and encryption

Writes are atomic, and optional versioned backups can be kept before every replacement:

```php
use zwuiix\store\storage\StoreBackupPolicy;

$options = new Store(
	$dataFolder . '/database.json',
	backupPolicy: StoreBackupPolicy::keep(5),
);
$options->save();
$options->recover();
```

`EncryptedStore`, keyrings and the encrypted STORE/1 codec provide password-protected configuration files. See the complete API guide for key providers, migration runners, mappings and custom codecs.

## Development

Install dependencies:

```bash
composer install
```

Build the virion:

```bash
composer make
```

Apply the configured coding style and run syntax checks:

```bash
composer cs-fixer
find src -name '*.php' -print0 | xargs -0 -n1 php -l
```

The public API is documented through the source code and the examples above.

## License

`store` is licensed under the [Apache License 2.0](LICENSE).
