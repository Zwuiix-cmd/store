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

namespace zwuiix\store\mapping;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use ReflectionClass;
use ReflectionEnum;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use zwuiix\store\exception\StoreException;
use zwuiix\store\security\StoreSecret;
use zwuiix\store\Store;
use zwuiix\store\validation\StoreRule;
use zwuiix\store\validation\StoreSchema;
use zwuiix\store\validation\StoreValueType;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_pop;
use function array_values;
use function class_exists;
use function compact;
use function count;
use function enum_exists;
use function explode;
use function get_debug_type;
use function implode;
use function is_a;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_object;
use function is_string;
use function strtolower;

/**
 * Hydrates typed PHP objects from Store values and serializes them back.
 *
 * Mapping uses constructor parameter and property names by default. StoreField
 * can override a path or describe the class used by list items. The mapper
 * deliberately uses reflection instead of a second configuration format.
 */
final class StoreMapper
{
	/** @var array<string, array{reflection: ReflectionClass, constructor: \ReflectionMethod|null, parameters: list<array{reflection: ReflectionParameter, field: StoreField|null}>, properties: list<array{reflection: ReflectionProperty, field: StoreField|null}>}> */
	private static array $metadata = [];

	/** @var array<string, StoreSchema> */
	private static array $schemas = [];

	private function __construct()
	{
	}

	/**
	 * Builds a schema from a typed DTO constructor and its properties.
	 *
	 * @param class-string $class DTO class to inspect.
	 * @return StoreSchema Generated schema.
	 */
	public static function schemaFor(string $class) : StoreSchema
	{
		if (isset(self::$schemas[$class])) {
			return self::$schemas[$class];
		}

		$schema = StoreSchema::create();
		self::$schemas[$class] = $schema;
		$metadata = self::metadata($class);
		foreach ($metadata['properties'] as $entry) {
			$property = $entry['reflection'];
			$type = $property->getType();
			if ($type === null) {
				continue;
			}
			$path = self::path($property->getName(), $entry['field']);
			self::addSchemaEntry($schema, $path, $type, $entry['field'], !$property->hasDefaultValue(), $property->hasDefaultValue(), $property->hasDefaultValue() ? $property->getDefaultValue() : null);
		}

		return $schema;
	}

	/**
	 * Reads an object from a Store path.
	 *
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T
	 */
	public static function get(Store $store, string $path, string $class, ?StoreSchema $schema = null) : object
	{
		$value = $path === '' ? $store->all() : $store->get($path);
		if (!is_array($value)) {
			throw new StoreException("Mapped Store path '{$path}' must contain an object map.");
		}

		return self::fromArray($value, $class, $schema);
	}

	/**
	 * Hydrates an object from an array.
	 *
	 * @template T of object
	 * @param array<string,mixed> $values
	 * @param class-string<T>     $class
	 * @return T
	 */
	public static function fromArray(array $values, string $class, ?StoreSchema $schema = null) : object
	{
		if (!class_exists($class)) {
			throw new StoreException("Mapped class '{$class}' does not exist.");
		}
		$metadata = self::metadata($class);
		$constructor = $metadata['constructor'];
		if ($constructor === null) {
			$object = $metadata['reflection']->newInstanceWithoutConstructor();
		} else {
			$args = [];
			foreach ($metadata['parameters'] as $entry) {
				$parameter = $entry['reflection'];
				$field = $entry['field'];
				$path = self::path($parameter->getName(), $field);
				$found = false;
				$value = self::read($values, $path, $found);
				if (!$found) {
					if ($parameter->isDefaultValueAvailable()) {
						$args[] = $parameter->getDefaultValue();
						continue;
					}
					if ($parameter->allowsNull()) {
						$args[] = null;
						continue;
					}
					throw new StoreException("Mapped Store value '{$path}' is required for {$class}::\${$parameter->getName()}.");
				}
				$args[] = self::convert($value, $parameter->getType(), $field?->itemClass, $path);
			}
			$object = $metadata['reflection']->newInstanceArgs($args);
		}

		foreach ($metadata['properties'] as $entry) {
			$property = $entry['reflection'];
			$field = $entry['field'];
			if ($property->isStatic() || ($property->isReadOnly() && $property->isInitialized($object))) {
				continue;
			}
			$path = self::path($property->getName(), $field);
			$found = false;
			$value = self::read($values, $path, $found);
			if (!$found) {
				continue;
			}
			$property->setValue($object, self::convert($value, $property->getType(), $field?->itemClass, $path));
		}

		if ($schema !== null) {
			$schema->validate(Store::fromArray(self::objectValues($object)))->throwIfInvalid();
		}

		return $object;
	}

