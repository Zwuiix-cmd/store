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

use InvalidArgumentException;
use zwuiix\store\security\StoreSecret;
use zwuiix\store\Store;

use function count;
use function filter_var;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_numeric;
use function is_scalar;
use function is_string;
use function json_decode;

use function preg_match;
use function strlen;
use function strtolower;
use const FILTER_VALIDATE_EMAIL;
use const FILTER_VALIDATE_URL;

/**
 * Fluent validation rule for one Store path.
 */
final class StoreRule
{
	private bool $required;

	private StoreValueType $type;

	private bool $nullable = false;

	private bool $hasDefault = false;

	private mixed $default = null;

	private ?string $description = null;

	private mixed $example = null;

	private bool $hasExample = false;

	private mixed $minimum = null;

	private mixed $maximum = null;

	/** @var list<mixed>|null */
	private ?array $allowed = null;

	private ?string $pattern = null;

	private ?StoreValueType $itemType = null;

	private bool $nonEmpty = false;

	private bool $coercion = false;

	private bool $email = false;

	private bool $url = false;

	/** @var array{path: string, value: mixed, inverse: bool}|null */
	private ?array $condition = null;

	/** @var array{path: string, value: mixed}|null */
	private ?array $requiredCondition = null;

	private ?string $deprecationMessage = null;

	/** @var callable(mixed): (bool|string|null)|null */
	private $customValidator = null;

	private string $customMessage = 'custom validation failed';

	private function __construct(
		bool           $required,
		StoreValueType $type,
	) {
		$this->required = $required;
		$this->type = $type;
	}

	/**
	 * Creates a required rule.
	 *
	 * @param StoreValueType $type Expected value type.
	 * @return self           New required rule.
	 */
	public static function required(StoreValueType $type = StoreValueType::Any) : self
	{
		return new self(true, $type);
	}

	/**
	 * Creates an optional rule.
	 *
	 * @param StoreValueType $type Expected value type.
	 * @return self           New optional rule.
	 */
	public static function optional(StoreValueType $type = StoreValueType::Any) : self
	{
		return new self(false, $type);
	}

	/**
	 * Allows null for an existing value.
	 *
	 * @param bool $nullable Whether null should be accepted.
	 * @return self This rule.
	 */
	public function nullable(bool $nullable = true) : self
	{
		$this->nullable = $nullable;

		return $this;
	}

	/**
	 * Supplies a default value for a missing key.
	 *
	 * StoreSchema::applyDefaults() writes this value before validation.
	 *
	 * @param mixed $default Default value.
	 * @return self  This rule.
	 */
	public function default(mixed $default) : self
	{
		if ($default !== null && !$this->type->matches($default)) {
			throw new InvalidArgumentException("The default value must match the {$this->type->value} type.");
		}
		if ($default === null && !$this->nullable) {
			throw new InvalidArgumentException('A null default requires a nullable Store rule.');
		}
		$this->default = $default;
		$this->hasDefault = true;

		return $this;
	}

	/**
	 * Adds human-readable documentation to this rule.
	 *
	 * @param string $description Rule explanation.
	 * @return self              This rule.
	 */
	public function description(string $description) : self
	{
		$this->description = $description;

		return $this;
	}

	/**
	 * Adds an example value used by generated documentation.
	 *
	 * @param mixed $example Example configuration value.
	 * @return self        This rule.
	 */
	public function example(mixed $example) : self
	{
		$this->example = $example;
		$this->hasExample = true;

		return $this;
	}

	/**
	 * Adds inclusive minimum and maximum constraints.
	 *
	 * @param mixed $minimum Minimum number, string length or array size.
	 * @param mixed $maximum Maximum number, string length or array size.
	 * @return self  This rule.
	 */
	public function between(mixed $minimum, mixed $maximum) : self
	{
		$this->minimum = $minimum;
		$this->maximum = $maximum;

		return $this;
	}

	/**
	 * Adds an inclusive minimum constraint.
	 *
	 * @param mixed $minimum Minimum number, string length or array size.
	 * @return self  This rule.
	 */
	public function min(mixed $minimum) : self
	{
		$this->minimum = $minimum;

		return $this;
	}

	/**
	 * Adds an inclusive maximum constraint.
	 *
	 * @param mixed $maximum Maximum number, string length or array size.
	 * @return self  This rule.
	 */
	public function max(mixed $maximum) : self
	{
		$this->maximum = $maximum;

		return $this;
	}

