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
use zwuiix\store\StoreCodecRegistry;
use zwuiix\store\StoreFormat;

use function array_key_exists;
use function array_replace;
use function array_replace_recursive;
use function dirname;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function is_file;
use function is_string;
use function realpath;
use function str_starts_with;
use const DIRECTORY_SEPARATOR;

/**
 * Loads a root document and its controlled `$include` directives.
 */
final class StoreIncludeLoader
{
	private const array INCLUDE_KEYS = ['$include', '_include', 'include'];

	private function __construct()
	{
	}

	/**
	 * Loads all included documents from low to high priority.
	 *
	 * @return array{values: array<string,mixed>, graph: StoreDependencyGraph, files: list<string>}
	 * @throws StoreException On missing files, cycles or invalid include declarations.
	 */
	public static function load(string $root, ?StoreFormat $format = null, ?string $passphrase = null) : array
	{
		$graph = new StoreDependencyGraph();
		$loaded = self::loadFile($root, $format, $passphrase, $graph, []);

		return ['values' => $loaded['values'], 'graph' => $graph, 'files' => $graph->files($loaded['path'])];
	}

	/**
	 * @param list<string> $stack
	 * @return array{path: string, values: array<string,mixed>}
	 */
	private static function loadFile(string $file, ?StoreFormat $format, ?string $passphrase, StoreDependencyGraph $graph, array $stack) : array
	{
		$chain = [...$stack, $file];
		$path = realpath($file);
		if ($path === false || !is_file($path)) {
			throw new StoreException("Included Store file '{$file}' does not exist. Include chain: " . implode(' -> ', $chain));
		}
		if (in_array($path, $stack, true)) {
			throw new StoreException("Circular Store include detected at '{$path}'. Include chain: " . implode(' -> ', [...$stack, $path]));
		}

		$contents = file_get_contents($path);
		if ($contents === false) {
			throw new StoreException("Unable to read included Store file '{$path}'.");
		}
		$resolvedFormat = $format ?? StoreFormat::fromPath($path);
		try {
			$values = StoreCodecRegistry::get($resolvedFormat)->decode($contents, $passphrase);
		} catch (\Throwable $exception) {
			throw new StoreException("Unable to decode Store file '{$path}'. Include chain: " . implode(' -> ', [...$stack, $path]) . ' ' . $exception->getMessage(), 0, $exception);
		}
		$includes = [];
		foreach (self::INCLUDE_KEYS as $key) {
			if (array_key_exists($key, $values)) {
				if ($includes !== []) {
					throw new StoreException("Store file '{$path}' declares more than one include key.");
				}
				$declaration = $values[$key];
				unset($values[$key]);
				$includes = is_string($declaration) ? [$declaration] : $declaration;
				if (!is_array($includes)) {
					throw new StoreException("Store include declaration in '{$path}' must be a string or list.");
				}
			}
		}

		$dependencies = [];
		$merged = [];
		$includeOverrides = [];
		foreach ($includes as $include) {
			$strategy = StoreIncludeStrategy::RootWins;
			if (is_array($include)) {
				$includePathValue = $include['path'] ?? $include['file'] ?? null;
				$strategyValue = $include['strategy'] ?? StoreIncludeStrategy::RootWins->value;
				if (!is_string($includePathValue) || !is_string($strategyValue)) {
					throw new StoreException("Store include entries in '{$path}' must contain a path and string strategy. Include chain: " . implode(' -> ', [...$stack, $path]));
				}
				$include = $includePathValue;
				$strategy = StoreIncludeStrategy::fromValue($strategyValue);
			}
			if (!is_string($include) || $include === '') {
				throw new StoreException("Store include declarations in '{$path}' must contain file paths.");
			}
			$includePath = str_starts_with($include, '/') ? $include : dirname($path) . DIRECTORY_SEPARATOR . $include;
			$child = self::loadFile($includePath, null, $passphrase, $graph, [...$stack, $path]);
			$dependencies[] = $child['path'];
			$merged = match ($strategy) {
				StoreIncludeStrategy::RootWins => array_replace_recursive($merged, $child['values']),
				StoreIncludeStrategy::IncludeWins => array_replace_recursive($merged, $child['values']),
				StoreIncludeStrategy::Replace => array_replace($merged, $child['values']),
			};
			if ($strategy === StoreIncludeStrategy::IncludeWins) {
				$includeOverrides[] = $child['values'];
			}
		}
		$graph->add($path, $dependencies);

		$resolved = array_replace_recursive($merged, $values);
		foreach ($includeOverrides as $override) {
			$resolved = array_replace_recursive($resolved, $override);
		}

		return ['path' => $path, 'values' => $resolved];
	}
}