	/**
	 * Writes a mapped object below a Store path.
	 *
	 * @return void
	 */
	public static function set(Store $store, string $path, object $object, ?StoreSchema $schema = null) : void
	{
		$values = self::toArray($object, $schema);
		if ($path === '') {
			$store->setAll($values);

			return;
		}
		$store->set($path, $values);
	}

	/**
	 * Serializes an object using its constructor/property mapping.
	 *
	 * @return array<string,mixed>
	 */
	public static function toArray(object $object, ?StoreSchema $schema = null) : array
	{
		if ($schema !== null) {
			$schema->validate(Store::fromArray(self::objectValues($object)))->throwIfInvalid();
		}

		return self::objectValues($object);
	}

	/** @param object $object @return array<string,mixed> */
	private static function objectValues(object $object) : array
	{
		$metadata = self::metadata($object::class);
		$values = [];
		foreach ($metadata['properties'] as $entry) {
			$property = $entry['reflection'];
			$field = $entry['field'];
			if ($property->isStatic() || !$property->isInitialized($object)) {
				continue;
			}
			$propertyValue = $property->getValue($object);
			self::write($values, self::path($property->getName(), $field), self::exportValue($propertyValue, $field?->itemClass));
		}

		return $values;
	}

	/**
	 * @return array{reflection: ReflectionClass, constructor: \ReflectionMethod|null, parameters: list<array{reflection: ReflectionParameter, field: StoreField|null}>, properties: list<array{reflection: ReflectionProperty, field: StoreField|null}>}
	 */
	private static function metadata(string $class) : array
	{
		return self::$metadata[$class] ??= (static function() use ($class) : array {
			if (!class_exists($class)) {
				throw new StoreException("Mapped class '{$class}' does not exist.");
			}
			$reflection = new ReflectionClass($class);
			$constructor = $reflection->getConstructor();
			$parameters = [];
			foreach ($constructor?->getParameters() ?? [] as $parameter) {
				$attributes = $parameter->getAttributes(StoreField::class);
				$parameters[] = ['reflection' => $parameter, 'field' => $attributes === [] ? null : $attributes[0]->newInstance()];
			}
			$properties = [];
			foreach ($reflection->getProperties() as $property) {
				$attributes = $property->getAttributes(StoreField::class);
				$field = $attributes === [] ? null : $attributes[0]->newInstance();
				if ($field === null && $constructor !== null) {
					foreach ($parameters as $parameter) {
						if ($parameter['reflection']->getName() !== $property->getName()) {
							continue;
						}
						$field = $parameter['field'];
						break;
					}
				}
				$properties[] = ['reflection' => $property, 'field' => $field];
			}

			return compact('reflection', 'constructor', 'parameters', 'properties');
		})();
	}

	private static function path(string $name, ?StoreField $field) : string
	{
		return $field === null || $field->path === null ? $name : $field->path;
	}

