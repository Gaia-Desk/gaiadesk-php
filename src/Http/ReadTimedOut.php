<?php

declare(strict_types=1);

namespace GaiaDesk\Http;

/**
 * Nothing arrived on a connection within the time allowed ({@see SocketClient::readSome()}).
 *
 * @internal
 */
final class ReadTimedOut extends \RuntimeException
{
}
