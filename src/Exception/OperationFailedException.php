<?php

declare(strict_types=1);

namespace GaiaDesk\Exception;

/**
 * The operation ran and did not succeed: no such job, a file that failed to copy, ... (kind `failed`; HTTP 422, 500; exit 1).
 */
class OperationFailedException extends GaiaDeskException
{
}
