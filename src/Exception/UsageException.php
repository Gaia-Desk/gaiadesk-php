<?php

declare(strict_types=1);

namespace GaiaDesk\Exception;

/**
 * The request is malformed, or an option is not valid (kind `usage`). Caught by the SDK before anything is sent, or answered 400 by the API.
 */
class UsageException extends GaiaDeskException
{
}
