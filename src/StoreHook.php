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

namespace zwuiix\store;

/**
 * Internal Store lifecycle hooks.
 */
enum StoreHook : string
{
	case BeforeSave = 'before_save';
	case AfterSave = 'after_save';
	case AfterReload = 'after_reload';
	case Error = 'error';
}
