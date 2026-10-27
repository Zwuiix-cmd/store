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
 * Key derivation strategies supported by encrypted Store documents.
 */
enum StoreKdf : string
{
	case Pbkdf2Sha256 = 'pbkdf2-sha256';
	case Argon2id = 'argon2id';
	case Raw = 'raw';
}
