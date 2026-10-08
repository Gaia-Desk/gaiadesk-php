<?php

declare(strict_types=1);

namespace GaiaDesk\Exception;

/**
 * The desk went away during the operation (kind `connection_lost`; HTTP 502).
 */
class ConnectionLostException extends GaiaDeskException
{
}