	private static function addSchemaEntry(StoreSchema $schema, string $path, ReflectionType $type, ?StoreField $field, bool $required, bool $hasDefault, mixed $default) : void
	{
		$nullable = $type->allowsNull();
		$ruleType = $type;
		if ($type instanceof ReflectionUnionType) {
			foreach ($type->getTypes() as $inner) {
				if ($inner instanceof ReflectionNamedType && $inner->getName() !== 'null') {
					$type = $inner;
					break;
				}
			}
		}
		if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && !enum_exists($type->getName()) && !is_a($type->getName(), DateTimeInterface::class, true)) {
			$schema->nested($path, self::schemaFor($type->getName()), $required);

			return;
		}
		if ($field?->itemClass !== null && $type instanceof ReflectionNamedType && $type->getName() === 'array' && class_exists($field->itemClass)) {
			$schema->listOf($path, self::schemaFor($field->itemClass), $required);

			return;
		}
		$rule = self::ruleForType($ruleType, $field);
		if (!$required) {
			$rule = $rule->optional();
		}
		if ($nullable) {
			$rule = $rule->nullable();
		}
		if ($hasDefault && ($default !== null || $nullable)) {
			$rule = $rule->default($default);
		}
		$schema->rule($path, $rule);
	}

	private static function ruleForType(ReflectionType $type, ?StoreField $field) : StoreRule
	{
		if ($type instanceof ReflectionUnionType) {
			$types = array_values(array_filter($type->getTypes(), static fn(ReflectionType $inner) : bool => !($inner instanceof ReflectionNamedType && $inner->getName() === 'null')));
			if (count($types) > 1) {
				return StoreRule::required(StoreValueType::Any);
			}
			foreach ($type->getTypes() as $inner) {
				if ($inner instanceof ReflectionNamedType && $inner->getName() !== 'null') {
					return self::ruleForType($inner, $field)->nullable();
				}
			}
		}
		if (!$type instanceof ReflectionNamedType || $type->getName() === 'mixed') {
			return StoreRule::required();
		}
		if ($type->isBuiltin()) {
			return StoreRule::required(match ($type->getName()) {
				'bool' => StoreValueType::Boolean,
				'int' => StoreValueType::Integer,
				'float' => StoreValueType::Float,
				'array' => $field?->itemClass === null ? StoreValueType::ArrayValue : StoreValueType::List,
				default => StoreValueType::String,
			});
		}

		if (enum_exists($type->getName())) {
			$enum = new ReflectionEnum($type->getName());
			$backingType = $enum->getBackingType();

			return StoreRule::required($backingType?->getName() === 'int' ? StoreValueType::Integer : StoreValueType::String);
		}

		return StoreRule::required(is_a($type->getName(), DateTimeInterface::class, true) ? StoreValueType::String : StoreValueType::ArrayValue);
	}

	private static function read(array $values, string $path, bool &$found) : mixed
	{
		$value = $values;
		foreach (explode('.', $path) as $segment) {
			if (!is_array($value) || !array_key_exists($segment, $value)) {
				$found = false;

				return null;
			}
			$value = $value[$segment];
		}
		$found = true;

		return $value;
	}

	private static function write(array &$values, string $path, mixed $value) : void
	{
		$segments = explode('.', $path);
		$last = array_pop($segments);
		$target = &$values;
		foreach ($segments as $segment) {
			$target[$segment] ??= [];
			$target = &$target[$segment];
		}
		$target[$last] = $value;
	}

	private static function convert(mixed $value, ?ReflectionType $type, ?string $itemClass, string $path) : mixed
	{
		if ($value === null) {
			if ($type !== null && !$type->allowsNull()) {
				throw new StoreException("Mapped Store value '{$path}' cannot be null.");
			}

			return null;
		}
		if ($itemClass !== null && is_array($value)) {
			return array_map(static function(mixed $item) use ($itemClass, $path) : object {
				if (!is_array($item)) {
					throw new StoreException("Mapped Store list '{$path}' must contain object maps.");
				}

				return self::fromArray($item, $itemClass);
			}, $value);
		}
		if ($type instanceof ReflectionUnionType) {
			$errors = [];
			foreach ($type->getTypes() as $inner) {
				if (!$inner instanceof ReflectionNamedType || !self::matchesRuntimeType($value, $inner)) {
					continue;
				}
				return self::convert($value, $inner, $itemClass, $path);
			}
			foreach ($type->getTypes() as $inner) {
				if ($inner instanceof ReflectionNamedType && $inner->getName() === 'null') {
					continue;
				}
				try {
					return self::convert($value, $inner, $itemClass, $path);
				} catch (StoreException $exception) {
					$errors[] = $exception->getMessage();
				}
			}
			throw new StoreException("Mapped Store value '{$path}' does not match its union type: " . implode('; ', $errors));
		}
		if ($type instanceof ReflectionIntersectionType) {
			throw new StoreException("Mapped Store value '{$path}' uses an unsupported intersection type.");
		}
		if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
			if ($type instanceof ReflectionNamedType) {
				return match ($type->getName()) {
					'array' => is_array($value) ? $value : throw new StoreException("Mapped Store value '{$path}' must be an array."),
					'bool' => self::boolean($value, $path),
					'int' => self::integer($value, $path),
					'float' => self::floating($value, $path),
					'string' => is_string($value) ? $value : (string) $value,
					default => $value,
				};
			}

			return $value;
		}
		$class = $type->getName();
		if (enum_exists($class)) {
			if (is_a($class, BackedEnum::class, true)) {
				return $class::from($value);
			}
			throw new StoreException("Mapped enum '{$class}' must be backed.");
		}
		if (is_a($class, DateTimeInterface::class, true) && is_string($value)) {
			return $class === DateTimeInterface::class || $class === DateTimeImmutable::class ? new DateTimeImmutable($value) : new $class($value);
		}
		if (is_array($value)) {
			return self::fromArray($value, $class);
		}
		if ($value instanceof $class) {
			return $value;
		}

		throw new StoreException("Mapped Store value '{$path}' cannot be converted to {$class}; got " . get_debug_type($value) . '.');
	}

	private static function matchesRuntimeType(mixed $value, ReflectionNamedType $type) : bool
	{
		if ($type->isBuiltin()) {
			return match ($type->getName()) {
				'bool' => is_bool($value),
				'int' => is_int($value),
				'float' => is_float($value),
				'string' => is_string($value),
				'array' => is_array($value),
				'mixed' => true,
				default => false,
			};
		}
		if (enum_exists($type->getName())) {
			$class = $type->getName();

			return $value instanceof $class;
		}
		$class = $type->getName();

		return is_object($value) && $value instanceof $class;
	}

	private static function exportValue(mixed $value, ?string $itemClass = null) : mixed
	{
		if ($value instanceof StoreSecret) {
			return $value;
		}
		if ($value instanceof BackedEnum) {
			return $value->value;
		}
		if ($value instanceof DateTimeInterface) {
			return $value->format(DateTimeInterface::ATOM);
		}
		if (is_object($value)) {
			return self::objectValues($value);
		}
		if (is_array($value)) {
			return array_map(static fn(mixed $item) : mixed => self::exportValue($item, $itemClass), $value);
		}

		return $value;
	}

	private static function boolean(mixed $value, string $path) : bool
	{
		if (is_bool($value)) {
			return $value;
		}
		if (is_string($value)) {
			return match (strtolower($value)) {
				'true', 'yes', 'on', '1' => true,
				'false', 'no', 'off', '0' => false,
				default => throw new StoreException("Mapped Store value '{$path}' must be boolean."),
			};
		}
		throw new StoreException("Mapped Store value '{$path}' must be boolean.");
	}

	private static function integer(mixed $value, string $path) : int
	{
		if (is_int($value)) {
			return $value;
		}
		if (is_string($value) && is_numeric($value) && (string) (int) $value === $value) {
			return (int) $value;
		}
		throw new StoreException("Mapped Store value '{$path}' must be integer.");
	}

	private static function floating(mixed $value, string $path) : float
	{
		if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
			return (float) $value;
		}
		throw new StoreException("Mapped Store value '{$path}' must be float.");
	}
}
