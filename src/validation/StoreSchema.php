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

namespace zwuiix\store\validation;

use JsonException;
use zwuiix\store\Store;

use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_pop;
use function array_values;
use function count;
use function explode;
use function implode;
use function is_array;
use function json_encode;
use function sprintf;
use function str_contains;
use function str_replace;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Collection of typed and required Store validation rules.
 */
final class StoreSchema
{
	/** @var array<string, StoreRule> */
	private array $rules = [];

	/** @var array<string,string> Legacy path to canonical path. */
	private array $aliases = [];

	/** @var array<string, array{schema: self, required: bool}> */
	private array $nested = [];

	/** @var array<string, array{schema: self, required: bool}> */
	private array $nestedLists = [];

	private bool $strict = false;

	/**
	 * Creates an empty schema.
	 *
	 * @return self Empty schema.
	 */
	public static function create() : self
	{
		return new self();
	}

	/**
	 * Composes several schemas in declaration order.
	 *
	 * @param self ...$schemas Schemas to combine.
	 * @return self             Combined schema.
	 */
	public static function compose(self ...$schemas) : self
	{
		$combined = self::create();
		foreach ($schemas as $schema) {
			$combined->merge($schema);
		}

		return $combined;
	}

	/**
	 * Rejects keys that are not described by this schema.
	 *
	 * @param bool $enabled Whether strict unknown-key validation is enabled.
	 * @return self        This schema.
	 */
	public function strict(bool $enabled = true) : self
	{
		$this->strict = $enabled;

		return $this;
	}

	/** @return bool Whether unknown keys are rejected. */
	public function isStrict() : bool
	{
		return $this->strict;
	}

	/**
	 * Adds a required key rule.
	 *
	 * @param string         $key  Dot-separated Store path.
	 * @param StoreValueType $type Expected value type.
	 * @return self           This schema.
	 */
	public function required(string $key, StoreValueType $type = StoreValueType::Any) : self
	{
		$this->rules[$key] = StoreRule::required($type);

		return $this;
	}

	/**
	 * Adds an optional key rule.
	 *
	 * @param string         $key  Dot-separated Store path.
	 * @param StoreValueType $type Expected value type.
	 * @return self           This schema.
	 */
	public function optional(string $key, StoreValueType $type = StoreValueType::Any) : self
	{
		$this->rules[$key] = StoreRule::optional($type);

		return $this;
	}

	/**
	 * Adds a preconfigured rule.
	 *
	 * @param string    $key  Dot-separated Store path.
	 * @param StoreRule $rule Validation rule.
	 * @return self      This schema.
	 */
	public function rule(string $key, StoreRule $rule) : self
	{
		$this->rules[$key] = $rule;

		return $this;
	}

	/**
	 * Adds a recursively validated object schema.
	 *
	 * @param string $key      Dot-separated object path.
	 * @param self   $schema   Rules for the object value.
	 * @param bool   $required Whether the object itself is required.
	 * @return self        This schema.
	 */
	public function nested(string $key, self $schema, bool $required = true) : self
	{
		$this->nested[$key] = ['schema' => $schema, 'required' => $required];

		return $this;
	}

	/**
	 * Adds a schema applied to every object in a list.
	 *
	 * @param string $key      Dot-separated list path.
	 * @param self   $schema   Rules for every list item.
	 * @param bool   $required Whether the list itself is required.
	 * @return self        This schema.
	 */
	public function listOf(string $key, self $schema, bool $required = true) : self
	{
		$this->nestedLists[$key] = ['schema' => $schema, 'required' => $required];

		return $this;
	}

	/**
	 * Merges rules from another schema into this schema.
	 *
	 * @param StoreSchema $schema Schema whose rules should be added.
	 * @return self        This schema.
	 */
	public function merge(self $schema) : self
	{
		$this->rules = [...$this->rules, ...$schema->rules()];
		$this->nested = [...$this->nested, ...$schema->nested];
		$this->nestedLists = [...$this->nestedLists, ...$schema->nestedLists];
		$this->aliases = [...$this->aliases, ...$schema->aliases()];
		$this->strict = $this->strict || $schema->isStrict();

		return $this;
	}

