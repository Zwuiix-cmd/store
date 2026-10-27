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

namespace zwuiix\store\security;

/**
 * Authenticated encryption algorithms supported by Store.
 */
enum StoreCipher : string
{
	case Aes256Gcm = 'aes-256-gcm';
	case XChaCha20Poly1305 = 'xchacha20-poly1305-ietf';
}
