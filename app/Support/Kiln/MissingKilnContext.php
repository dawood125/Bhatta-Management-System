<?php

namespace App\Support\Kiln;

use LogicException;

/**
 * Thrown when kiln-owned data is touched without a kiln context.
 * Failing loudly is deliberate: silently returning another kiln's data would be far worse.
 */
class MissingKilnContext extends LogicException
{
    public function __construct(string $message = 'No kiln context is set for this operation.')
    {
        parent::__construct($message);
    }
}
