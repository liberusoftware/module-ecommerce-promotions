<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Enums;

/**
 * Whether an offer tolerates company. Every offer declares this explicitly:
 * there is no implicit answer and no global setting, because the default a
 * merchant assumes and the default the code picked is exactly the disagreement
 * that shows up as a revenue number.
 */
enum StackingMode: string
{
    case Exclusive = 'exclusive';
    case Stackable = 'stackable';
}