	/**
	 * Restricts the value to a strict allow-list.
	 *
	 * @param list<mixed> $allowed Accepted values.
	 * @return self        This rule.
	 */
	public function oneOf(array $allowed) : self
	{
		$this->allowed = $allowed;

		return $this;
	}

	/**
	 * Restricts string values to a regular expression.
	 *
	 * @param string $pattern PCRE pattern.
	 * @return self   This rule.
	 */
	public function pattern(string $pattern) : self
	{
		$this->pattern = $pattern;

		return $this;
	}

	/**
	 * Requires every item of an array to use the supplied type.
	 *
	 * @param StoreValueType $type Expected item type.
	 * @return self           This rule.
	 */
	public function items(StoreValueType $type) : self
	{
		$this->itemType = $type;

		return $this;
	}

	/**
	 * Rejects empty strings, arrays and lists.
	 *
	 * @param bool $enabled Whether the constraint is enabled.
	 * @return self This rule.
	 */
	public function nonEmpty(bool $enabled = true) : self
	{
		$this->nonEmpty = $enabled;

		return $this;
	}

	/**
	 * Enables safe scalar coercion before validation.
	 *
	 * Only unambiguous values are converted: numeric strings, boolean words and
	 * JSON arrays. Invalid values remain unchanged and are reported by validate().
	 *
	 * @param bool $enabled Whether coercion is enabled.
	 * @return self This rule.
	 */
	public function coerce(bool $enabled = true) : self
	{
		$this->coercion = $enabled;

		return $this;
	}

	/**
	 * Requires a valid email address.
	 *
	 * @param bool $enabled Whether the constraint is enabled.
	 * @return self This rule.
	 */
	public function email(bool $enabled = true) : self
	{
		$this->email = $enabled;

		return $this;
	}

	/**
	 * Requires a valid URL.
	 *
	 * @param bool $enabled Whether the constraint is enabled.
	 * @return self This rule.
	 */
	public function url(bool $enabled = true) : self
	{
		$this->url = $enabled;

		return $this;
	}

	/**
	 * Applies this rule only when another Store path has the expected value.
	 *
	 * @param string $path    Dot-separated condition path.
	 * @param mixed  $value   Expected value.
	 * @param bool   $inverse Whether the condition should be inverted.
	 * @return self             This rule.
	 */
	public function when(string $path, mixed $value = true, bool $inverse = false) : self
	{
		$this->condition = ['path' => $path, 'value' => $value, 'inverse' => $inverse];

		return $this;
	}

	/**
	 * Applies this rule unless another Store path has the expected value.
	 *
	 * @param string $path  Dot-separated condition path.
	 * @param mixed  $value Expected value.
	 * @return self         This rule.
	 */
	public function unless(string $path, mixed $value = true) : self
	{
		return $this->when($path, $value, true);
	}

	/**
	 * Makes an optional rule required when another path has the expected value.
	 *
	 * @param string $path  Dot-separated condition path.
	 * @param mixed  $value Expected value.
	 * @return self         This rule.
	 */
	public function requiredWhen(string $path, mixed $value = true) : self
	{
		$this->requiredCondition = ['path' => $path, 'value' => $value];

		return $this;
	}

	/**
	 * Marks this rule as deprecated while keeping it valid.
	 *
	 * @param string $message Warning returned by StoreValidationResult.
	 * @return self         This rule.
	 */
	public function deprecated(string $message = 'key is deprecated') : self
	{
		$this->deprecationMessage = $message;

		return $this;
	}

	/**
	 * Adds a custom validator.
	 *
	 * The callback returns true or null for success, false for the supplied
	 * message, or a string containing a specific validation message.
	 *
	 * @param callable(mixed): (bool|string|null) $validator Validation callback.
	 * @param string                              $message   Message for false.
	 * @return self                                This rule.
	 */
	public function custom(callable $validator, string $message = 'custom validation failed') : self
	{
		$this->customValidator = $validator;
		$this->customMessage = $message;

		return $this;
	}

	/** @return bool */
	public function isRequired() : bool
	{
		return $this->required;
	}

	/**
	 * Returns whether this rule requires a value in the current Store context.
	 *
	 * @param Store $store Store being validated.
	 * @return bool        Whether the key is required.
	 */
	public function isRequiredFor(Store $store) : bool
	{
		if ($this->requiredCondition === null) {
			return $this->required;
		}

		return $this->matchesCondition($store, $this->requiredCondition['path'], $this->requiredCondition['value']);
	}

