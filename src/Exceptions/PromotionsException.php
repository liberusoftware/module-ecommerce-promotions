<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Exceptions;

use RuntimeException;

/**
 * Every failure this module raises, with the code and status a transport should
 * render it as.
 *
 * The code is exposed through a **method**, never a promoted readonly property
 * named `$code`: `Exception::$code` already exists and is not readonly, so
 * redeclaring it readonly is a fatal at class load — an unattributed crash in a
 * Pest run rather than a test failure.
 */
abstract class PromotionsException extends RuntimeException
{
    /** A stable machine-readable code, safe to publish. */
    abstract public function errorCode(): string;

    /** The HTTP status a transport should map this to. */
    abstract public function status(): int;
}
