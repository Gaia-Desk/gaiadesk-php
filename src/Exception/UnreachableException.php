<?php

declare(strict_types=1);

namespace GaiaDesk\Exception;

/**
 * The API or the desk could not be reached: unknown_desk, offline, no_wake_path, network, timeout, ... (kind `unreachable` or finer; HTTP 404, 409, 503, 504).
 */
class UnreachableException extends GaiaDeskException
{
}
