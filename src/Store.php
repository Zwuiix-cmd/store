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

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use pocketmine\event\Cancellable;
use pocketmine\event\Event as PocketMineEvent;
use pocketmine\promise\Promise;
use pocketmine\promise\PromiseResolver;
use pocketmine\Server;
use zwuiix\store\async\StoreIncludesLoadAsyncTask;
use zwuiix\store\async\StoreLoadAsyncTask;
use zwuiix\store\async\StoreRemoteLoadAsyncTask;
use zwuiix\store\async\StoreSaveAsyncTask;
use zwuiix\store\codec\StoreSourceEditor;
use zwuiix\store\data\PathAccessor;
use zwuiix\store\data\StoreChangeRecord;
use zwuiix\store\data\StoreSnapshot;
use zwuiix\store\data\StoreValueTree;
use zwuiix\store\data\StoreView;
use zwuiix\store\event\StoreBackupEvent;
use zwuiix\store\event\StoreBeforeReloadEvent;
use zwuiix\store\event\StoreBeforeWriteEvent;
use zwuiix\store\event\StoreChangeEvent;
use zwuiix\store\event\StoreConflictDetectedEvent;
use zwuiix\store\event\StoreErrorEvent;
use zwuiix\store\event\StoreIncludeLoadedEvent;
use zwuiix\store\event\StoreLoadEvent;
use zwuiix\store\event\StoreMappedEvent;
use zwuiix\store\event\StoreMergeEvent;
use zwuiix\store\event\StoreRecoveryEvent;
use zwuiix\store\event\StoreReloadEvent;
use zwuiix\store\event\StoreSaveEvent;
use zwuiix\store\event\StoreTransactionEvent;
use zwuiix\store\event\StoreValidationFailedEvent;
use zwuiix\store\exception\StoreConflictException;
use zwuiix\store\exception\StoreException;
use zwuiix\store\exception\StoreLockException;
use zwuiix\store\mapping\StoreMapper;
use zwuiix\store\merge\StoreConflictPolicy;
use zwuiix\store\merge\StoreMergeConflict;
use zwuiix\store\merge\StoreMergeDecision;
use zwuiix\store\merge\StoreMergeResult;
use zwuiix\store\migration\StoreMigrationRunner;
use zwuiix\store\runtime\Environment;
use zwuiix\store\runtime\StoreAuditLog;
use zwuiix\store\runtime\StoreInterpolator;
use zwuiix\store\runtime\StoreLogger;
use zwuiix\store\runtime\StoreProfile;
use zwuiix\store\runtime\StoreWatcher;
use zwuiix\store\security\StoreSecret;
use zwuiix\store\storage\StoreBackupManager;
use zwuiix\store\storage\StoreBackupPolicy;
use zwuiix\store\storage\StoreDependencyGraph;
use zwuiix\store\storage\StoreFilePolicy;
use zwuiix\store\storage\StoreIncludeLoader;
use zwuiix\store\storage\StoreRemote;
use zwuiix\store\validation\StoreSchema;
use zwuiix\store\validation\StoreValidationException;
use zwuiix\store\validation\StoreValidationResult;

use function array_filter;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_pop;
use function array_replace_recursive;
use function array_slice;
use function array_unique;
use function array_values;
use function chmod;
use function count;
use function date;
use function dirname;
use function enum_exists;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function flock;
use function fopen;
use function ftruncate;
use function fwrite;
use function getmypid;
use function hash;
use function hash_file;
use function is_array;
use function is_bool;
use function is_dir;
use function is_file;
use function is_float;
use function is_int;
use function is_string;
use function max;
use function microtime;
use function mkdir;
use function parse_url;
use function pathinfo;
use function rename;
use function sprintf;
use function str_starts_with;
use function tempnam;
use function trim;
use function unlink;
use function usleep;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;
use const PATHINFO_EXTENSION;
use const PHP_URL_PATH;

/**
 * Independent configuration store with multiple codecs.
 *
 * Store keeps values in a nested associative array, exposes typed accessors,
 * supports synchronous and asynchronous persistence, and can be extended for
 * specialized formats such as EncryptedStore.
 */
class Store
{
	/** @var array<string, mixed> */
	private array $values = [];

	/** @var array<string, mixed> */
	private array $loadedValues = [];

	private ?string $sourceContents = null;

	private ?string $sourceHash = null;

	/** @var array<string, mixed> */
	private array $nestedCache = [];

	private StoreFormat $format;

	private string $path;

	private ?string $passphrase;

	private ?StoreCodecInterface $customCodec;

	private int $revision = 0;

	private bool $dirty = false;

	private StoreFilePolicy $filePolicy;

	private StoreBackupPolicy $backupPolicy;

	private ?StoreLogger $logger;

	private ?StoreWatcher $watcher = null;

	private ?StoreAuditLog $auditLog = null;

	private ?int $auditListener = null;

	private ?StoreDependencyGraph $dependencyGraph = null;

	/** @var list<string> */
	private array $dependencyFiles = [];

	private bool $autoSaveEnabled = false;

	private bool $autoSavePending = false;

	private int $historyLimit = 0;

	/** @var list<StoreChangeRecord> */
	private array $history = [];

	/** @var array<int, array{path: string, listener: callable}> */
	private array $pathListeners = [];

	private int $nextPathListener = 1;

	/** @var Promise<bool>|null */
	private ?Promise $activeSavePromise = null;

	private ?int $activeSaveRevision = null;

	private int $asyncGeneration = 0;

	/** @var Promise<self>|null */
	private ?Promise $activeReloadPromise = null;

	/** @var list<PromiseResolver<bool>> */
	private array $queuedSaveResolvers = [];

	private int $transactionDepth = 0;

	/**
	 * @var list<array{
	 *     values: array<string, mixed>,
	 *     loadedValues: array<string, mixed>,
	 *     nestedCache: array<string, mixed>,
	 *     revision: int,
	 *     dirty: bool,
	 *     autoSavePending: bool
	 * }>
	 */
	private array $transactionSnapshots = [];

	/** @var array<string, list<callable>> */
	private array $hooks = [];

	/**
	 * Creates a Store and loads it synchronously.
	 *
	 * The constructor accepts StoreFormat values and legacy numeric format values.
	 * Missing files are created with the defaults.
	 * When initial data is supplied, the file is not read; this is how async
	 * loading hydrates the Store after decoding in the worker.
	 *
	 * @param string                    $file           File path, or an empty string for an in-memory Store.
	 * @param StoreFormat|int|null      $format         StoreFormat or a legacy numeric format value.
	 * @param array<string, mixed>      $defaults       Values used when keys are absent from the file.
	 * @param string|null               $passphrase     Secret passed to codecs that support encryption.
	 * @param array<string, mixed>|null $initialData    Pre-decoded values used by asynchronous loading.
	 * @param StoreFilePolicy|null      $filePolicy     File mode and locking policy.
	 * @param StoreBackupPolicy|null    $backupPolicy   Backup policy applied before replacement.
	 * @param StoreLogger|null          $logger         Optional PocketMine-backed logger.
	 * @param bool                      $dispatchEvents Whether public events should be emitted.
	 * @throws StoreException            If the source file or encoded data cannot be read.
	 */
	public function __construct(
		string               $file,
		StoreFormat|int|null $format = null,
		array                $defaults = [],
		?string              $passphrase = null,
		?array               $initialData = null,
		?StoreFilePolicy     $filePolicy = null,
		?StoreBackupPolicy   $backupPolicy = null,
		?StoreLogger         $logger = null,
		bool                 $dispatchEvents = true,
		?StoreCodecInterface $customCodec = null,
	) {
		$this->path = $file;
		$this->format = self::resolveFormat($file, $format);
		$this->passphrase = $passphrase;
		$this->customCodec = $customCodec;
		$this->filePolicy = $filePolicy ?? ($this->format === StoreFormat::Encrypted ? StoreFilePolicy::secure() : new StoreFilePolicy());
		$this->backupPolicy = $backupPolicy ?? StoreBackupPolicy::disabled();
		$this->logger = $logger;
		$created = $file !== '' && !is_file($file);

		if ($initialData !== null) {
			$this->values = array_replace_recursive($defaults, $initialData);
			$this->sourceHash = is_file($file) ? (hash_file('sha256', $file) ?: null) : null;
		} elseif (is_file($file)) {
			$contents = file_get_contents($file);
			if ($contents === false) {
				throw new StoreException("Unable to read Store file '{$file}'.");
			}

			$this->sourceContents = $contents;
			$this->sourceHash = hash('sha256', $contents);
			try {
				$decoded = $this->activeCodec()->decode($contents, $passphrase);
			} catch (\Throwable $exception) {
				$recovered = $this->recoverDecodedFile();
				if ($recovered === null) {
					throw $exception;
				}

				[$contents, $decoded, $backupPath] = $recovered;
				$this->sourceContents = $contents;
				$this->sourceHash = hash('sha256', $contents);
				$this->dispatch(new StoreRecoveryEvent($this, $backupPath));
				$this->logger?->warning("Recovered Store document from backup '{$backupPath}'.");
			}

			$this->values = array_replace_recursive($defaults, $decoded);
		} else {
			$this->values = $defaults;
		}

		$this->loadedValues = $this->values;
		if ($created) {
			$this->save();
		}

		if ($dispatchEvents) {
			$this->dispatch(new StoreLoadEvent($this, $created));
			$this->logger?->debug($created ? 'Created a new Store document.' : 'Loaded Store document.');
		}
	}

	/**
	 * Opens a Store file synchronously.
	 *
	 * @param string               $file       File to open.
	 * @param StoreFormat|int|null $format     StoreFormat or a legacy numeric format value.
	 * @param array<string, mixed> $defaults   Values used when keys are absent from the file.
	 * @param string|null          $passphrase Secret passed to the selected codec.
	 * @return self                 The loaded Store instance.
	 * @throws StoreException       If the file cannot be read, parsed or created.
	 */
	public static function open(
		string               $file,
		StoreFormat|int|null $format = null,
		array                $defaults = [],
		?string              $passphrase = null,
	) : self {
		if ($format === null) {
			$customCodec = StoreCodecRegistry::extension(pathinfo($file, PATHINFO_EXTENSION));
			if ($customCodec !== null) {
				return self::openWithCodec($file, $customCodec, $defaults, $passphrase);
			}
		}

		return new self($file, $format, $defaults, $passphrase);
	}

	/**
	 * Opens a Store with explicit file and lifecycle policies.
	 *
	 * @param string                 $file
	 * @param StoreFormat|int|null   $format
	 * @param array<string,mixed>    $defaults
	 * @param string|null            $passphrase
	 * @param StoreFilePolicy|null   $filePolicy
	 * @param StoreBackupPolicy|null $backupPolicy
	 * @param StoreLogger|null       $logger
	 * @return self Loaded Store.
	 */
	public static function openWithPolicy(
		string               $file,
		StoreFormat|int|null $format = null,
		array                $defaults = [],
		?string              $passphrase = null,
		?StoreFilePolicy     $filePolicy = null,
		?StoreBackupPolicy   $backupPolicy = null,
		?StoreLogger         $logger = null,
	) : self {
		if ($format === null) {
			$customCodec = StoreCodecRegistry::extension(pathinfo($file, PATHINFO_EXTENSION));
			if ($customCodec !== null) {
				return self::openWithCodec($file, $customCodec, $defaults, $passphrase, $filePolicy, $backupPolicy, $logger);
			}
		}

		return new self($file, $format, $defaults, $passphrase, null, $filePolicy, $backupPolicy, $logger);
	}

