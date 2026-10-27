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

namespace zwuiix\store\tests;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use pocketmine\promise\Promise;
use pocketmine\scheduler\AsyncPool;
use pocketmine\Server;
use pocketmine\snooze\SleeperHandler;
use pocketmine\thread\ThreadSafeClassLoader;
use pocketmine\utils\MainLogger;
use ReflectionClass;
use ReflectionProperty;
use zwuiix\store\EncryptedStore;
use zwuiix\store\Store;
use zwuiix\store\StoreFormat;

use function bin2hex;
use function class_exists;
use function define;
use function defined;
use function dirname;
use function file_put_contents;
use function function_exists;
use function is_dir;
use function is_file;
use function microtime;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;
use function usleep;

/**
 * Regression coverage for Store loading through PocketMine's async pool.
 */
final class StoreAsyncLoadingTest extends TestCase
{
	private ?string $directory = null;

	private ?AsyncPool $pool = null;

	private ?Server $previousServer = null;

	private ?ReflectionProperty $serverInstance = null;

	protected function setUp() : void
	{
		if (!class_exists('pmmp\\thread\\Runnable')) {
			self::markTestSkipped('PocketMine async tests require the pmmpthread extension.');
		}
		if (!defined('pocketmine\\COMPOSER_AUTOLOADER_PATH')) {
			define('pocketmine\\COMPOSER_AUTOLOADER_PATH', dirname(__DIR__) . '/vendor/autoload.php');
		}

		$this->directory = sys_get_temp_dir() . '/store-async-tests-' . bin2hex(random_bytes(6));
		mkdir($this->directory, 0775, true);
		$logger = new MainLogger(null, false, 'Store Tests', new DateTimeZone('UTC'));
		$this->pool = new AsyncPool(1, 1024, new ThreadSafeClassLoader(), $logger, new SleeperHandler());

		$this->serverInstance = new ReflectionProperty(Server::class, 'instance');
		$this->previousServer = $this->serverInstance->getValue();
		$server = (new ReflectionClass(Server::class))->newInstanceWithoutConstructor();
		$asyncPool = new ReflectionProperty(Server::class, 'asyncPool');
		$asyncPool->setValue($server, $this->pool);
		$this->serverInstance->setValue(null, $server);
	}

	protected function tearDown() : void
	{
		$this->pool?->shutdown();
		$this->serverInstance?->setValue(null, $this->previousServer);
		if ($this->directory !== null) {
			$this->removeDirectory($this->directory);
		}
	}

	public function testAsyncLoadingWithArrayDefaults() : void
	{
		$store = $this->load(
			$this->directory . '/array-defaults.json',
			StoreFormat::Json,
			['enabled' => true, 'database' => ['host' => 'localhost', 'port' => 3306]],
		);

		self::assertSame(['enabled' => true, 'database' => ['host' => 'localhost', 'port' => 3306]], $store->all());
	}

	public function testAsyncLoadingOfExistingYamlFile() : void
	{
		if (!function_exists('yaml_parse')) {
			self::markTestSkipped('The YAML regression test requires ext-yaml.');
		}

		$path = $this->directory . '/existing.yaml';
		file_put_contents($path, "database:\n  host: yaml.example\n  port: 3306\n");
		$store = $this->load($path, StoreFormat::Yaml, ['database' => ['port' => 5432, 'ssl' => true]]);

		self::assertSame([
			'database' => ['host' => 'yaml.example', 'port' => 3306, 'ssl' => true],
		], $store->all());
	}

	public function testAsyncLoadingOfMissingFileCreatesIt() : void
	{
		$path = $this->directory . '/missing/nested/config.json';
		$store = $this->load($path, StoreFormat::Json, ['created' => true]);

		self::assertTrue(is_file($path));
		self::assertSame(['created' => true], $store->all());
	}

	public function testAsyncLoadingMergesNestedDefaults() : void
	{
		$path = $this->directory . '/nested.json';
		file_put_contents($path, '{"database":{"port":5432},"features":{"chat":false}}');
		$store = $this->load($path, StoreFormat::Json, [
			'database' => ['host' => 'localhost', 'credentials' => ['user' => 'store']],
			'features' => ['chat' => true, 'metrics' => ['enabled' => true]],
		]);

		self::assertSame([
			'database' => ['host' => 'localhost', 'credentials' => ['user' => 'store'], 'port' => 5432],
			'features' => ['chat' => false, 'metrics' => ['enabled' => true]],
		], $store->all());
	}

	public function testAsyncLoadingOfEncryptedFile() : void
	{
		$path = $this->directory . '/encrypted.store';
		$passphrase = 'async-test-passphrase';
		$created = EncryptedStore::open($path, defaults: ['secret' => 'value'], passphrase: $passphrase);
		self::assertSame(['secret' => 'value'], $created->all());

		$store = $this->load($path, StoreFormat::Encrypted, ['fallback' => true], $passphrase);

		self::assertSame(['fallback' => true, 'secret' => 'value'], $store->all());
	}

	public function testInvalidDecodedDataRejectsThePromise() : void
	{
		$path = $this->directory . '/invalid.json';
		file_put_contents($path, 'null');
		$promise = Store::load($path, StoreFormat::Json, async: true);
		self::assertInstanceOf(Promise::class, $promise);

		$state = $this->await($promise);
		self::assertSame('rejected', $state);
	}

	/**
	 * @param array<string, mixed> $defaults
	 */
	private function load(string $path, StoreFormat $format, array $defaults, ?string $passphrase = null) : Store
	{
		$promise = Store::load($path, $format, $defaults, $passphrase, async: true);
		self::assertInstanceOf(Promise::class, $promise);

		$result = $this->await($promise);
		self::assertIsArray($result);
		self::assertSame('resolved', $result['state']);
		self::assertInstanceOf(Store::class, $result['value']);

		return $result['value'];
	}

	private function await(Promise $promise) : mixed
	{
		$state = null;
		$promise->onCompletion(
			static function(mixed $value) use (&$state) : void {
				$state = ['state' => 'resolved', 'value' => $value];
			},
			static function() use (&$state) : void {
				$state = 'rejected';
			},
		);

		$pool = $this->pool;
		self::assertNotNull($pool);
		$deadline = microtime(true) + 30.0;
		while ($state === null && microtime(true) < $deadline) {
			$pool->collectTasks();
			usleep(10_000);
		}

		self::assertNotNull($state, 'The async Store promise did not complete within 30 seconds.');

		return $state;
	}

	private function removeDirectory(string $directory) : void
	{
		if (!is_dir($directory)) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($iterator as $item) {
			if ($item->isDir()) {
				rmdir($item->getPathname());
			} else {
				unlink($item->getPathname());
			}
		}
		rmdir($directory);
	}
}
