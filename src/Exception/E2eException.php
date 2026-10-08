<?php

declare(strict_types=1);

namespace GaiaDesk\Exception;

/**
 * End-to-end encryption: the SDK would not send the operation in the clear (reason
 * `e2e_unavailable`: `e2e: 'require'`, or a desk that requires it, and no key for the
 * desk) or the server handed out a key other than the pinned one (`e2e_key_mismatch`).
 * Nothing was sent to the desk.
 */
class E2eException extends RefusedException
{
}