	/**
	 * Opens a file using a plugin supplied codec.
	 *
	 * @param string                 $file         File path.
	 * @param StoreCodecInterface    $codec        Custom codec implementation.
	 * @param array<string,mixed>    $defaults     Default values.
	 * @param string|null            $passphrase   Optional codec secret.
	 * @param StoreFilePolicy|null   $filePolicy   File policy.
	 * @param StoreBackupPolicy|null $backupPolicy Backup policy.
	 * @param StoreLogger|null       $logger       Optional logger.
	 * @return self Loaded custom Store.
	 */
	public static function openWithCodec(
		string               $file,
		StoreCodecInterface  $codec,
		array                $defaults = [],
		?string              $passphrase = null,
		?StoreFilePolicy     $filePolicy = null,
		?StoreBackupPolicy   $backupPolicy = null,
		?StoreLogger         $logger = null,
	) : self {
		$store = new self($file, self::resolveFormat($file, null), $defaults, $passphrase, null, $filePolicy, $backupPolicy, $logger, true, $codec);

		return $store;
	}

	/**
	 * Creates an in-memory Store from custom encoded content.
	 *
	 * @param string              $contents   Encoded content.
	 * @param StoreCodecInterface $codec      Codec implementation.
	 * @param string|null         $path       Optional associated path.
	 * @param string|null         $passphrase Optional codec secret.
	 * @param array<string,mixed> $defaults   Defaults.
	 * @return self Decoded Store.
	 */
	public static function fromStringWithCodec(string $contents, StoreCodecInterface $codec, ?string $path = null, ?string $passphrase = null, array $defaults = []) : self
	{
		$data = $codec->decode($contents, $passphrase);

		return new self($path ?? '', $path === null ? StoreFormat::Json : StoreFormat::fromPath($path), $defaults, $passphrase, $data, null, null, null, true, $codec);
	}

	/**
	 * Opens a root document and merges files declared by `$include`.
	 *
	 * Include files are applied first and the root document has the highest
	 * priority. Relative paths are resolved from the including file, cycles are
	 * rejected, and the dependency graph is retained for reload/watch support.
	 *
	 * @param string                 $file         Root Store file.
	 * @param StoreFormat|int|null   $format       Optional root format.
	 * @param array<string,mixed>    $defaults     Values used when keys are absent.
	 * @param string|null            $passphrase   Secret passed to codecs.
	 * @param StoreFilePolicy|null   $filePolicy   File policy.
	 * @param StoreBackupPolicy|null $backupPolicy Backup policy.
	 * @param StoreLogger|null       $logger       Optional logger.
	 * @return self                 Composed Store.
	 */
	public static function openWithIncludes(
		string               $file,
		StoreFormat|int|null $format = null,
		array                $defaults = [],
		?string              $passphrase = null,
		?StoreFilePolicy     $filePolicy = null,
		?StoreBackupPolicy   $backupPolicy = null,
		?StoreLogger         $logger = null,
	) : self {
		$resolvedFormat = self::resolveFormat($file, $format);
		$included = StoreIncludeLoader::load($file, $resolvedFormat, $passphrase);
		$store = new self($file, $resolvedFormat, $defaults, $passphrase, $included['values'], $filePolicy, $backupPolicy, $logger);
		$store->setDependencies($included['graph'], $included['files']);
		$store->dispatchIncludeEvents();

		return $store;
	}

	/**
	 * Loads an include graph without parsing its files on the main thread.
	 *
	 * @param string                 $file
	 * @param StoreFormat|int|null   $format
	 * @param array<string,mixed>    $defaults
	 * @param string|null            $passphrase
	 * @param StoreFilePolicy|null   $filePolicy
	 * @param StoreBackupPolicy|null $backupPolicy
	 * @param StoreLogger|null       $logger
	 * @return Promise<self> Promise resolving to the composed Store.
	 */
	public static function openWithIncludesAsync(
		string               $file,
		StoreFormat|int|null $format = null,
		array                $defaults = [],
		?string              $passphrase = null,
		?StoreFilePolicy     $filePolicy = null,
		?StoreBackupPolicy   $backupPolicy = null,
		?StoreLogger         $logger = null,
	) : Promise {
		$resolver = new PromiseResolver();
		$resolvedFormat = self::resolveFormat($file, $format);
		$workerResolver = new PromiseResolver();
		$workerResolver->getPromise()->onCompletion(function(array $result) use ($file, $resolvedFormat, $defaults, $passphrase, $filePolicy, $backupPolicy, $logger, $resolver) : void {
			try {
				$store = new self($file, $resolvedFormat, $defaults, $passphrase, $result['values'], $filePolicy, $backupPolicy, $logger);
				$store->setDependencies(StoreDependencyGraph::fromArray($result['graph']), $result['files']);
				$store->dispatchIncludeEvents();
				$resolver->resolve($store);
			} catch (\Throwable) {
				$resolver->reject();
			}
		}, static function() use ($resolver) : void {
			$resolver->reject();
		});
		Server::getInstance()->getAsyncPool()->submitTask(new StoreIncludesLoadAsyncTask($file, $resolvedFormat, $passphrase, $workerResolver));

		return $resolver->getPromise();
	}

	/**
	 * Loads a Store using either synchronous or asynchronous I/O.
	 *
	 * When asynchronous loading is selected, the returned Promise resolves with
	 * the loaded Store after reading and decoding have completed in the async
	 * worker pool.
	 *
	 * @param string               $file       File to open.
	 * @param StoreFormat|int|null $format     StoreFormat or a legacy numeric format value.
	 * @param array<string, mixed> $defaults   Values used when keys are absent from the file.
	 * @param string|null          $passphrase Secret passed to the selected codec.
	 * @param bool                 $async      Whether the file should be loaded asynchronously.
	 * @return self|Promise<self>   The loaded Store, or a Promise resolving to it.
	 * @throws StoreException       If synchronous loading fails or the format is invalid.
	 */
	public static function load(
		string               $file,
		StoreFormat|int|null $format = null,
		array                $defaults = [],
		?string              $passphrase = null,
		bool                 $async = false,
	) : self|Promise {
		if ($async) {
			return static::openAsyncInternal($file, $format, $defaults, $passphrase, true);
		}

		return static::open($file, $format, $defaults, $passphrase);
	}

	/**
	 * Creates a Store from encoded content synchronously.
	 *
	 * @param string               $contents   Encoded Store content.
	 * @param StoreFormat          $format     Format used to decode the content.
	 * @param string|null          $path       Optional path associated with the Store.
	 * @param string|null          $passphrase Secret passed to the codec.
	 * @param array<string, mixed> $defaults   Values used when keys are absent from the content.
	 * @return self                 The decoded Store instance.
	 * @throws StoreException       If the content is invalid for the selected format.
	 */
	public static function fromString(
		string      $contents,
		StoreFormat $format = StoreFormat::Json,
		?string     $path = null,
		?string     $passphrase = null,
		array       $defaults = [],
	) : self {
		$data = StoreCodecRegistry::get($format)->decode($contents, $passphrase);

		return new self($path ?? '', $format, $defaults, $passphrase, $data);
	}

	/**
	 * Creates a Store from an already decoded array.
	 *
	 * @param array<string, mixed> $values     Initial Store values.
	 * @param StoreFormat          $format     Format used when the Store is saved.
	 * @param string|null          $path       Optional path associated with the Store.
	 * @param string|null          $passphrase Secret passed to the codec when saving.
	 * @return self                 The Store instance containing the supplied values.
	 * @throws StoreException       If the values cannot be represented by the selected codec when saving.
	 */
	public static function fromArray(
		array       $values,
		StoreFormat $format = StoreFormat::Json,
		?string     $path = null,
		?string     $passphrase = null,
	) : self {
		return new self($path ?? '', $format, [], $passphrase, $values);
	}

	/**
	 * Opens a remote Store document with an optional local cache.
	 *
	 * @param string               $url        HTTP or HTTPS source URL.
	 * @param StoreFormat|int|null $format     Optional explicit format.
	 * @param array<string,mixed>  $defaults   Values used when keys are absent.
	 * @param string|null          $cacheFile  Local cache path.
	 * @param int                  $ttl        Fresh cache lifetime in seconds.
	 * @param int                  $timeout    Network timeout in seconds.
	 * @param string|null          $passphrase Secret passed to the codec.
	 * @param string|null          $sha256     Expected SHA-256 content signature.
	 * @param string|null          $signature  Expected Ed25519 detached signature.
	 * @param string|null          $publicKey  Ed25519 public key used to verify it.
	 * @return self                 Loaded Store.
	 * @throws StoreException       If the source cannot be downloaded or decoded.
	 */
	public static function openRemote(
		string               $url,
		StoreFormat|int|null $format = null,
		array                $defaults = [],
		?string              $cacheFile = null,
		int                  $ttl = 300,
		int                  $timeout = 5,
		?string              $passphrase = null,
		?string              $sha256 = null,
		?string              $signature = null,
		?string              $publicKey = null,
	) : self {
		$contents = StoreRemote::fetch($url, $cacheFile, $ttl, $timeout, $sha256, $signature, $publicKey);
		if ($format === null) {
			$path = parse_url($url, PHP_URL_PATH);
			$format = StoreFormat::fromPath(is_string($path) ? $path : $url);
		}

		return self::fromString($contents, self::resolveFormat($url, $format), null, $passphrase, $defaults);
	}

	/**
	 * Opens a remote Store document in PocketMine's async pool.
	 *
	 * @param string               $url        HTTP or HTTPS source URL.
	 * @param StoreFormat|int|null $format     Optional explicit format.
	 * @param array<string,mixed>  $defaults   Values used when keys are absent.
	 * @param string|null          $cacheFile  Local cache path.
	 * @param int                  $ttl        Fresh cache lifetime in seconds.
	 * @param int                  $timeout    Network timeout in seconds.
	 * @param string|null          $passphrase Secret passed to the codec.
	 * @param string|null          $sha256     Expected SHA-256 content signature.
	 * @param string|null          $signature  Expected Ed25519 detached signature.
	 * @param string|null          $publicKey  Ed25519 public key used to verify it.
	 * @return Promise<self>        Promise resolving to the loaded Store.
	 */
	public static function openRemoteAsync(
		string               $url,
		StoreFormat|int|null $format = null,
		array                $defaults = [],
		?string              $cacheFile = null,
		int                  $ttl = 300,
		int                  $timeout = 5,
		?string              $passphrase = null,
		?string              $sha256 = null,
		?string              $signature = null,
		?string              $publicKey = null,
	) : Promise {
		$path = parse_url($url, PHP_URL_PATH);
		$resolvedFormat = self::resolveFormat($url, $format ?? StoreFormat::fromPath(is_string($path) ? $path : $url));
		$resolver = new PromiseResolver();
		Server::getInstance()->getAsyncPool()->submitTask(new StoreRemoteLoadAsyncTask(
			$url,
			$resolvedFormat,
			$defaults,
			$cacheFile,
			$ttl,
			$timeout,
			$passphrase,
			$sha256,
			$signature,
			$publicKey,
			$resolver,
		));

		return $resolver->getPromise();
	}