	/**
	 * Returns whether the rule should be evaluated in the current context.
	 *
	 * @param Store $store Store being validated.
	 * @return bool        Whether validation should continue.
	 */
	public function appliesTo(Store $store) : bool
	{
		if ($this->condition === null) {
			return true;
		}

		$matches = $this->matchesCondition($store, $this->condition['path'], $this->condition['value']);

		return $this->condition['inverse'] ? !$matches : $matches;
	}

	/** @return string|null Deprecation warning, if configured. */
	public function deprecationMessage() : ?string
	{
		return $this->deprecationMessage;
	}

	/**
	 * Returns a serializable description for documentation or JSON Schema tooling.
	 *
	 * @return array<string,mixed> Rule metadata.
	 */
	public function describe() : array
	{
		$data = [
			'required' => $this->required,
			'type' => $this->type->value,
			'nullable' => $this->nullable,
			'coerce' => $this->coercion,
		];
		if ($this->hasDefault) {
			$data['default'] = $this->default;
		}
		if ($this->description !== null) {
			$data['description'] = $this->description;
		}
		if ($this->hasExample) {
			$data['example'] = $this->example;
		}
		if ($this->minimum !== null) {
			$data['minimum'] = $this->minimum;
		}
		if ($this->maximum !== null) {
			$data['maximum'] = $this->maximum;
		}
		if ($this->allowed !== null) {
			$data['allowed'] = $this->allowed;
		}
		if ($this->pattern !== null) {
			$data['pattern'] = $this->pattern;
		}
		if ($this->itemType !== null) {
			$data['items'] = $this->itemType->value;
		}
		if ($this->nonEmpty) {
			$data['non_empty'] = true;
		}
		if ($this->email) {
			$data['email'] = true;
		}
		if ($this->url) {
			$data['url'] = true;
		}
		if ($this->condition !== null) {
			$data['when'] = $this->condition;
		}
		if ($this->requiredCondition !== null) {
			$data['required_when'] = $this->requiredCondition;
		}
		if ($this->deprecationMessage !== null) {
			$data['deprecated'] = $this->deprecationMessage;
		}

		return $data;
	}

	/**
	 * Returns the static part of this rule in JSON Schema form.
	 *
	 * Conditional requirements are kept in the Store description because JSON
	 * Schema cannot represent every Store runtime condition directly.
	 *
	 * @return array<string,mixed> JSON Schema fragment.
	 */
	public function jsonSchema() : array
	{
		$data = [];
		$type = match ($this->type) {
			StoreValueType::String, StoreValueType::Secret => 'string',
			StoreValueType::Integer => 'integer',
			StoreValueType::Float => 'number',
			StoreValueType::Boolean => 'boolean',
			StoreValueType::ArrayValue => 'object',
			StoreValueType::List => 'array',
			StoreValueType::Any => null,
		};
		if ($type !== null) {
			$data['type'] = $this->nullable ? [$type, 'null'] : $type;
		}
		if ($this->hasDefault) {
			$data['default'] = $this->default;
		}
		if ($this->allowed !== null) {
			$data['enum'] = $this->allowed;
		}
		if ($this->pattern !== null) {
			$data['pattern'] = $this->pattern;
		}
		if ($this->email) {
			$data['format'] = 'email';
		}
		if ($this->url) {
			$data['format'] = 'uri';
		}
		if ($this->description !== null) {
			$data['description'] = $this->description;
		}
		if ($this->hasExample) {
			$data['examples'] = [$this->example];
		}
		if ($this->type === StoreValueType::List && $this->itemType !== null) {
			$data['items'] = StoreRule::optional($this->itemType)->jsonSchema();
		}
		if ($this->minimum !== null) {
			$data[$this->type === StoreValueType::String ? 'minLength' : ($this->type === StoreValueType::List || $this->type === StoreValueType::ArrayValue ? 'minItems' : 'minimum')] = $this->minimum;
		}
		if ($this->maximum !== null) {
			$data[$this->type === StoreValueType::String ? 'maxLength' : ($this->type === StoreValueType::List || $this->type === StoreValueType::ArrayValue ? 'maxItems' : 'maximum')] = $this->maximum;
		}

		return $data;
	}

