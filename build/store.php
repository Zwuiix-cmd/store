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

require_once __DIR__ . '/../vendor/autoload.php';

use zwuiix\store\Store;
use zwuiix\store\StoreFormat;
use zwuiix\store\validation\StoreRule;
use zwuiix\store\validation\StoreSchema;
use zwuiix\store\validation\StoreValueType;

/** @var list<string> $arguments */
$arguments = array_slice($argv, 1);
$command = strtolower((string) array_shift($arguments));
$options = parse_options($arguments);

try {
	match ($command) {
		'inspect' => inspect_command($options['arguments'], $options['values']),
		'convert' => convert_command($options['arguments'], $options['values']),
		'diff' => diff_command($options['arguments']),
		'merge' => merge_command($options['arguments'], $options['values']),
		'schema' => schema_command($options['arguments']),
		'validate' => validate_command($options['arguments']),
		default => usage(),
	};
} catch (Throwable $exception) {
	fwrite(STDERR, "Store command failed: {$exception->getMessage()}\n");
	exit(1);
}

/**
 * Parses positional arguments and --key=value or --key value options.
 *
 * @param list<string> $arguments Raw command arguments.
 * @return array{arguments:list<string>, values:array<string,string>} Parsed arguments.
 */
function parse_options(array $arguments) : array
{
	$positional = [];
	$values = [];
	while ($arguments !== []) {
		$argument = array_shift($arguments);
		if ($argument === null) {
			break;
		}
		if (!str_starts_with($argument, '--')) {
			$positional[] = $argument;
			continue;
		}

		$option = substr($argument, 2);
		if (str_contains($option, '=')) {
			[$name, $value] = explode('=', $option, 2);
			$values[$name] = $value;
		} else {
			$next = $arguments[0] ?? 'true';
			$values[$option] = str_starts_with($next, '--') ? 'true' : (string) array_shift($arguments);
		}
	}

	return ['arguments' => $positional, 'values' => $values];
}

/**
 * @param list<string> $arguments @param array<string,string> $options
 */
function inspect_command(array $arguments, array $options) : void
{
	$store = Store::open(require_argument($arguments, 0), null, [], $options['passphrase'] ?? null);
	print_json($store->all());
}

/**
 * @param list<string> $arguments @param array<string,string> $options
 */
function convert_command(array $arguments, array $options) : void
{
	$source = require_argument($arguments, 0);
	$target = require_argument($arguments, 1);
	$store = Store::open($source, null, [], $options['passphrase'] ?? null);
	$converted = Store::fromArray($store->all(), StoreFormat::fromPath($target), $target, $options['passphrase'] ?? null);
	$converted->save();
	echo "Converted {$source} to {$target}.\n";
}

/**
 * @param list<string> $arguments
 */
function diff_command(array $arguments) : void
{
	$left = Store::open(require_argument($arguments, 0));
	$right = Store::open(require_argument($arguments, 1));
	print_json(Store::fromArray($right->all())->diff($left->all()));
}

/**
 * @param list<string> $arguments @param array<string,string> $options
 */
function merge_command(array $arguments, array $options) : void
{
	$basePath = require_argument($arguments, 0);
	$remotePath = require_argument($arguments, 1);
	$outputPath = require_argument($arguments, 2);
	$base = Store::open($basePath, null, [], $options['passphrase'] ?? null);
	$remote = Store::open($remotePath, null, [], $options['passphrase'] ?? null);
	$result = $base->mergeExternal($remote->all());
	$values = match ($options['prefer'] ?? '') {
		'local' => $result->preferLocal(),
		'remote' => $result->preferRemote(),
		default => $result->isClean() ? $result->values() : throw new RuntimeException('Merge contains conflicts; use --prefer=local or --prefer=remote.'),
	};
	$merged = Store::fromArray($values, StoreFormat::fromPath($outputPath), $outputPath, $options['passphrase'] ?? null);
	$merged->save();
	echo "Merged {$basePath} and {$remotePath} into {$outputPath}.\n";
}

/**
 * @param list<string> $arguments
 */
function schema_command(array $arguments) : void
{
	$store = Store::open(require_argument($arguments, 0));
	$schema = StoreSchema::create();
	foreach (flatten_values($store->all()) as $path => $value) {
		$schema->optional($path, type_for($value));
	}
	print_json($schema->toArray());
}

/**
 * @param list<string> $arguments
 */
function validate_command(array $arguments) : void
{
	$store = Store::open(require_argument($arguments, 0));
	$schemaData = json_decode(file_get_contents(require_argument($arguments, 1)), true, 512, JSON_THROW_ON_ERROR);
	if (!is_array($schemaData) || !is_array($schemaData['rules'] ?? null)) {
		throw new RuntimeException('The schema file must contain a rules object.');
	}
	$schema = StoreSchema::create();
	foreach ($schemaData['rules'] as $path => $definition) {
		if (!is_array($definition)) {
			continue;
		}
		$type = StoreValueType::from((string) ($definition['type'] ?? 'any'));
		$rule = (($definition['required'] ?? false) ? StoreRule::required($type) : StoreRule::optional($type));
		if (($definition['nullable'] ?? false) === true) {
			$rule->nullable();
		}
		$schema->rule((string) $path, $rule);
	}
	$result = $store->validate($schema);
	print_json(['valid' => $result->isValid(), 'errors' => $result->errors(), 'warnings' => $result->warnings()]);
	if (!$result->isValid()) {
		exit(1);
	}
}

/**
 * @param list<string> $arguments
 */
function require_argument(array $arguments, int $index) : string
{
	$value = $arguments[$index] ?? '';
	if (trim($value) === '') {
		throw new InvalidArgumentException('Missing command argument at position ' . ($index + 1) . '.');
	}

	return $value;
}

/**
 * @param array<string,mixed> $values @return array<string,mixed>
 */
function flatten_values(array $values, string $prefix = '') : array
{
	$result = [];
	foreach ($values as $key => $value) {
		$path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
		if (is_array($value) && $value !== []) {
			$result += flatten_values($value, $path);
		} else {
			$result[$path] = $value;
		}
	}

	return $result;
}

function type_for(mixed $value) : StoreValueType
{
	return match (true) {
		is_string($value) => StoreValueType::String,
		is_int($value) => StoreValueType::Integer,
		is_float($value) => StoreValueType::Float,
		is_bool($value) => StoreValueType::Boolean,
		is_array($value) && array_is_list($value) => StoreValueType::List,
		is_array($value) => StoreValueType::ArrayValue,
		default => StoreValueType::Any,
	};
}

/**
 * @param mixed $value
 */
function print_json(mixed $value) : void
{
	try {
		echo json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
	} catch (JsonException $exception) {
		throw new RuntimeException('Unable to encode command output.', 0, $exception);
	}
}

function usage() : never
{
	echo <<<TEXT
Usage: php build/store.php <command> [arguments] [options]

Commands:
  inspect <file>                 Print decoded values.
  convert <source> <target>      Convert between supported formats.
  diff <left> <right>            Print the patch needed to reach right.
  merge <base> <remote> <out>    Three-way merge, optionally --prefer=local|remote.
  schema <file>                  Generate a schema description from values.
  validate <file> <schema.json>  Validate a file against a generated schema.

Options:
  --passphrase=<value>           Key for encrypted Store files.
  --prefer=local|remote          Resolve merge conflicts explicitly.
TEXT;
}