	/**
	 * Opens a Store asynchronously with explicit event dispatch control.
	 *
	 * This internal variant prevents temporary hydration Stores from emitting
	 * public load events before the final Store instance is available.
	 *
	 * @param string               $file           File to open.
	 * @param StoreFormat|int|null $format         StoreFormat or legacy value.
	 * @param array<string, mixed> $defaults       Values used when keys are absent.
	 * @param string|null          $passphrase     Secret passed to the codec.
	 * @param bool                 $dispatchEvents Whether the public load event should be emitted.
	 * @return Promise<self>        A promise resolved with the loaded Store.
	 */
	protected static function openAsyncInternal(
		string               $file,
		StoreFormat|int|null $format,
		array                $defaults,
		?string              $passphrase,
		bool                 $dispatchEvents,
	) : Promise {
		$resolver = new PromiseResolver();
		$promise = $resolver->getPromise();
		$resolvedFormat = self::resolveFormat($file, $format);
		Server::getInstance()->getAsyncPool()->submitTask(new StoreLoadAsyncTask(
			$file,
			$resolvedFormat,
			$defaults,
			$passphrase,
			$dispatchEvents,
			$resolver,
		));

		return $promise;
	}

	/**
	 * Returns the file path associated with this Store.
	 *
	 * @return string The configured file path, or an empty string for an in-memory Store.
	 */
	public function path() : string
	{
		return $this->path;
	}

	/** @return list<string> Root file and all files reached through includes. */
	public function dependencies() : array
	{
		return $this->dependencyFiles === [] && $this->path !== '' ? [$this->path] : $this->dependencyFiles;
	}

	/** @return StoreDependencyGraph|null Include graph, when this Store has includes. */
	public function dependencyGraph() : ?StoreDependencyGraph
	{
		return $this->dependencyGraph;
	}

	/**
	 * Reloads the root file and all of its included dependencies.
	 *
	 * @return self This Store instance.
	 * @throws StoreException If an include is missing, cyclic or invalid.
	 */
	public function reloadDependencies() : self
	{
		if ($this->path === '') {
			throw new StoreException('This Store has no file path.');
		}
		$included = StoreIncludeLoader::load($this->path, $this->format, $this->passphrase);
		$this->values = $included['values'];
		$this->sourceContents = file_get_contents($this->path) ?: null;
		$this->sourceHash = $this->sourceContents === null ? null : hash('sha256', $this->sourceContents);
		$this->loadedValues = $this->values;
		$this->nestedCache = [];
		$this->dependencyGraph = $included['graph'];
		$this->dependencyFiles = $included['files'];
		$this->dispatchIncludeEvents();
		$this->revision++;
		$this->autoSavePending = false;
		$this->dirty = false;
		$this->emit(StoreHook::AfterReload);
		$this->dispatch(new StoreReloadEvent($this, false));

		return $this;
	}

	private function setDependencies(StoreDependencyGraph $graph, array $files) : void
	{
		$this->dependencyGraph = $graph;
		$this->dependencyFiles = $files;
	}

	/**
	 * Returns the active file policy.
	 *
	 * @return StoreFilePolicy File mode and locking policy.
	 */
	public function filePolicy() : StoreFilePolicy
	{
		return $this->filePolicy;
	}

	/**
	 * Returns the backup policy used before atomic replacements.
	 *
	 * @return StoreBackupPolicy Active backup policy.
	 */
	public function backupPolicy() : StoreBackupPolicy
	{
		return $this->backupPolicy;
	}

	/**
	 * Returns an immutable view rooted at a nested path.
	 *
	 * @param string $path Dot-separated branch path, or an empty string for the root.
	 * @return StoreView Read-only branch view.
	 */
	public function view(string $path = '') : StoreView
	{
		return new StoreView($this, $path);
	}

	/**
	 * Hydrates a typed object from a Store path.
	 *
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T
	 */
	public function getMapped(string $path, string $class, ?StoreSchema $schema = null) : object
	{
		$object = StoreMapper::get($this, $path, $class, $schema);
		$this->dispatch(new StoreMappedEvent($this, $path, $class, $object, 'get'));

		return $object;
	}

	/**
	 * Serializes a typed object below a Store path.
	 *
	 * @param object $object Object to map.
	 * @return void
	 */
	public function setMapped(string $path, object $object, ?StoreSchema $schema = null) : void
	{
		StoreMapper::set($this, $path, $object, $schema);
		$this->dispatch(new StoreMappedEvent($this, $path, $object::class, $object, 'set'));
	}

	/**
	 * Builds a validation schema from a typed mapped class.
	 *
	 * @param class-string $class DTO class to inspect.
	 * @return StoreSchema Generated schema.
	 */
	public static function schemaFor(string $class) : StoreSchema
	{
		return StoreMapper::schemaFor($class);
	}

	/**
	 * Returns the optional logger used by this Store.
	 *
	 * @return StoreLogger|null Logger, or null when logging is disabled.
	 */
	public function logger() : ?StoreLogger
	{
		return $this->logger;
	}

	/**
	 * Replaces the optional logger.
	 *
	 * @param StoreLogger|null $logger Logger, or null to disable logging.
	 * @return self             This Store instance.
	 */
	public function setLogger(?StoreLogger $logger) : self
	{
		$this->logger = $logger;

		return $this;
	}

	/**
	 * Enables an optional JSONL audit trail using the existing change event flow.
	 *
	 * @param string $path          Audit file path.
	 * @param bool   $includeValues Include values, with StoreSecret redaction.
	 * @return self This Store instance.
	 */
	public function enableAudit(string $path, bool $includeValues = false) : self
	{
		$this->disableAudit();
		$this->auditLog = new StoreAuditLog($path, $includeValues);
		$this->auditListener = $this->watchPath('', function(StoreChangeEvent $event) : void {
			$this->auditLog?->record($event);
		});

		return $this;
	}

	/** @return StoreAuditLog|null Active audit sink. */
	public function auditLog() : ?StoreAuditLog
	{
		return $this->auditLog;
	}

	/** @return self This Store instance. */
	public function disableAudit() : self
	{
		if ($this->auditListener !== null) {
			$this->unwatchPath($this->auditListener);
		}
		$this->auditListener = null;
		$this->auditLog = null;

		return $this;
	}

	/**
	 * Attaches PocketMine's server logger.
	 *
	 * @param bool $debug Whether normal lifecycle details should be logged.
	 * @return self This Store instance.
	 */
	public function useServerLogger(bool $debug = false) : self
	{
		$this->logger = StoreLogger::server('Store', $debug);

		return $this;
	}

	/**
	 * Returns the paths of retained backups, newest first.
	 *
	 * @return list<string> Existing backup paths.
	 */
	public function backups() : array
	{
		if ($this->path === '') {
			return [];
		}

		return StoreBackupManager::list($this->path, $this->backupPolicy);
	}

	/**
	 * Restores the newest backup and reloads it immediately.
	 *
	 * @throws StoreException If no backup exists or the restored data is invalid.
	 */
	public function recover() : void
	{
		if ($this->path === '') {
			throw new StoreException('This Store has no file path.');
		}

		StoreBackupManager::restoreLatest($this->path, $this->backupPolicy);
		$this->reload();
	}

	/**
	 * Registers a lifecycle hook.
	 *
	 * Save and reload hooks receive the Store. Error hooks receive the Store and
	 * the Throwable that caused the failed operation.
	 *
	 * @param StoreHook $event    Hook to observe.
	 * @param callable  $listener Event listener.
	 * @return self      This Store instance.
	 */
	public function on(StoreHook $event, callable $listener) : self
	{
		$this->hooks[$event->value][] = $listener;

		return $this;
	}

	/**
	 * Removes a registered lifecycle hook.
	 *
	 * @param StoreHook $event    Hook to stop observing.
	 * @param callable  $listener Listener to remove.
	 * @return self      This Store instance.
	 */
	public function off(StoreHook $event, callable $listener) : self
	{
		$listeners = $this->hooks[$event->value] ?? [];
		$this->hooks[$event->value] = array_values(array_filter(
			$listeners,
			static fn(callable $registered) : bool => $registered !== $listener,
		));

		return $this;
	}

	/**
	 * Starts a transaction. Mutations are coalesced into one auto-save request.
	 *
	 * Transactions may be nested. Only the outermost commit schedules a write.
	 *
	 * @return self This Store instance.
	 */
	public function beginTransaction() : self
	{
		$this->transactionDepth++;
		$this->transactionSnapshots[] = [
			'values' => $this->values,
			'loadedValues' => $this->loadedValues,
			'nestedCache' => $this->nestedCache,
			'revision' => $this->revision,
			'dirty' => $this->dirty,
			'autoSavePending' => $this->autoSavePending,
		];

		return $this;
	}

	/**
	 * Commits the current transaction.
	 *
	 * @param bool $save  Whether to persist immediately at the outermost commit.
	 * @param bool $async Whether the requested save should be asynchronous.
	 * @return Promise<bool>|null A save Promise, or null when no save was requested.
	 * @throws StoreException     If no transaction is active or synchronous saving fails.
	 */
	public function commitTransaction(bool $save = false, bool $async = false) : ?Promise
	{
		if ($this->transactionDepth === 0) {
			throw new StoreException('There is no active Store transaction.');
		}

		$snapshot = array_pop($this->transactionSnapshots);
		$this->transactionDepth--;
		if ($this->transactionDepth > 0 || $snapshot === null) {
			return null;
		}

		$changed = $this->values !== $snapshot['values'];
		if (!$changed) {
			$this->autoSavePending = $snapshot['autoSavePending'];
			$this->dispatch(new StoreTransactionEvent($this, true, false, $this->transactionDepth));
			return null;
		}

		if ($save) {
			$promise = $this->save($async);
			$this->dispatch(new StoreTransactionEvent($this, true, true, $this->transactionDepth));

			return $promise;
		}

		$this->requestAutoSave();
		$this->dispatch(new StoreTransactionEvent($this, true, true, $this->transactionDepth));

		return null;
	}

	/**
	 * Rolls back the current transaction to its starting values.
	 *
	 * @return self           This Store instance.
	 * @throws StoreException If no transaction is active.
	 */
	public function rollbackTransaction() : self
	{
		if ($this->transactionDepth === 0) {
			throw new StoreException('There is no active Store transaction.');
		}

		$snapshot = array_pop($this->transactionSnapshots);
		$this->transactionDepth--;
		if ($snapshot !== null) {
			$changed = $this->values !== $snapshot['values'];
			$this->values = $snapshot['values'];
			$this->loadedValues = $snapshot['loadedValues'];
			$this->nestedCache = $snapshot['nestedCache'];
			$this->revision = max($this->revision, $snapshot['revision']) + 1;
			$this->dirty = $snapshot['dirty'];
			$this->autoSavePending = $snapshot['autoSavePending'];
			$this->dispatch(new StoreTransactionEvent($this, false, $changed, $this->transactionDepth));
		}

		return $this;
	}

	/**
	 * Runs a callback atomically and rolls back when it throws.
	 *
	 * @param callable(self):void $callback Mutation callback.
	 * @param bool                $save     Whether to persist after commit.
	 * @param bool                $async    Whether that save should be asynchronous.
	 * @return Promise<bool>|null  A save Promise, or null.
	 * @throws \Throwable          When the callback or requested synchronous save fails.
	 */
	public function transaction(callable $callback, bool $save = false, bool $async = false) : ?Promise
	{
		$this->beginTransaction();
		try {
			$callback($this);

			return $this->commitTransaction($save, $async);
		} catch (\Throwable $exception) {
			if ($this->transactionDepth > 0) {
				$this->rollbackTransaction();
			}

			throw $exception;
		}
	}

	/**
	 * Returns the active serialization format.
	 */
	public function format() : StoreFormat
	{
		return $this->format;
	}

