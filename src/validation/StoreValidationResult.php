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

use function count;

/**
 * Immutable result returned by StoreSchema validation.
 */
final class StoreValidationResult
{
	/** @var array<string, list<string>> */
	private array $errors;

	/** @var array<string, list<string>> */
	private array $warnings;

	/**
	 * @param array<string, list<string>> $errors   Validation errors indexed by Store key.
	 * @param array<string, list<string>> $warnings Non-fatal warnings indexed by Store key.
	 */
	public function __construct(array $errors = [], array $warnings = [])
	{
		$this->errors = $errors;
		$this->warnings = $warnings;
	}

	/**
	 * Returns whether every schema rule passed.
	 *
	 * @return bool True when no validation error exists.
	 */
	public function isValid() : bool
	{
		return $this->errors === [];
	}

	/**
	 * Returns all validation errors.
	 *
	 * @return array<string, list<string>> Errors indexed by Store key.
	 */
	public function errors() : array
	{
		return $this->errors;
	}

	/**
	 * Returns the number of failed rules.
	 *
	 * @return int Number of keys with validation errors.
	 */
	public function count() : int
	{
		return count($this->errors);
	}

	/**
	 * Returns whether this result contains any non-fatal warning.
	 *
	 * @return bool True when one or more warnings were collected.
	 */
	public function hasWarnings() : bool
	{
		return $this->warnings !== [];
	}

	/**
	 * Returns all warnings.
	 *
	 * @return array<string, list<string>> Warnings indexed by Store key.
	 */
	public function warnings() : array
	{
		return $this->warnings;
	}

	/**
	 * Throws when this result contains errors.
	 *
	 * @return void
	 * @throws StoreValidationException If one or more rules failed.
	 */
	public function throwIfInvalid() : void
	{
		if (!$this->isValid()) {
			throw new StoreValidationException($this->errors);
		}
	}
}
