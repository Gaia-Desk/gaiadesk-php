<?php

declare(strict_types=1);

namespace GaiaDesk\Exception;

/**
 * An answer that is not what the contract says: not JSON, no error envelope, a sealed answer that does not open, or a desk too old for the request (kind `protocol`).
 */
class ProtocolException extends GaiaDeskException
{
}