	/**
	 * Returns the configured rules.
	 *
	 * @return array<string, StoreRule> Rules indexed by Store path.
	 */
	public function rules() : array
	{
		return $this->rules;
	}

	/**
	 * Registers a legacy path that may populate a canonical path.
	 *
	 * The legacy value remains in place so an application can save a deliberate
	 * migration later. Validation reports a warning when the alias is used.
	 *
	 * @param string $legacy    Deprecated dot-separated path.
	 * @param string $canonical Current dot-separated path.
	 * @return self             This schema.
	 */
	public function alias(string $legacy, string $canonical) : self
	{
		$this->aliases[$legacy] = $canonical;

		return $this;
	}

	/**
	 * Returns registered legacy aliases.
	 *
	 * @return array<string,string> Legacy path to canonical path.
	 */
	public function aliases() : array
	{
		return $this->aliases;
	}

	/**
	 * Returns a serializable schema description.
	 *
	 * @return array{rules: array<string,array<string,mixed>>, aliases: array<string,string>, nested: array<string,mixed>, lists: array<string,mixed>}
	 */
	public function toArray() : array
	{
		$rules = [];
		foreach ($this->rules as $key => $rule) {
			$rules[$key] = $rule->describe();
		}

		$nested = [];
		foreach ($this->nested as $key => $entry) {
			$nested[$key] = ['required' => $entry['required'], 'schema' => $entry['schema']->toArray()];
		}
		$lists = [];
		foreach ($this->nestedLists as $key => $entry) {
			$lists[$key] = ['required' => $entry['required'], 'schema' => $entry['schema']->toArray()];
		}

		return ['rules' => $rules, 'aliases' => $this->aliases, 'nested' => $nested, 'lists' => $lists, 'strict' => $this->strict];
	}

	/**
	 * Encodes the schema for documentation or external tooling.
	 *
	 * @param bool $pretty Whether to format the JSON over multiple lines.
	 * @return string       JSON schema description.
	 * @throws JsonException If the schema contains an unsupported value.
	 */
	public function toJson(bool $pretty = true) : string
	{
		return json_encode($this->toArray(), ($pretty ? JSON_PRETTY_PRINT : 0) | JSON_THROW_ON_ERROR);
	}

	/**
	 * Encodes the static rules as a draft 2020-12 JSON Schema document.
	 *
	 * Store aliases and conditional requirements are retained in extension
	 * fields so tooling can display the complete Store contract.
	 *
	 * @param bool $pretty Whether to format the JSON over multiple lines.
	 * @return string       JSON Schema document.
	 * @throws JsonException If the schema contains an unsupported value.
	 */
	public function toJsonSchema(bool $pretty = true) : string
	{
		$document = [
			'$schema' => 'https://json-schema.org/draft/2020-12/schema',
			'type' => 'object',
			'properties' => [],
		];
		if ($this->strict) {
			$document['additionalProperties'] = false;
		}
		foreach ($this->rules as $path => $rule) {
			$segments = explode('.', $path);
			$last = array_pop($segments);
			if ($last === '') {
				continue;
			}
			$target = &$document;
			foreach ($segments as $segment) {
				$target['properties'][$segment] ??= ['type' => 'object', 'properties' => []];
				$target = &$target['properties'][$segment];
			}
			$target['properties'][$last] = $rule->jsonSchema();
			if ($rule->isRequired()) {
				$target['required'] ??= [];
				$target['required'][] = $last;
			}
			unset($target);
		}
		foreach ($this->nested as $path => $entry) {
			$this->addNestedJsonSchema($document, $path, $entry['schema']->toJsonSchemaArray(), $entry['required']);
		}
		foreach ($this->nestedLists as $path => $entry) {
			$this->addNestedJsonSchema($document, $path, ['type' => 'array', 'items' => $entry['schema']->toJsonSchemaArray()], $entry['required']);
		}
		if ($this->aliases !== []) {
			$document['x-store-aliases'] = $this->aliases;
		}

		return json_encode($document, ($pretty ? JSON_PRETTY_PRINT : 0) | JSON_THROW_ON_ERROR);
	}

