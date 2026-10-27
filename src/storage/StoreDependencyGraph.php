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

use function array_keys;
use function array_map;
use function array_unique;
use function array_values;

/**
 * Directed graph of Store files connected by include directives.
 */
final class StoreDependencyGraph
{
	/** @var array<string, list<string>> */
	private array $edges = [];

	/**
	 * Adds a file and the files it directly includes.
	 *
	 * @param string       $file         Parent file.
	 * @param list<string> $dependencies Direct dependencies.
	 */
	public function add(string $file, array $dependencies) : void
	{
		$this->edges[$file] = array_values(array_unique($dependencies));
	}

	/**
	 * Rebuilds a graph returned by an async worker.
	 *
	 * @param array<string,list<string>> $edges Serialized graph.
	 * @return self Rebuilt graph.
	 */
	public static function fromArray(array $edges) : self
	{
		$graph = new self();
		foreach ($edges as $file => $dependencies) {
			$graph->add((string) $file, array_values(array_map(static fn(mixed $path) : string => (string) $path, $dependencies)));
		}

		return $graph;
	}

	/** @return array<string,list<string>> Complete graph. */
	public function all() : array
	{
		return $this->edges;
	}

	/** @return list<string> Direct dependencies of a file. */
	public function dependencies(string $file) : array
	{
		return $this->edges[$file] ?? [];
	}

	/** @return list<string> Every file reachable from the root, including the root. */
	public function files(string $root) : array
	{
		$files = [];
		$visit = function(string $file) use (&$visit, &$files) : void {
			if (isset($files[$file])) {
				return;
			}
			$files[$file] = true;
			foreach ($this->dependencies($file) as $dependency) {
				$visit($dependency);
			}
		};
		$visit($root);

		return array_keys($files);
	}
}