	/** @return StoreValueType */
	public function type() : StoreValueType
	{
		return $this->type;
	}

	/** @return bool */
	public function allowsNull() : bool
	{
		return $this->nullable;
	}

	/** @return bool */
	public function hasDefault() : bool
	{
		return $this->hasDefault;
	}

	/** @return bool Whether safe coercion is enabled. */
	public function shouldCoerce() : bool
	{
		return $this->coercion;
	}

	/**
	 * Converts an unambiguous value to the configured type.
	 *
	 * @param mixed $value Value to convert.
	 * @return mixed Converted value, or the original value when unsafe.
	 */
	public function coerceValue(mixed $value) : mixed
	{
		if (!$this->coercion || $value === null) {
			return $value;
		}

		return match ($this->type) {
			StoreValueType::String => is_scalar($value) ? (string) $value : $value,
			StoreValueType::Integer => is_string($value) && preg_match('/^[+-]?\d+$/', $value) === 1 ? (int) $value : $value,
			StoreValueType::Float => (is_string($value) && is_numeric($value)) ? (float) $value : $value,
			StoreValueType::Boolean => $this->coerceBoolean($value),
			StoreValueType::ArrayValue, StoreValueType::List => $this->coerceArray($value),
			StoreValueType::Secret => is_string($value) ? StoreSecret::from($value) : $value,
			StoreValueType::Any => $value,
		};
	}

	/** @return mixed */
	public function defaultValue() : mixed
	{
		return $this->default;
	}

	/**
	 * Validates a present value.
	 *
	 * @param mixed $value Value to inspect.
	 * @return list<string> Validation messages.
	 */
	public function validate(mixed $value) : array
	{
		if ($value === null) {
			return $this->nullable ? [] : ['must not be null'];
		}
		if (!$this->type->matches($value)) {
			return ['must be a ' . $this->type->value];
		}

		$errors = [];
		$size = $value instanceof StoreSecret ? $value->length() : (is_string($value) ? strlen($value) : (is_array($value) ? count($value) : $value));
		if ($this->minimum !== null && $size < $this->minimum) {
			$errors[] = 'must be at least ' . $this->minimum;
		}
		if ($this->maximum !== null && $size > $this->maximum) {
			$errors[] = 'must be at most ' . $this->maximum;
		}
		if ($this->allowed !== null && !in_array($value, $this->allowed, true)) {
			$errors[] = 'contains a value outside the allow-list';
		}
		if ($this->pattern !== null && (!is_string($value) || preg_match($this->pattern, $value) !== 1)) {
			$errors[] = 'does not match the required pattern';
		}
		if ($this->nonEmpty && (($value === '') || (is_array($value) && $value === []))) {
			$errors[] = 'must not be empty';
		}
		if ($this->email && (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false)) {
			$errors[] = 'must be a valid email address';
		}
		if ($this->url && (!is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false)) {
			$errors[] = 'must be a valid URL';
		}

		if ($this->itemType !== null) {
			if (!is_array($value)) {
				$errors[] = 'must be an array to validate its items';
			} else {
				foreach ($value as $index => $item) {
					if (!$this->itemType->matches($item)) {
						$errors[] = "item '{$index}' must be a {$this->itemType->value}";
					}
				}
			}
		}

		if ($this->customValidator !== null) {
			$result = ($this->customValidator)($value);
			if ($result === false) {
				$errors[] = $this->customMessage;
			} elseif (is_string($result)) {
				$errors[] = $result;
			}
		}

		return $errors;
	}

	private function coerceBoolean(mixed $value) : mixed
	{
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value) && ($value === 0 || $value === 1)) {
			return $value === 1;
		}

		if (is_string($value)) {
			return match (strtolower($value)) {
				'true', 'yes', 'on', '1' => true,
				'false', 'no', 'off', '0' => false,
				default => $value,
			};
		}

		return $value;
	}

	private function coerceArray(mixed $value) : mixed
	{
		if (is_array($value)) {
			return $value;
		}
		if (!is_string($value) || ($value === '' || $value[0] !== '[' && $value[0] !== '{')) {
			return $value;
		}

		$decoded = json_decode($value, true);

		return is_array($decoded) ? $decoded : $value;
	}

	private function matchesCondition(Store $store, string $path, mixed $expected) : bool
	{
		return $store->has($path) && $store->get($path) === $expected;
	}
}
