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

namespace zwuiix\store\runtime;

use pocketmine\Server;

use function sprintf;

/**
 * Small optional logger backed by PocketMine's server logger.
 *
 * Store uses debug logging for normal lifecycle details and reserves warning
 * and error messages for failed operations. No logger is required in tests or
 * command-line tools running outside a PocketMine server.
 */
final class StoreLogger
{
	/**
	 * @param \Logger|null $logger       PocketMine logger, or null for silence.
	 * @param string       $channel      Prefix placed before every message.
	 * @param bool         $debugEnabled Whether debug messages are emitted.
	 */
	public function __construct(
		private ?\Logger $logger,
		private string   $channel = 'Store',
		private bool     $debugEnabled = false,
	) {
	}

	/**
	 * Creates a logger using the current PocketMine server logger.
	 *
	 * @param string $channel      Message prefix.
	 * @param bool   $debugEnabled Whether lifecycle debug messages are enabled.
	 * @return self   Server-backed logger, or a silent logger outside the server.
	 */
	public static function server(string $channel = 'Store', bool $debugEnabled = false) : self
	{
		try {
			return new self(Server::getInstance()->getLogger(), $channel, $debugEnabled);
		} catch (\Throwable) {
			return new self(null, $channel, $debugEnabled);
		}
	}

	/** @return bool Whether debug messages are enabled. */
	public function isDebugEnabled() : bool
	{
		return $this->debugEnabled;
	}

	/**
	 * Enables or disables lifecycle debug messages.
	 *
	 * @param bool $enabled Whether debug messages should be emitted.
	 * @return self This logger.
	 */
	public function setDebug(bool $enabled) : self
	{
		$this->debugEnabled = $enabled;

		return $this;
	}

	/**
	 * Emits a debug message when explicitly enabled.
	 *
	 * @param string $message Message to write.
	 * @return void
	 */
	public function debug(string $message) : void
	{
		if ($this->debugEnabled) {
			$this->write('debug', $message);
		}
	}

	/**
	 * Emits an informational message.
	 *
	 * @param string $message Message to write.
	 * @return void
	 */
	public function info(string $message) : void
	{
		$this->write('info', $message);
	}

	/**
	 * Emits a warning message.
	 *
	 * @param string $message Message to write.
	 * @return void
	 */
	public function warning(string $message) : void
	{
		$this->write('warning', $message);
	}

	/**
	 * Emits an error and optional exception trace.
	 *
	 * @param string          $message   Message to write.
	 * @param \Throwable|null $exception Related exception.
	 * @return void
	 */
	public function error(string $message, ?\Throwable $exception = null) : void
	{
		if ($this->logger === null) {
			return;
		}

		$this->logger->error($this->prefix($message));
		if ($exception !== null) {
			$this->logger->logException($exception);
		}
	}

	private function write(string $level, string $message) : void
	{
		if ($this->logger === null) {
			return;
		}

		match ($level) {
			'info' => $this->logger->info($this->prefix($message)),
			'warning' => $this->logger->warning($this->prefix($message)),
			default => $this->logger->debug($this->prefix($message)),
		};
	}

	private function prefix(string $message) : string
	{
		return sprintf('[%s] %s', $this->channel, $message);
	}
}