	/**
	 * Opens a Store with a base file and an optional profile overlay.
	 *
	 * @param string               $file       Base configuration file.
	 * @param string               $profile    Profile name such as dev or prod.
	 * @param StoreFormat|int|null $format     Optional explicit format.
	 * @param array<string,mixed>  $defaults   Default values for the base file.
	 * @param string|null          $passphrase Secret passed to supported codecs.
	 * @return self                 Composed Store.
	 */
	public static function openProfile(
		string               $file,
		string               $profile,
		StoreFormat|int|null $format = null,
		array                $defaults = [],
		?string              $passphrase = null,
	) : self {
		return StoreProfile::open($file, $profile, $format, $defaults, $passphrase);
	}

	/**
	 * Applies one or more configuration layers to this Store.
	 *
	 * @param array<string,mixed>|self ...$layers Layers ordered from low to high priority.
	 * @return self                     This Store instance.
	 */
	public function overlay(array|self ...$layers) : self
	{
		$values = $this->values;
		foreach ($layers as $layer) {
			$values = StoreProfile::merge($values, $layer instanceof self ? $layer->all() : $layer);
		}

		$this->setAll($values);

		return $this;
	}

	/**
	 * Resolves ${path} and ${ENVIRONMENT_VARIABLE} references in place.
	 *
	 * @param bool $environment Whether process environment variables are allowed.
	 * @param bool $keepUnknown Whether unresolved references should remain visible.
	 * @return self           This Store instance.
	 * @throws StoreException If references form a cycle.
	 */
	public function interpolate(bool $environment = true, bool $keepUnknown = true) : self
	{
		$this->setAll(StoreInterpolator::resolve($this->values, $environment, $keepUnknown));

		return $this;
	}

	/**
	 * Creates an immutable checkpoint of the current value tree.
	 *
	 * @return StoreSnapshot Snapshot that can later be restored.
	 */
	public function snapshot() : StoreSnapshot
	{
		return new StoreSnapshot($this->values, $this->revision);
	}

	/**
	 * Restores a checkpoint through the normal mutation and auto-save pipeline.
	 *
	 * @param StoreSnapshot $snapshot Checkpoint to restore.
	 * @return self           This Store instance.
	 */
	public function restoreSnapshot(StoreSnapshot $snapshot) : self
	{
		$this->setAll($snapshot->values());

		return $this;
	}

	/**
	 * Performs a three-way merge against the values loaded from disk.
	 *
	 * A path changed by only one side is accepted automatically. Paths changed
	 * differently by both sides are returned as conflicts and keep their local
	 * value in the provisional result.
	 *
	 * @param array<string,mixed> $external Values read from another source.
	 * @param bool                $apply    Whether to apply a conflict-free result.
	 * @return StoreMergeResult    Merge result.
	 */
	public function mergeExternal(array $external, bool $apply = false) : StoreMergeResult
	{
		$result = $this->calculateMerge($external);
		if (!$result->isClean()) {
			$this->dispatch(new StoreConflictDetectedEvent($this, $result));
		}
		$applied = false;
		if ($apply && $result->isClean()) {
			$this->setAll($result->values());
			$applied = true;
		}
		$result = new StoreMergeResult($result->values(), $result->conflicts(), $applied);
		$this->dispatch(new StoreMergeEvent($this, $result));

		return $result;
	}

	/**
	 * Resolves every merge conflict through a callback and applies the result.
	 *
	 * The callback must return StoreMergeDecision::local(), ::remote() or
	 * ::value($custom). This keeps conflict handling deterministic while letting
	 * a command or UI decide independently for every path.
	 *
	 * @param StoreMergeResult                    $result   Merge result to resolve.
	 * @param callable(StoreMergeConflict): mixed $resolver Decision callback.
	 * @return self                                                This Store instance.
	 * @throws StoreException                                     If a callback returns an invalid decision.
	 */
	public function resolveMerge(StoreMergeResult $result, callable $resolver) : self
	{
		$values = $result->values();
		foreach ($result->conflicts() as $conflict) {
			$decision = $resolver($conflict);
			if (!$decision instanceof StoreMergeDecision) {
				throw new StoreException('A Store merge resolver must return StoreMergeDecision.');
			}
			$exists = true;
			$value = match ($decision->source()) {
				'local' => $conflict->localValue(),
				'remote' => $conflict->remoteValue(),
				'value' => $decision->customValue(),
				default => throw new StoreException('Unknown Store merge decision source.'),
			};
			if ($decision->source() === 'local') {
				$exists = $conflict->localExists();
			} elseif ($decision->source() === 'remote') {
				$exists = $conflict->remoteExists();
			}
			StoreValueTree::write($values, $conflict->path(), $exists, $value);
		}

		$this->setAll($values);
		$resolved = new StoreMergeResult($values, [], true);
		$this->dispatch(new StoreMergeEvent($this, $resolved));

		return $this;
	}

	/**
	 * Reads an external file and performs a three-way merge with this Store.
	 *
	 * @param bool $apply Whether a clean merge should replace current values.
	 * @return StoreMergeResult Merge result.
	 * @throws StoreException If the file cannot be read or decoded.
	 */
	public function mergeFile(bool $apply = false) : StoreMergeResult
	{
		if ($this->path === '') {
			throw new StoreException('This Store has no file path.');
		}

		$contents = file_get_contents($this->path);
		if ($contents === false) {
			throw new StoreException("Unable to read Store file '{$this->path}'.");
		}

		$external = $this->activeCodec()->decode($contents, $this->passphrase);
		$result = $this->calculateMerge($external);
		if (!$apply || !$result->isClean()) {
			if (!$result->isClean()) {
				$this->dispatch(new StoreConflictDetectedEvent($this, $result));
			}
			$this->dispatch(new StoreMergeEvent($this, $result));

			return $result;
		}

		$this->sourceContents = $contents;
		$this->sourceHash = hash('sha256', $contents);
		$this->loadedValues = $external;
		$this->setAll($result->values());
		$result = new StoreMergeResult($result->values(), [], true);
		$this->dispatch(new StoreMergeEvent($this, $result));

		return $result;
	}

	/**
	 * Encodes the current values using another format without changing this Store.
	 *
	 * @param StoreFormat $format     Target format.
	 * @param string|null $passphrase Secret for encrypted output.
	 * @return string         Encoded document.
	 * @throws StoreException If the target codec cannot encode the values.
	 */
	public function export(StoreFormat $format, ?string $passphrase = null) : string
	{
		return StoreCodecRegistry::get($format)->encode(StoreSecret::unwrap($this->values), $passphrase ?? $this->passphrase);
	}

	/**
	 * Creates an in-memory Store using another format.
	 *
	 * @param StoreFormat $format     Target format.
	 * @param string|null $path       Optional target path.
	 * @param string|null $passphrase Secret for encrypted output.
	 * @return self        Converted Store.
	 */
	public function convert(StoreFormat $format, ?string $path = null, ?string $passphrase = null) : self
	{
		return self::fromArray(StoreSecret::unwrap($this->values), $format, $path, $passphrase ?? $this->passphrase);
	}

	/**
	 * Enables a bounded in-memory mutation history.
	 *
	 * @param int $limit Maximum number of records to keep.
	 * @return self           This Store instance.
	 * @throws StoreException If the limit is less than one.
	 */
	public function enableHistory(int $limit = 100) : self
	{
		if ($limit < 1) {
			throw new StoreException('A Store history must keep at least one record.');
		}

		$this->historyLimit = $limit;
		$this->history = array_slice($this->history, -$limit);

		return $this;
	}

	/**
	 * Disables and clears the in-memory mutation history.
	 *
	 * @return self This Store instance.
	 */
	public function disableHistory() : self
	{
		$this->historyLimit = 0;
		$this->history = [];

		return $this;
	}

	/**
	 * Returns recorded mutations, oldest first.
	 *
	 * @return list<StoreChangeRecord> History records.
	 */
	public function history() : array
	{
		return $this->history;
	}

	/**
	 * Sets a sensitive value that is unwrapped only while encoding.
	 *
	 * @param string $key   Dot-separated path.
	 * @param string $value Secret text.
	 * @return void
	 */
	public function setSecret(string $key, string $value) : void
	{
		$this->set($key, StoreSecret::from($value));
	}

	/**
	 * Returns a sensitive value explicitly.
	 *
	 * @param string      $key     Dot-separated path.
	 * @param string|null $default Fallback value.
	 * @return string|null    Secret text or the fallback.
	 * @throws StoreException If the stored value is not a string or secret.
	 */
	public function getSecret(string $key, ?string $default = null) : ?string
	{
		$value = $this->get($key, null);
		if ($value === null) {
			return $default;
		}
		if ($value instanceof StoreSecret) {
			return $value->reveal();
		}
		if (!is_string($value)) {
			throw new StoreException("Store secret '{$key}' must be a string.");
		}

		return $value;
	}

	/**
	 * Validates this Store against a schema.
	 *
	 * @param StoreSchema $schema Schema containing the expected keys and types.
	 * @return StoreValidationResult Validation result containing grouped errors.
	 */
	public function validate(StoreSchema $schema) : StoreValidationResult
	{
		$result = $schema->validate($this);
		if (!$result->isValid()) {
			$this->dispatch(new StoreValidationFailedEvent($this, $schema, $result));
		}

		return $result;
	}

	/**
	 * Applies schema defaults and safe coercions, then validates the Store.
	 *
	 * @param StoreSchema $schema Schema to apply.
	 * @return StoreValidationResult Validation result.
	 */
	public function normalize(StoreSchema $schema) : StoreValidationResult
	{
		$result = $schema->normalizeAndValidate($this);
		if (!$result->isValid()) {
			$this->dispatch(new StoreValidationFailedEvent($this, $schema, $result));
		}

		return $result;
	}

	/**
	 * Validates this Store and throws when a rule fails.
	 *
	 * @param StoreSchema $schema Schema containing the expected keys and types.
	 * @return void
	 * @throws StoreValidationException If one or more rules fail.
	 */
	public function validateOrFail(StoreSchema $schema) : void
	{
		$this->validate($schema)->throwIfInvalid();
	}

	/**
	 * Creates a migration runner bound to this Store.
	 *
	 * @param string $versionKey Nested key used to store the schema version.
	 * @return StoreMigrationRunner Migration runner bound to this Store.
	 */
	public function migrations(string $versionKey = '_meta.version') : StoreMigrationRunner
	{
		return new StoreMigrationRunner($this, $versionKey);
	}

	/**
	 * Returns whether this Store has unsaved changes.
	 *
	 * @return bool True when values have changed since the last save or reload.
	 */
	public function isDirty() : bool
	{
		return $this->dirty;
	}

	/**
	 * Enables coalesced asynchronous saving after mutations.
	 *
	 * Only one async write may run at a time; changes made while it runs are
	 * persisted by one follow-up write.
	 *
	 * @return self This Store instance.
	 */
	public function enableAutoSave() : self
	{
		$this->autoSaveEnabled = true;
		if ($this->isDirty()) {
			$this->requestAutoSave();
		}

		return $this;
	}

	/**
	 * Enables or disables coalesced asynchronous saving.
	 *
	 * @param bool $enabled Whether mutations should trigger auto-save.
	 * @return self This Store instance.
	 */
	public function setAutoSave(bool $enabled) : self
	{
		if ($enabled) {
			return $this->enableAutoSave();
		}

		return $this->disableAutoSave();
	}

