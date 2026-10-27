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

use zwuiix\store\exception\StoreException;
use function implode;

/**
 * Raised when a StoreSchema validation fails.
 */
final class StoreValidationException extends StoreException
{
	/** @var array<string, list<string>> */
	private array $errors;

	/**
	 * @param array<string, list<string>> $errors Validation errors indexed by Store key.
	 */
	public function __construct(array $errors)
	{
		$this->errors = $errors;
		$messages = [];
		foreach ($errors as $key => $keyErrors) {
			$messages[] = $key . ': ' . implode(', ', $keyErrors);
		}

		parent::__construct('Store validation failed: ' . implode('; ', $messages));
	}

	/**
	 * Returns validation errors grouped by key.
	 *
	 * @return array<string, list<string>> Validation errors.
	 */
	public function errors() : array
	{
		return $this->errors;
	}
}