	/**
	 * Exports a human-readable Markdown contract for the schema.
	 *
	 * @return string Markdown document.
	 */
	public function toMarkdown() : string
	{
		$lines = ['# Store schema', '', '| Path | Type | Required | Details |', '| --- | --- | --- | --- |'];
		foreach ($this->rules as $path => $rule) {
			$description = $rule->describe();
			unset($description['required'], $description['type']);
			$lines[] = sprintf('| `%s` | `%s` | `%s` | `%s` |', $path, $rule->type()->value, $rule->isRequired() ? 'yes' : 'no', str_replace('|', '\\|', json_encode($description, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''));
		}
		foreach ($this->nested as $path => $entry) {
			$lines[] = sprintf('| `%s` | `object` | `%s` | Nested schema |', $path, $entry['required'] ? 'yes' : 'no');
		}
		foreach ($this->nestedLists as $path => $entry) {
			$lines[] = sprintf('| `%s` | `list<object>` | `%s` | Nested item schema |', $path, $entry['required'] ? 'yes' : 'no');
		}
		if ($this->strict) {
			$lines[] = '';
			$lines[] = 'Unknown keys are rejected.';
		}

		return implode("\n", $lines) . "\n";
	}

	/**
	 * Applies defaults declared by optional or required rules.
	 *
	 * @param Store $store Store to mutate.
	 * @return void
	 */
	public function applyDefaults(Store $store) : void
	{
		$this->applyAliases($store);
		foreach ($this->rules as $key => $rule) {
			if ($rule->hasDefault() && str_contains($key, '*') && $rule->appliesTo($store)) {
				foreach ($this->expandPaths($store, $key) as $actualKey) {
					if (!$store->has($actualKey)) {
						$store->set($actualKey, $rule->defaultValue());
					}
				}
			} elseif ($rule->hasDefault() && !$store->has($key) && $rule->appliesTo($store)) {
				$store->set($key, $rule->defaultValue());
			}
		}
		foreach ($this->nested as $key => $entry) {
			if (!$store->has($key) || !is_array($store->get($key))) {
				continue;
			}
			$nested = Store::fromArray($store->get($key));
			$entry['schema']->applyDefaults($nested);
			if ($nested->all() !== $store->get($key)) {
				$store->set($key, $nested->all());
			}
		}
	}

	/**
	 * Coerces values for rules that explicitly allow it.
	 *
	 * @param Store $store Store to normalize.
	 * @return void
	 */
	public function normalize(Store $store) : void
	{
		foreach ($this->rules as $key => $rule) {
			if (!$rule->shouldCoerce() || !$rule->appliesTo($store)) {
				continue;
			}
			foreach ($this->expandPaths($store, $key) as $actualKey) {
			$value = $store->get($actualKey);
				$coerced = $rule->coerceValue($value);
				if ($coerced !== $value) {
					$store->set($actualKey, $coerced);
				}
			}
		}
		foreach ($this->nested as $key => $entry) {
			if (!$store->has($key) || !is_array($store->get($key))) {
				continue;
			}
			$nested = Store::fromArray($store->get($key));
			$entry['schema']->normalize($nested);
			if ($nested->all() !== $store->get($key)) {
				$store->set($key, $nested->all());
			}
		}
	}

	/**
	 * Normalizes and validates a Store in one call.
	 *
	 * @param Store $store Store to normalize.
	 * @return StoreValidationResult Validation result after normalization.
	 */
	public function normalizeAndValidate(Store $store) : StoreValidationResult
	{
		$this->applyDefaults($store);
		$this->normalize($store);

		return $this->validate($store);
	}

	/**
	 * Validates a Store against every rule.
	 *
	 * @param Store $store Store to validate.
	 * @return StoreValidationResult Validation result.
	 */
	public function validate(Store $store) : StoreValidationResult
	{
		$errors = [];
		$warnings = [];
		foreach ($this->rules as $key => $rule) {
			if (!$rule->appliesTo($store)) {
				continue;
			}
			$actualKeys = $this->expandPaths($store, $key);
			if ($actualKeys === []) {
				if ($rule->isRequiredFor($store)) {
					$errors[$key][] = 'is required';
				}

				continue;
			}
			foreach ($actualKeys as $actualKey) {
				$messages = $rule->validate($store->get($actualKey));
				if ($messages !== []) {
					$errors[$actualKey] = $messages;
				}
			}
			if ($rule->deprecationMessage() !== null) {
				$warnings[$key][] = $rule->deprecationMessage();
			}
		}
		foreach ($this->aliases as $legacy => $canonical) {
			if ($store->has($legacy)) {
				$warnings[$legacy][] = "is deprecated; use '{$canonical}' instead";
			}
		}
		foreach ($this->nested as $key => $entry) {
			if (!$store->has($key)) {
				if ($entry['required']) {
					$errors[$key][] = 'is required';
				}
				continue;
			}
			$value = $store->get($key);
			if (!is_array($value)) {
				$errors[$key][] = 'must be an object';
				continue;
			}
			$this->mergeNestedResult($errors, $warnings, $key, $entry['schema']->validate(Store::fromArray($value)));
		}
		foreach ($this->nestedLists as $key => $entry) {
			if (!$store->has($key)) {
				if ($entry['required']) {
					$errors[$key][] = 'is required';
				}
				continue;
			}
			$value = $store->get($key);
			if (!is_array($value) || !array_is_list($value)) {
				$errors[$key][] = 'must be a list';
				continue;
			}
			foreach ($value as $index => $item) {
				if (!is_array($item)) {
					$errors[$key . '[' . $index . ']'][] = 'must be an object';
					continue;
				}
				$this->mergeNestedResult($errors, $warnings, $key . '[' . $index . ']', $entry['schema']->validate(Store::fromArray($item)));
			}
		}
		if ($this->strict) {
			$this->validateUnknown($store->all(), '', $errors);
		}

		return new StoreValidationResult($errors, $warnings);
	}

	/**
	 * Validates a Store and throws immediately when it is invalid.
	 *
	 * @param Store $store Store to validate.
	 * @return void
	 * @throws StoreValidationException If one or more rules fail.
	 */
	public function validateOrFail(Store $store) : void
	{
		$this->validate($store)->throwIfInvalid();
	}

	private function applyAliases(Store $store) : void
	{
		foreach ($this->aliases as $legacy => $canonical) {
			if (!$store->has($canonical) && $store->has($legacy)) {
				$store->set($canonical, $store->get($legacy));
			}
		}
	}

	private function legacyKey(Store $store, string $canonical) : ?string
	{
		foreach ($this->aliases as $legacy => $target) {
			if ($target === $canonical && $store->has($legacy)) {
				return $legacy;
			}
		}

		return null;
	}

	/** @return list<string> Expanded paths for a wildcard rule. */
	private function expandPaths(Store $store, string $pattern) : array
	{
		if (!str_contains($pattern, '*')) {
			if ($store->has($pattern)) {
				return [$pattern];
			}
			$legacy = $this->legacyKey($store, $pattern);

			return $legacy === null ? [] : [$legacy];
		}

		$segments = explode('.', $pattern);
		$paths = [];
		$walk = function(mixed $value, int $index, string $prefix) use (&$walk, &$paths, $segments) : void {
			if ($index === count($segments)) {
				$paths[] = $prefix;

				return;
			}
			$segment = $segments[$index];
			if (!is_array($value)) {
				return;
			}
			if ($segment === '*') {
				foreach ($value as $key => $child) {
					$walk($child, $index + 1, $prefix === '' ? (string) $key : $prefix . '.' . $key);
				}

				return;
			}
			if (!array_key_exists($segment, $value)) {
				return;
			}
			$walk($value[$segment], $index + 1, $prefix === '' ? $segment : $prefix . '.' . $segment);
		};
		$walk($store->all(), 0, '');

		return $paths;
	}

	/** @param array<string,mixed> $values @param array<string,list<string>> $errors */
	private function validateUnknown(array $values, string $prefix, array &$errors) : void
	{
		foreach ($values as $key => $value) {
			$path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
			if (!$this->matchesKnownPrefix($path)) {
				$errors[$path][] = 'is not defined by the schema';

				continue;
			}
			if (is_array($value) && $this->hasKnownDescendant($path)) {
				$this->validateUnknown($value, $path, $errors);
			}
		}
	}

	private function matchesKnownPrefix(string $path) : bool
	{
		foreach ([...array_keys($this->rules), ...array_keys($this->nested), ...array_keys($this->nestedLists), ...array_keys($this->aliases), ...array_values($this->aliases)] as $pattern) {
			if ($this->patternIsPrefix($pattern, $path)) {
				return true;
			}
		}

		return false;
	}

	private function hasKnownDescendant(string $path) : bool
	{
		foreach ([...array_keys($this->rules), ...array_keys($this->nested), ...array_keys($this->nestedLists), ...array_keys($this->aliases), ...array_values($this->aliases)] as $pattern) {
			if ($this->patternIsPrefix($pattern, $path) && $pattern !== $path) {
				return true;
			}
		}

		return false;
	}

	private function patternIsPrefix(string $pattern, string $path) : bool
	{
		$patternParts = explode('.', $pattern);
		$pathParts = explode('.', $path);
		if (count($pathParts) > count($patternParts)) {
			return false;
		}
		foreach ($pathParts as $index => $part) {
			if ($patternParts[$index] !== '*' && $patternParts[$index] !== $part) {
				return false;
			}
		}

		return true;
	}

	/** @param array<string,mixed> $document */
	private function addNestedJsonSchema(array &$document, string $path, array $schema, bool $required) : void
	{
		$segments = explode('.', $path);
		$last = array_pop($segments);
		if ($last === '') {
			return;
		}
		$target = &$document;
		foreach ($segments as $segment) {
			$target['properties'][$segment] ??= ['type' => 'object', 'properties' => []];
			$target = &$target['properties'][$segment];
		}
		$target['properties'][$last] = $schema;
		if ($required) {
			$target['required'] ??= [];
			$target['required'][] = $last;
		}
		unset($target);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function toJsonSchemaArray() : array
	{
		$document = ['type' => 'object', 'properties' => []];
		if ($this->strict) {
			$document['additionalProperties'] = false;
		}
		foreach ($this->rules as $path => $rule) {
			$this->addNestedJsonSchema($document, $path, $rule->jsonSchema(), $rule->isRequired());
		}
		foreach ($this->nested as $path => $entry) {
			$this->addNestedJsonSchema($document, $path, $entry['schema']->toJsonSchemaArray(), $entry['required']);
		}
		foreach ($this->nestedLists as $path => $entry) {
			$this->addNestedJsonSchema($document, $path, ['type' => 'array', 'items' => $entry['schema']->toJsonSchemaArray()], $entry['required']);
		}

		return $document;
	}

	/** @param array<string,list<string>> $errors @param array<string,list<string>> $warnings */
	private function mergeNestedResult(array &$errors, array &$warnings, string $prefix, StoreValidationResult $result) : void
	{
		foreach ($result->errors() as $path => $messages) {
			$errors[$prefix . '.' . $path] = $messages;
		}
		foreach ($result->warnings() as $path => $messages) {
			$warnings[$prefix . '.' . $path] = $messages;
		}
	}
}
