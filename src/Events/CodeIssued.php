<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Events;

use Liberu\Ecommerce\Promotions\Models\Code;

final readonly class CodeIssued
{
    public function __construct(public Code $code) {}
}