	/**
	 * Disables auto-save and clears a pending follow-up write.
	 *
	 * An async write that is already running is allowed to finish.
	 *
	 * @return self This Store instance.
	 */
	public function disableAutoSave() : self
	{
		$this->autoSaveEnabled = false;
		$this->autoSavePending = false;

		return $this;
	}

	/**
	 * Watches this Store for external file changes.
	 *
	 * @param callable(Store):void|null $callback Callback invoked after reload.
	 * @return StoreWatcher              Watcher that can be started with a scheduler.
	 */
	public function watch(?callable $callback = null) : StoreWatcher
	{
		$this->watcher?->stop();
		$this->watcher = new StoreWatcher($this, $callback ?? static function(Store $store) : void {
		});

		return $this->watcher;
	}

	/**
	 * Watches one path and all of its descendants for local mutations.
	 *
	 * @param string                          $path     Dot-separated path, or empty for all paths.
	 * @param callable(StoreChangeEvent):void $listener Listener invoked after a mutation.
	 * @return int                   Listener identifier for unwatchPath().
	 */
	public function watchPath(string $path, callable $listener) : int
	{
		if ($path !== '') {
			$found = false;
			PathAccessor::get([], $path, $found);
		}

		$id = $this->nextPathListener++;
		$this->pathListeners[$id] = ['path' => $path, 'listener' => $listener];

		return $id;
	}

	/**
	 * Stops a path listener.
	 *
	 * @param int $id Listener identifier returned by watchPath().
	 * @return bool   Whether a listener was removed.
	 */
	public function unwatchPath(int $id) : bool
	{
		if (!isset($this->pathListeners[$id])) {
			return false;
		}

		unset($this->pathListeners[$id]);

		return true;
	}

	/**
	 * Stops the active file watcher.
	 *
	 * @return void
	 */
	public function stopWatching() : void
	{
		$this->watcher?->stop();
		$this->watcher = null;
	}

	/**
	 * Polls the active watcher once.
	 *
	 * @return bool True when the watcher reloaded the Store.
	 */
	public function pollChanges() : bool
	{
		return $this->watcher?->poll() ?? false;
	}

	/**
	 * Returns whether auto-save is enabled.
	 *
	 * @return bool True when mutations can schedule asynchronous saves.
	 */
	public function isAutoSaveEnabled() : bool
	{
		return $this->autoSaveEnabled;
	}

	/**
	 * Returns all values without changing the Store.
	 *
	 * @return array<string, mixed>
	 */
	public function all() : array
	{
		return $this->values;
	}

	/**
	 * Returns the top-level keys currently stored.
	 *
	 * @return list<string> Top-level keys.
	 */
	public function keys() : array
	{
		return array_values(array_map(static fn(int|string $key) : string => (string) $key, array_keys($this->values)));
	}

	/**
	 * Returns a value using dot notation for nested paths.
	 *
	 * @param string $key     Dot-separated path to read.
	 * @param mixed  $default Value returned when the path does not exist.
	 * @return mixed                     The stored value or the supplied default.
	 * @throws \InvalidArgumentException If the path is empty or invalid.
	 */
	public function get(string $key, mixed $default = null) : mixed
	{
		if (array_key_exists($key, $this->nestedCache)) {
			return $this->nestedCache[$key];
		}

		$found = false;
		$value = PathAccessor::get($this->values, $key, $found);
		if (!$found) {
			return $default;
		}

		$this->nestedCache[$key] = $value;

		return $value;
	}

	/**
	 * Checks whether a dot-separated key exists.
	 *
	 * @param string $key Path to check.
	 * @return bool   True when the path exists, including when its value is null.
	 */
	public function has(string $key) : bool
	{
		$found = false;
		PathAccessor::get($this->values, $key, $found);

		return $found;
	}

