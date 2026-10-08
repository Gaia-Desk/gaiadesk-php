<?php

declare(strict_types=1);

namespace GaiaDesk\Exception;

/**
 * Said no: no or a bad credential, a missing scope, a token without the scope, rate limited, the desk opted out, an admin request refused (kind `refused`; HTTP 401, 403, 429; exit 254).
 */
class RefusedException extends GaiaDeskException
{
}
