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

namespace zwuiix\store\merge;

/**
 * Explicit answer returned by a per-path merge resolver.
 */
final class StoreMergeDecision
{
	private function __construct(private string $source, private mixed $value = null)
	{
	}

	/** @return self Select the local value. */
	public static function local() : self
	{
		return new self('local');
	}

	/** @return self Select the remote value. */
	public static function remote() : self
	{
		return new self('remote');
	}

	/** @param mixed $value Custom value to write. @return self */
	public static function value(mixed $value) : self
	{
		return new self('value', $value);
	}

	/** @return string Decision source. */
	public function source() : string
	{
		return $this->source;
	}

	/** @return mixed Custom value, when source is value. */
	public function customValue() : mixed
	{
		return $this->value;
	}
}