	/**
	 * Checks whether at least one of the supplied paths exists.
	 *
	 * @param list<string> $keys Paths to inspect.
	 * @return bool           Whether one path exists.
	 */
	public function hasAny(array $keys) : bool
	{
		foreach ($keys as $key) {
			if ($this->has($key)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks whether every supplied path exists.
	 *
	 * @param list<string> $keys Paths to inspect.
	 * @return bool           Whether every path exists.
	 */
	public function hasAll(array $keys) : bool
	{
		foreach ($keys as $key) {
			if (!$this->has($key)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns a string value or null when the path is absent.
	 *
	 * @param string      $key     Dot-separated path to read.
	 * @param string|null $default Value returned when the path does not exist.
	 * @return string|null               The stored string or the supplied default.
	 * @throws StoreException            If the stored value is not a string.
	 * @throws \InvalidArgumentException If the path is empty or invalid.
	 */
	public function getString(string $key, ?string $default = null) : ?string
	{
		$value = $this->get($key, $default);
		if ($value instanceof StoreSecret) {
			return $value->reveal();
		}
		if ($value !== null && !is_string($value)) {
			throw new StoreException("Store value '{$key}' must be a string.");
		}

		return $value;
	}

	/**
	 * Returns an integer value or null when the path is absent.
	 *
	 * @param string   $key     Dot-separated path to read.
	 * @param int|null $default Value returned when the path does not exist.
	 * @return int|null                  The stored integer or the supplied default.
	 * @throws StoreException            If the stored value is not an integer.
	 * @throws \InvalidArgumentException If the path is empty or invalid.
	 */
	public function getInt(string $key, ?int $default = null) : ?int
	{
		$value = $this->get($key, $default);
		if ($value !== null && !is_int($value)) {
			throw new StoreException("Store value '{$key}' must be an integer.");
		}

		return $value;
	}

	/**
	 * Returns a floating-point value or null when the path is absent.
	 *
	 * @param string     $key     Dot-separated path to read.
	 * @param float|null $default Value returned when the path does not exist.
	 * @return float|null                The stored number converted to a float, or the default.
	 * @throws StoreException            If the stored value is neither an integer nor a float.
	 * @throws \InvalidArgumentException If the path is empty or invalid.
	 */
	public function getFloat(string $key, ?float $default = null) : ?float
	{
		$value = $this->get($key, $default);
		if ($value !== null && !is_float($value) && !is_int($value)) {
			throw new StoreException("Store value '{$key}' must be a float.");
		}

		return $value === null ? null : (float) $value;
	}

	/**
	 * Returns a boolean value or null when the path is absent.
	 *
	 * @param string    $key     Dot-separated path to read.
	 * @param bool|null $default Value returned when the path does not exist.
	 * @return bool|null                 The stored boolean or the supplied default.
	 * @throws StoreException            If the stored value is not a boolean.
	 * @throws \InvalidArgumentException If the path is empty or invalid.
	 */
	public function getBool(string $key, ?bool $default = null) : ?bool
	{
		$value = $this->get($key, $default);
		if ($value !== null && !is_bool($value)) {
			throw new StoreException("Store value '{$key}' must be a boolean.");
		}

		return $value;
	}

	/**
	 * Returns a backed enum value.
	 *
	 * @template T of BackedEnum
	 * @param class-string<T> $enum    Backed enum class.
	 * @param T|null          $default Fallback value.
	 * @return T|null                    Enum value or the fallback.
	 * @throws StoreException            If the value cannot be converted.
	 */
	public function getEnum(string $key, string $enum, ?BackedEnum $default = null) : ?BackedEnum
	{
		$value = $this->get($key, $default);
		if ($value === null) {
			return null;
		}
		if (!enum_exists($enum)) {
			throw new StoreException("Store enum '{$enum}' must be a backed enum.");
		}
		if ($value instanceof $enum) {
			return $value;
		}

		try {
			return $enum::from($value);
		} catch (\Throwable $exception) {
			throw new StoreException("Store value '{$key}' is not a valid {$enum} value.", 0, $exception);
		}
	}

	/**
	 * Returns a list and verifies its numeric keys.
	 *
	 * @return list<mixed>|null List value or the fallback.
	 */
	public function getList(string $key, ?array $default = null) : ?array
	{
		$value = $this->getArray($key, $default);
		if ($value !== null && !array_is_list($value)) {
			throw new StoreException("Store value '{$key}' must be a list.");
		}

		return $value;
	}

	/**
	 * Returns an associative map and rejects list values.
	 *
	 * @return array<string|int,mixed>|null Map value or the fallback.
	 */
	public function getMap(string $key, ?array $default = null) : ?array
	{
		$value = $this->getArray($key, $default);
		if ($value !== null && $value !== [] && array_is_list($value)) {
			throw new StoreException("Store value '{$key}' must be an associative map.");
		}

		return $value;
	}

	/**
	 * Returns an immutable date/time value parsed from an ISO string.
	 *
	 * @throws StoreException If the value is not a valid date/time.
	 */
	public function getDateTime(string $key, ?DateTimeImmutable $default = null) : ?DateTimeImmutable
	{
		$value = $this->get($key, $default);
		if ($value === null) {
			return null;
		}
		if ($value instanceof DateTimeImmutable) {
			return $value;
		}
		if (!$value instanceof DateTimeInterface && !is_string($value)) {
			throw new StoreException("Store value '{$key}' must be a date/time string.");
		}

		try {
			return $value instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($value) : new DateTimeImmutable($value);
		} catch (\Throwable $exception) {
			throw new StoreException("Store value '{$key}' is not a valid date/time.", 0, $exception);
		}
	}

	/**
	 * Returns an array value or null when the path is absent.
	 *
	 * @return array<mixed>|null
	 * @param string     $key     Dot-separated path to read.
	 * @param array|null $default Value returned when the path does not exist.
	 * @throws StoreException            If the stored value is not an array.
	 * @throws \InvalidArgumentException If the path is empty or invalid.
	 */
	public function getArray(string $key, ?array $default = null) : ?array
	{
		$value = $this->get($key, $default);
		if ($value !== null && !is_array($value)) {
			throw new StoreException("Store value '{$key}' must be an array.");
		}

		return $value;
	}

	/**
	 * Returns a list and verifies that every item is a string.
	 *
	 * @param string                  $key     Dot-separated path to read.
	 * @param array<int|string,mixed> $default Values used when the path does not exist.
	 * @return list<string>              The stored string values.
	 * @throws StoreException            If the stored value or one of its items is not valid.
	 * @throws \InvalidArgumentException If the path is empty or invalid.
	 */
	public function getStringList(string $key, array $default = []) : array
	{
		$value = $this->getArray($key, $default) ?? [];
		foreach ($value as $item) {
			if (!is_string($item)) {
				throw new StoreException("Store value '{$key}' must contain only strings.");
			}
		}

		return array_values($value);
	}

	/**
	 * Sets a value using dot notation for nested paths.
	 *
	 * @param string $key   Dot-separated path to update.
	 * @param mixed  $value Value to store.
	 * @throws \InvalidArgumentException If the path is empty or invalid.
	 */
	public function set(string $key, mixed $value = true) : void
	{
		$this->assertWritable();
		$found = false;
		$oldValue = PathAccessor::get($this->values, $key, $found);
		if ($found && $oldValue === $value) {
			return;
		}
		if (!$this->dispatchChange($key, $found ? $oldValue : null, $value, 'set')) {
			return;
		}

		PathAccessor::set($this->values, $key, $value);
		$this->nestedCache = [];
		$this->revision++;
		$this->dirty = true;
		$this->recordChange($key, $found ? $oldValue : null, $value, 'set');
		$this->notifyPathListeners($key, $found ? $oldValue : null, $value, 'set');
		$this->requestAutoSave();
	}

	/**
	 * Replaces all Store values.
	 *
	 * @param array<string, mixed> $values Complete replacement value tree.
	 */
	public function setAll(array $values) : void
	{
		$this->assertWritable();
		if ($values === $this->values) {
			return;
		}
		if (!$this->dispatchChange(null, $this->values, $values, 'replace')) {
			return;
		}

		$oldValues = $this->values;
		$this->values = $values;
		$this->nestedCache = [];
		$this->revision++;
		$this->dirty = true;
		$this->recordChange(null, $oldValues, $values, 'replace');
		$this->notifyPathListeners(null, $oldValues, $values, 'replace');
		$this->requestAutoSave();
	}

	/**
	 * Removes a top-level or nested value and returns silently when absent.
	 *
	 * @param string $key Dot-separated path to remove.
	 * @throws \InvalidArgumentException If the path is empty or invalid.
	 */
	public function remove(string $key) : void
	{
		$this->assertWritable();
		$found = false;
		$oldValue = PathAccessor::get($this->values, $key, $found);
		if (!$found || !$this->dispatchChange($key, $oldValue, null, 'remove')) {
			return;
		}

		PathAccessor::remove($this->values, $key);
		$this->nestedCache = [];
		$this->revision++;
		$this->dirty = true;
		$this->recordChange($key, $oldValue, null, 'remove');
		$this->notifyPathListeners($key, $oldValue, null, 'remove');
		$this->requestAutoSave();
	}

	/**
	 * Removes several paths as one transaction.
	 *
	 * @param list<string> $keys Paths to remove.
	 * @return int           Number of existing paths removed.
	 */
	public function removeMany(array $keys) : int
	{
		$removed = 0;
		$this->beginTransaction();
		try {
			foreach ($keys as $key) {
				if ($this->has($key)) {
					$this->remove($key);
					$removed++;
				}
			}
			$this->commitTransaction();
		} catch (\Throwable $exception) {
			$this->rollbackTransaction();
			throw $exception;
		}

		return $removed;
	}

	/**
	 * Adds missing default values without overwriting existing values.
	 *
	 * @param array<string, mixed> $defaults Default values to merge into the Store.
	 */
	public function setDefaults(array $defaults) : void
	{
		$values = array_replace_recursive($defaults, $this->values);
		if ($values === $this->values) {
			return;
		}
		if (!$this->dispatchChange(null, $this->values, $values, 'defaults')) {
			return;
		}

		$oldValues = $this->values;
		$this->values = $values;
		$this->nestedCache = [];
		$this->revision++;
		$this->dirty = true;
		$this->recordChange(null, $oldValues, $values, 'defaults');
		$this->notifyPathListeners(null, $oldValues, $values, 'defaults');
		$this->requestAutoSave();
	}

	/**
	 * Adds defaults and returns this instance for fluent setup.
	 *
	 * @param array<string, mixed> $defaults Default values to merge into the Store.
	 * @return self                 This Store instance.
	 */
	public function withDefaults(array $defaults) : self
	{
		$this->setDefaults($defaults);

		return $this;
	}

	/**
	 * Computes a portable patch from another value tree to this Store.
	 *
	 * The patch contains dot paths to set and remove, which makes it suitable
	 * for audit logs, synchronization and small incremental updates.
	 *
	 * @param array<string, mixed>|null $against Values to compare with, or the last loaded values.
	 * @return array{set: array<string, mixed>, remove: list<string>} Patch to reach this Store.
	 */
	public function diff(?array $against = null) : array
	{
		$set = [];
		$remove = [];
		$this->buildDiff($this->values, $against ?? $this->loadedValues, '', $set, $remove);

		return ['set' => $set, 'remove' => $remove];
	}

	/**
	 * Applies a patch produced by diff().
	 *
	 * @param array<string, mixed> $patch Patch to apply.
	 * @return self                 This Store instance.
	 * @throws StoreException       If the patch contains an invalid operation.
	 */
	public function applyPatch(array $patch) : self
	{
		$set = $patch['set'] ?? [];
		$remove = $patch['remove'] ?? [];
		if (!is_array($set) || !is_array($remove)) {
			throw new StoreException('A Store patch must contain array set and remove operations.');
		}

		$this->transaction(function() use ($set, $remove) : void {
			foreach ($set as $key => $value) {
				if (!is_string($key)) {
					throw new StoreException('Store patch paths must be strings.');
				}

				$this->set($key, $value);
			}

			foreach ($remove as $key) {
				if (!is_string($key)) {
					throw new StoreException('Store patch removal paths must be strings.');
				}

				$this->remove($key);
			}
		});

		return $this;
	}

	/**
	 * Serializes the current values without writing them to disk.
	 *
	 * @return string         Encoded Store content.
	 * @throws StoreException If the active codec cannot encode the values.
	 */
	public function toString() : string
	{
		$values = StoreSecret::unwrap($this->values);
		$loadedValues = StoreSecret::unwrap($this->loadedValues);
		if ($this->sourceContents !== null && $values === $loadedValues) {
			return $this->sourceContents;
		}

		if ($this->sourceContents !== null) {
			$edited = StoreSourceEditor::update($this->format, $this->sourceContents, $loadedValues, $values);
			if ($edited !== null) {
				return $edited;
			}
		}

		return $this->activeCodec()->encode($values, $this->passphrase);
	}

	/**
	 * Saves the Store synchronously or asynchronously.
	 *
	 * The named async option keeps synchronous and asynchronous persistence under
	 * one method.
	 *
	 * @param bool $async Whether the Store should be written asynchronously.
	 * @return Promise<bool>|null A Promise for asynchronous saves, or null after a synchronous save.
	 * @throws StoreException     If synchronous saving fails or the Store has no path.
	 */
	public function save(bool $async = false) : ?Promise
	{
		if ($async) {
			return $this->saveInBackground();
		}

		$this->saveNow();

		return null;
	}

	/**
	 * Serializes and saves immediately on the calling thread.
	 *
	 * @throws StoreException If the Store has no path or cannot be written.
	 */
	private function saveNow() : void
	{
		$this->assertWritable();
		if ($this->path === '') {
			throw new StoreException('A path is required to save this Store.');
		}
		$this->asyncGeneration++;

		try {
			if (!$this->dispatchCancellable(new StoreBeforeWriteEvent($this, false))) {
				throw new StoreException('Store write was cancelled by a listener.');
			}
			$this->emit(StoreHook::BeforeSave);
			$contents = $this->toString();
			$this->writeAtomically($this->path, $contents);
			$this->sourceContents = $contents;
			$this->sourceHash = hash('sha256', $contents);
			$this->loadedValues = $this->values;
			$this->autoSavePending = false;
			$this->dirty = false;
			$this->resolveQueuedSaves(true);
			$this->emit(StoreHook::AfterSave);
			$this->dispatch(new StoreSaveEvent($this, false));
			$this->logger?->debug('Saved Store document synchronously.');
		} catch (\Throwable $exception) {
			$this->emitError($exception, 'save');
			throw $exception;
		}
	}

	/**
	 * Replaces the secret used by the active codec.
	 *
	 * @param string $secret Serialized codec secret.
	 * @return void
	 */
	protected function replaceCodecSecret(string $secret) : void
	{
		$this->passphrase = $secret;
		$this->revision++;
		$this->dirty = true;
	}

	/**
	 * Serializes and saves a snapshot in PocketMine's async worker pool.
	 *
	 * The promise resolves when the requested revision has been written. Calls
	 * made while an older snapshot is active receive a queued Promise that waits
	 * for the newer snapshot instead of incorrectly returning the old Promise.
	 *
	 * @return Promise<bool> A promise resolved after the snapshot is written.
	 */
	private function saveInBackground() : Promise
	{
		$this->assertWritable();
		if ($this->customCodec !== null) {
			throw new StoreException('Custom Store codecs must be saved synchronously because they cannot be sent to an async worker.');
		}
		if ($this->activeSavePromise !== null) {
			if ($this->activeSaveRevision === $this->revision) {
				return $this->activeSavePromise;
			}

			$resolver = new PromiseResolver();
			$this->queuedSaveResolvers[] = $resolver;

			return $resolver->getPromise();
		}

		$this->autoSavePending = false;

		return $this->startSaveAsync();
	}

	/**
	 * Invalidates an active async write and rejects queued write callers.
	 *
	 * PocketMine cannot stop a worker that is already encoding, so its temporary
	 * result is discarded when it completes.
	 *
	 * @return self This Store instance.
	 */
	public function cancelPendingWrites() : self
	{
		$this->asyncGeneration++;
		$this->autoSavePending = false;
		$this->rejectQueuedSaves();

		return $this;
	}

	/**
	 * Starts one asynchronous write after the public coalescing checks complete.
	 *
	 * @return Promise<bool> A promise resolved after the snapshot is written.
	 */
	private function startSaveAsync() : Promise
	{
		$resolver = new PromiseResolver();
		$promise = $resolver->getPromise();
		if ($this->path === '') {
			$resolver->reject();

			return $promise;
		}
		if (!$this->dispatchCancellable(new StoreBeforeWriteEvent($this, true))) {
			$resolver->reject();

			return $promise;
		}

		$this->emit(StoreHook::BeforeSave);
		$revision = $this->revision;
		$generation = $this->asyncGeneration;
		$this->activeSavePromise = $promise;
		$this->activeSaveRevision = $revision;
		$promise->onCompletion(function(bool $saved) use ($revision) : void {
			$this->activeSavePromise = null;
			$this->activeSaveRevision = null;
			if ($saved && $revision === $this->revision) {
				$this->autoSavePending = false;
				$this->dirty = false;
				$this->resolveQueuedSaves(true);

				return;
			}

			if ($revision !== $this->revision && ($this->queuedSaveResolvers !== [] || $this->autoSavePending)) {
				$this->autoSavePending = false;
				$this->startSaveAsync();

				return;
			}

			if (!$saved) {
				$this->rejectQueuedSaves();
			}
		}, function() use ($promise) : void {
			if ($this->activeSavePromise === $promise) {
				$this->activeSavePromise = null;
				$this->activeSaveRevision = null;
			}

			if ($this->activeSaveRevision !== $this->revision && ($this->queuedSaveResolvers !== [] || $this->autoSavePending)) {
				$this->autoSavePending = false;
				$this->startSaveAsync();
			} else {
				$this->rejectQueuedSaves();
			}
		});

		$workerResolver = new PromiseResolver();
		$workerPromise = $workerResolver->getPromise();
		$workerPromise->onCompletion(function(string $temporaryPath) use ($resolver, $revision, $generation) : void {
			if ($generation !== $this->asyncGeneration || $revision !== $this->revision) {
				@unlink($temporaryPath);
				$resolver->resolve(false);

				return;
			}

			try {
				$this->replaceTemporaryFile($temporaryPath);

				$this->sourceContents = null;
				$this->sourceHash = is_file($this->path) ? (hash_file('sha256', $this->path) ?: null) : null;
				$this->loadedValues = $this->values;
				$this->dirty = false;
				$this->emit(StoreHook::AfterSave);
				$this->dispatch(new StoreSaveEvent($this, true));
				$this->logger?->debug('Saved Store document asynchronously.');
				$resolver->resolve(true);
			} catch (\Throwable $exception) {
				@unlink($temporaryPath);
				$this->emitError($exception, 'save_async');
				$resolver->reject();
			}
		}, function() use ($resolver) : void {
			$this->emitError(new StoreException('Unable to encode Store asynchronously.'), 'save_async');
			$resolver->reject();
		});
		Server::getInstance()->getAsyncPool()->submitTask(new StoreSaveAsyncTask($this->path, StoreSecret::unwrap($this->values), $this->format, $this->passphrase, $workerResolver));

		return $promise;
	}

	/**
	 * Resolves callers waiting for the latest queued snapshot.
	 */
	private function resolveQueuedSaves(bool $saved) : void
	{
		$resolvers = $this->queuedSaveResolvers;
		$this->queuedSaveResolvers = [];
		foreach ($resolvers as $resolver) {
			$resolver->resolve($saved);
		}
	}

	/**
	 * Rejects callers waiting for a snapshot that could not be written.
	 */
	private function rejectQueuedSaves() : void
	{
		$resolvers = $this->queuedSaveResolvers;
		$this->queuedSaveResolvers = [];
		foreach ($resolvers as $resolver) {
			$resolver->reject();
		}
	}

	/**
	 * Reloads the Store synchronously or asynchronously.
	 *
	 * The named async option keeps synchronous and asynchronous reloads under
	 * one method.
	 *
	 * @param bool $async Whether the Store should be reloaded asynchronously.
	 * @return Promise<self>|null A Promise for asynchronous reloads, or null after a synchronous reload.
	 * @throws StoreException     If synchronous loading fails or the Store has no path.
	 */
	public function reload(bool $async = false) : ?Promise
	{
		if ($async) {
			return $this->reloadInBackground();
		}

		if ($this->path === '') {
			throw new StoreException('This Store has no file path.');
		}

		try {
			if (!$this->dispatchCancellable(new StoreBeforeReloadEvent($this, false))) {
				throw new StoreException('Store reload was cancelled by a listener.');
			}
			$contents = file_get_contents($this->path);
			if ($contents === false) {
				throw new StoreException("Unable to read Store file '{$this->path}'.");
			}

			try {
				$this->values = $this->activeCodec()->decode($contents, $this->passphrase);
			} catch (\Throwable $exception) {
				$recovered = $this->recoverDecodedFile();
				if ($recovered === null) {
					throw $exception;
				}

				[$contents, $this->values, $backupPath] = $recovered;
				$this->dispatch(new StoreRecoveryEvent($this, $backupPath));
				$this->logger?->warning("Recovered Store document from backup '{$backupPath}'.");
			}
			$this->sourceContents = $contents;
			$this->sourceHash = hash('sha256', $contents);
			$this->loadedValues = $this->values;
			$this->nestedCache = [];
			$this->revision++;
			$this->autoSavePending = false;
			$this->dirty = false;
			$this->emit(StoreHook::AfterReload);
			$this->dispatch(new StoreReloadEvent($this, false));
			$this->logger?->debug('Reloaded Store document synchronously.');
		} catch (\Throwable $exception) {
			$this->emitError($exception, 'reload');
			throw $exception;
		}

		return null;
	}

	/**
	 * Reloads a file into a temporary Store, validates it, then applies it.
	 *
	 * The active values remain untouched when decoding or validation fails.
	 *
	 * @throws StoreException If reading, decoding or validation fails.
	 */
	public function reloadValidated(StoreSchema $schema) : void
	{
		if ($this->path === '') {
			throw new StoreException('This Store has no file path.');
		}
		if (!$this->dispatchCancellable(new StoreBeforeReloadEvent($this, false))) {
			throw new StoreException('Store reload was cancelled by a listener.');
		}

		try {
			$candidate = $this->readReloadCandidate();
			$result = $schema->validate(Store::fromArray($candidate['values']));
			if (!$result->isValid()) {
				$this->dispatch(new StoreValidationFailedEvent($this, $schema, $result));
				$result->throwIfInvalid();
			}
			$this->applyReloadCandidate($candidate, false);
		} catch (\Throwable $exception) {
			$this->emitError($exception, 'reload_validated');
			throw $exception;
		}
	}

	/**
	 * Reloads and validates a file in PocketMine's async worker pool.
	 *
	 * @return Promise<self> Promise resolving after the validated replacement.
	 */
	public function reloadValidatedAsync(StoreSchema $schema) : Promise
	{
		if ($this->path === '' || $this->customCodec !== null) {
			$resolver = new PromiseResolver();
			$resolver->reject();

			return $resolver->getPromise();
		}
		if ($this->activeReloadPromise !== null) {
			return $this->activeReloadPromise;
		}
		$resolver = new PromiseResolver();
		$promise = $resolver->getPromise();
		if (!$this->dispatchCancellable(new StoreBeforeReloadEvent($this, true))) {
			$resolver->reject();

			return $promise;
		}
		$this->activeReloadPromise = $promise;
		$revision = $this->revision;
		$loaded = count($this->dependencies()) > 1
			? self::openWithIncludesAsync($this->path, $this->format, [], $this->passphrase)
			: self::openAsyncInternal($this->path, $this->format, [], $this->passphrase, false);
		$loaded->onCompletion(function(Store $candidate) use ($resolver, $schema, $revision) : void {
			$this->activeReloadPromise = null;
			try {
				if ($revision !== $this->revision) {
					throw new StoreException('Validated Store reload became stale before it could be applied.');
				}
				$result = $schema->validate($candidate);
				if (!$result->isValid()) {
					$this->dispatch(new StoreValidationFailedEvent($this, $schema, $result));
					$result->throwIfInvalid();
				}
				$this->values = $candidate->all();
				$this->sourceContents = null;
				$this->sourceHash = is_file($this->path) ? (hash_file('sha256', $this->path) ?: null) : null;
				$this->loadedValues = $this->values;
				$this->nestedCache = [];
				$this->dependencyGraph = $candidate->dependencyGraph();
				$this->dependencyFiles = $candidate->dependencies();
				$this->revision++;
				$this->autoSavePending = false;
				$this->dirty = false;
				$this->emit(StoreHook::AfterReload);
				$this->dispatch(new StoreReloadEvent($this, true));
				$resolver->resolve($this);
			} catch (\Throwable $exception) {
				$this->emitError($exception, 'reload_validated_async');
				$resolver->reject();
			}
		}, function() use ($resolver) : void {
			$this->activeReloadPromise = null;
			$this->emitError(new StoreException('Unable to reload Store asynchronously.'), 'reload_validated_async');
			$resolver->reject();
		});

		return $promise;
	}

	/**
	 * Reloads the file only when its contents changed since the last load.
	 *
	 * Dirty Stores are left untouched and return false so local changes remain
	 * safe until the caller decides how to resolve the conflict.
	 *
	 * @return bool           True when a reload was performed.
	 * @throws StoreException If the file cannot be read or parsed.
	 */
	public function reloadIfChanged() : bool
	{
		if ($this->path === '') {
			throw new StoreException('This Store has no file path.');
		}

		$contents = file_get_contents($this->path);
		if ($contents === false) {
			throw new StoreException("Unable to read Store file '{$this->path}'.");
		}
		if ($this->sourceHash !== null && hash('sha256', $contents) === $this->sourceHash) {
			return false;
		}
		if ($this->dirty) {
			return false;
		}
		if (!$this->dispatchCancellable(new StoreBeforeReloadEvent($this, false))) {
			return false;
		}

		$this->values = $this->activeCodec()->decode($contents, $this->passphrase);
		$this->sourceContents = $contents;
		$this->sourceHash = hash('sha256', $contents);
		$this->loadedValues = $this->values;
		$this->nestedCache = [];
		$this->revision++;
		$this->autoSavePending = false;
		$this->dirty = false;
		$this->emit(StoreHook::AfterReload);
		$this->dispatch(new StoreReloadEvent($this, false));

		return true;
	}

	/**
	 * Reads and parses the file in the async worker pool.
	 *
	 * The promise resolves to this instance after its values are replaced.
	 *
	 * @return Promise<self> A promise resolved with this Store instance.
	 */
	private function reloadInBackground() : Promise
	{
		if ($this->customCodec !== null) {
			$resolver = new PromiseResolver();
			$resolver->reject();

			return $resolver->getPromise();
		}
		$resolver = new PromiseResolver();
		$promise = $resolver->getPromise();
		if ($this->path === '') {
			$resolver->reject();

			return $promise;
		}
		if ($this->activeReloadPromise !== null) {
			return $this->activeReloadPromise;
		}
		if (!$this->dispatchCancellable(new StoreBeforeReloadEvent($this, true))) {
			$resolver->reject();

			return $promise;
		}
		$this->activeReloadPromise = $promise;

		$revision = $this->revision;
		$load = function() use ($resolver, $revision) : void {
			$loaded = count($this->dependencies()) > 1
				? self::openWithIncludesAsync($this->path, $this->format, [], $this->passphrase)
				: self::openAsyncInternal($this->path, $this->format, [], $this->passphrase, false);
			$loaded->onCompletion(function(Store $store) use ($resolver, $revision) : void {
				$this->activeReloadPromise = null;
				if ($revision !== $this->revision) {
					$resolver->reject();

					return;
				}

				$this->values = $store->all();
				$this->sourceContents = null;
				$this->sourceHash = is_file($this->path) ? (hash_file('sha256', $this->path) ?: null) : null;
				$this->loadedValues = $this->values;
				$this->nestedCache = [];
				$this->revision++;
				$this->autoSavePending = false;
				$this->dirty = false;
				$this->dependencyGraph = $store->dependencyGraph();
				$this->dependencyFiles = $store->dependencies();
				$this->dispatch(new StoreReloadEvent($this, true));
				$this->logger?->debug('Reloaded Store document asynchronously.');
				$resolver->resolve($this);
			}, function() use ($resolver) : void {
				$this->activeReloadPromise = null;
				$this->emitError(new StoreException('Unable to reload Store asynchronously.'), 'reload_async');
				$resolver->reject();
			});
		};
		$waitForWrites = function() use (&$waitForWrites, $load) : void {
			if ($this->activeSavePromise !== null) {
				$this->activeSavePromise->onCompletion($waitForWrites, $waitForWrites);

				return;
			}

			$load();
		};
		$waitForWrites();

		return $promise;
	}

	private static function resolveFormat(string $file, StoreFormat|int|null $format) : StoreFormat
	{
		if ($format instanceof StoreFormat) {
			return $format;
		}
		if ($format === null) {
			return StoreFormat::fromPath($file);
		}

		return match ((string) $format) {
			'0' => StoreFormat::Ini,
			'1' => StoreFormat::Json,
			'2' => StoreFormat::Yaml,
			default => throw new StoreException("Unsupported numeric Store format '{$format}'."),
		};
	}

	/**
	 * Reads a replacement without mutating the active Store.
	 *
	 * @return array{values: array<string,mixed>, contents: string|null, graph: StoreDependencyGraph|null, files: list<string>}
	 */
	private function readReloadCandidate() : array
	{
		if (count($this->dependencies()) > 1) {
			$included = StoreIncludeLoader::load($this->path, $this->format, $this->passphrase);
			$contents = file_get_contents($this->path);

			return [
				'values' => $included['values'],
				'contents' => $contents === false ? null : $contents,
				'graph' => $included['graph'],
				'files' => $included['files'],
			];
		}

		$contents = file_get_contents($this->path);
		if ($contents === false) {
			throw new StoreException("Unable to read Store file '{$this->path}'.");
		}

		return [
			'values' => $this->activeCodec()->decode($contents, $this->passphrase),
			'contents' => $contents,
			'graph' => null,
			'files' => [],
		];
	}

	/**
	 * Applies a previously decoded replacement on the main thread.
	 *
	 * @param array{values: array<string,mixed>, contents: string|null, graph: StoreDependencyGraph|null, files: list<string>} $candidate
	 */
	private function applyReloadCandidate(array $candidate, bool $async) : void
	{
		$this->values = $candidate['values'];
		$this->sourceContents = $candidate['contents'];
		$this->sourceHash = $candidate['contents'] === null ? null : hash('sha256', $candidate['contents']);
		$this->loadedValues = $this->values;
		$this->nestedCache = [];
		$this->dependencyGraph = $candidate['graph'];
		$this->dependencyFiles = $candidate['files'];
		$this->revision++;
		$this->autoSavePending = false;
		$this->dirty = false;
		$this->dispatchIncludeEvents();
		$this->emit(StoreHook::AfterReload);
		$this->dispatch(new StoreReloadEvent($this, $async));
	}

	/** @return StoreCodecInterface Active built-in or custom codec. */
	private function activeCodec() : StoreCodecInterface
	{
		return $this->customCodec ?? StoreCodecRegistry::get($this->format);
	}

	/**
	 * Requests one coalesced auto-save after a value mutation.
	 */
	private function requestAutoSave() : void
	{
		if (!$this->autoSaveEnabled || $this->path === '') {
			return;
		}

		$this->autoSavePending = true;
		if ($this->transactionDepth > 0) {
			return;
		}
		if ($this->activeSavePromise !== null) {
			return;
		}

		$this->autoSavePending = false;
		$this->startSaveAsync();
	}

	private function writeAtomically(string $path, string $contents) : void
	{
		$directory = dirname($path);
		if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
			throw new StoreException("Unable to create Store directory '{$directory}'.");
		}

		$tempPath = tempnam($directory, '.store-');
		if ($tempPath === false) {
			throw new StoreException("Unable to create a temporary Store file in '{$directory}'.");
		}

		try {
			if (file_put_contents($tempPath, $contents) === false) {
				throw new StoreException("Unable to save Store file '{$path}'.");
			}
			if (!chmod($tempPath, $this->filePolicy->mode())) {
				throw new StoreException("Unable to set Store file permissions for '{$path}'.");
			}

			$this->replaceTemporaryFile($tempPath, $path);
		} finally {
			if (is_file($tempPath)) {
				@unlink($tempPath);
			}
		}
	}

	private function replaceTemporaryFile(string $temporaryPath, ?string $path = null) : void
	{
		$path ??= $this->path;
		$lock = null;
		if ($this->filePolicy->locking()) {
			$lockPath = $path . '.lock';
			$lock = fopen($lockPath, 'c+');
			if ($lock === false) {
				throw new StoreLockException("Unable to open Store lock '{$lockPath}'.");
			}

			$deadline = microtime(true) + $this->filePolicy->lockTimeout();
			while (!flock($lock, LOCK_EX | LOCK_NB)) {
				if (microtime(true) >= $deadline) {
					$owner = trim((string) @file_get_contents($lockPath));
					fclose($lock);
					$ownerMessage = $owner === '' ? 'the owner could not be identified' : "owner information: {$owner}";
					throw new StoreLockException(sprintf(
						"Unable to lock Store file '%s' after %.2f seconds; %s.",
						$path,
						$this->filePolicy->lockTimeout(),
						$ownerMessage,
					));
				}

				usleep($this->filePolicy->lockPollMicros());
			}

			if (!flock($lock, LOCK_EX)) {
				if ($lock !== null) {
					fclose($lock);
				}

				throw new StoreLockException("Unable to lock Store file '{$path}'.");
			}
			ftruncate($lock, 0);
			fwrite($lock, sprintf('pid=%d; acquired=%s', getmypid(), date('c')));
		}

		try {
			$this->assertFileUnchanged($path);
			$backupPath = StoreBackupManager::create($path, $this->backupPolicy);
			if ($backupPath !== null) {
				$this->dispatch(new StoreBackupEvent($this, $backupPath));
				$this->logger?->debug("Created Store backup '{$backupPath}'.");
			}

			if (!rename($temporaryPath, $path) || !chmod($path, $this->filePolicy->mode())) {
				throw new StoreException("Unable to save Store file '{$path}'.");
			}
		} finally {
			if ($lock !== null) {
				flock($lock, LOCK_UN);
				fclose($lock);
			}
		}
	}

	/**
	 * Recursively builds a set/remove patch.
	 *
	 * @param array<string, mixed> $current Current values.
	 * @param array<string, mixed> $against Previous values.
	 * @param string               $prefix  Current dot path.
	 * @param array<string, mixed> $set     Values to write.
	 * @param list<string>         $remove  Paths to remove.
	 */
	private function buildDiff(array $current, array $against, string $prefix, array &$set, array &$remove) : void
	{
		$keys = array_unique([...array_keys($current), ...array_keys($against)]);
		foreach ($keys as $key) {
			$path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
			$hasCurrent = array_key_exists($key, $current);
			$hasPrevious = array_key_exists($key, $against);
			if (!$hasCurrent) {
				$remove[] = $path;
				continue;
			}

			if (!$hasPrevious) {
				$set[$path] = $current[$key];
				continue;
			}

			$currentValue = $current[$key];
			$previousValue = $against[$key];
			if (is_array($currentValue) && is_array($previousValue)) {
				$this->buildDiff($currentValue, $previousValue, $path, $set, $remove);
			} elseif ($currentValue !== $previousValue) {
				$set[$path] = $currentValue;
			}
		}
	}

	/**
	 * Calculates a merge without changing the Store or dispatching an event.
	 *
	 * @param array<string,mixed> $external External value tree.
	 * @return StoreMergeResult    Provisional merge result.
	 */
	private function calculateMerge(array $external) : StoreMergeResult
	{
		$conflicts = [];
		$values = StoreValueTree::merge($this->loadedValues, $this->values, $external, $conflicts);

		return new StoreMergeResult($values, $conflicts);
	}

	/**
	 * Adds one mutation to the optional bounded history.
	 *
	 * @param string|null $path
	 * @param mixed       $oldValue
	 * @param mixed       $newValue
	 * @param string      $operation
	 */
	private function recordChange(?string $path, mixed $oldValue, mixed $newValue, string $operation) : void
	{
		if ($this->historyLimit === 0) {
			return;
		}

		$this->history[] = new StoreChangeRecord($path, $oldValue, $newValue, $operation, $this->revision, microtime(true));
		if (count($this->history) > $this->historyLimit) {
			$this->history = array_slice($this->history, -$this->historyLimit);
		}
	}

	/**
	 * Notifies local path listeners after a mutation has been accepted.
	 *
	 * @param string|null $path
	 * @param mixed       $oldValue
	 * @param mixed       $newValue
	 * @param string      $operation
	 */
	private function notifyPathListeners(?string $path, mixed $oldValue, mixed $newValue, string $operation) : void
	{
		foreach ($this->pathListeners as $listener) {
			$watched = $listener['path'];
			if ($watched !== '' && $path !== null && $path !== $watched && !str_starts_with($path, $watched . '.')) {
				continue;
			}
			if ($watched !== '' && $path === null) {
				continue;
			}

			($listener['listener'])(new StoreChangeEvent($this, $path, $oldValue, $newValue, $operation));
		}
	}

	/**
	 * Rejects a write when another process changed the file after this Store loaded it.
	 *
	 * @throws StoreConflictException If conflict detection is enabled and the file changed.
	 */
	private function assertFileUnchanged(string $path) : void
	{
		if ($this->filePolicy->conflict() === StoreConflictPolicy::Overwrite || $this->sourceHash === null || !is_file($path)) {
			return;
		}

		$currentHash = hash_file('sha256', $path);
		if ($currentHash !== false && $currentHash !== $this->sourceHash) {
			throw new StoreConflictException("Store file '{$path}' changed since it was loaded.");
		}
	}

	/**
	 * Tries retained backups from newest to oldest and restores the first valid one.
	 *
	 * @return array{0: string, 1: array<string,mixed>, 2: string}|null Recovered source, values and backup path.
	 */
	private function recoverDecodedFile() : ?array
	{
		if ($this->path === '' || !$this->backupPolicy->enabled()) {
			return null;
		}

		foreach (StoreBackupManager::list($this->path, $this->backupPolicy) as $backupPath) {
			$contents = file_get_contents($backupPath);
			if ($contents === false) {
				continue;
			}

			try {
				$values = $this->activeCodec()->decode($contents, $this->passphrase);
				StoreBackupManager::restore($backupPath, $this->path, $this->backupPolicy);

				return [$contents, $values, $backupPath];
			} catch (\Throwable) {
				continue;
			}
		}

		return null;
	}

	/**
	 * Rejects operations that would mutate a read-only Store.
	 *
	 * @throws StoreException When the active file policy is read-only.
	 */
	private function assertWritable() : void
	{
		if ($this->filePolicy->isReadOnly()) {
			throw new StoreException('This Store is read-only and cannot be modified.');
		}
	}

	/**
	 * Emits a lifecycle event to registered listeners.
	 *
	 * @param StoreHook $event Event to emit.
	 */
	private function emit(StoreHook $event) : void
	{
		foreach ($this->hooks[$event->value] ?? [] as $listener) {
			$listener($this);
		}
	}

	/**
	 * Emits an operation failure to error listeners.
	 *
	 * @param \Throwable $exception Failure that occurred.
	 * @param string     $operation Failure operation name.
	 */
	private function emitError(\Throwable $exception, string $operation) : void
	{
		foreach ($this->hooks[StoreHook::Error->value] ?? [] as $listener) {
			$listener($this, $exception);
		}

		$this->logger?->error("Store {$operation} failed.", $exception);
		$this->dispatch(new StoreErrorEvent($this, $operation, $exception));
	}

	/**
	 * Dispatches a cancellable mutation event when a plugin listens to it.
	 *
	 * @param string|null $key       Changed path.
	 * @param mixed       $oldValue  Previous value.
	 * @param mixed       $newValue  New value.
	 * @param string      $operation Mutation operation.
	 * @return bool        Whether the mutation may continue.
	 */
	private function dispatchChange(?string $key, mixed $oldValue, mixed $newValue, string $operation) : bool
	{
		if (!StoreChangeEvent::hasHandlers()) {
			return true;
		}

		$event = new StoreChangeEvent($this, $key, $oldValue, $newValue, $operation);
		$event->call();

		return !$event->isCancelled();
	}

	/**
	 * Dispatches a cancellable lifecycle event only when it has listeners.
	 */
	private function dispatchCancellable(PocketMineEvent $event) : bool
	{
		if (!$event::hasHandlers() || !$event instanceof Cancellable) {
			return true;
		}

		$event->call();

		return !$event->isCancelled();
	}

	/**
	 * Emits include events in dependency order after an include graph is ready.
	 */
	private function dispatchIncludeEvents() : void
	{
		if ($this->dependencyGraph === null || !StoreIncludeLoadedEvent::hasHandlers()) {
			return;
		}

		$visited = [];
		$visit = function(string $parent, int $depth) use (&$visit, &$visited) : void {
			foreach ($this->dependencyGraph?->dependencies($parent) ?? [] as $child) {
				if (isset($visited[$child])) {
					continue;
				}
				$visited[$child] = true;
				(new StoreIncludeLoadedEvent($this, $child, $parent, $depth))->call();
				$visit($child, $depth + 1);
			}
		};
		$visit($this->path, 1);
	}

	/**
	 * Dispatches an event only when a plugin has registered a listener.
	 *
	 * @param PocketMineEvent $event Event to dispatch.
	 */
	private function dispatch(PocketMineEvent $event) : void
	{
		if (!$event::hasHandlers()) {
			return;
		}

		$event->call();
	}
}
