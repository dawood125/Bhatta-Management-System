<?php

namespace App\Accounting;

use LogicException;

/**
 * Raised when code tries to break a ledger rule: changing or deleting posted entries,
 * posting an unbalanced entry, reversing twice, etc. These are programming errors, never
 * user input errors, so they must fail loudly.
 */
class LedgerIntegrityException extends LogicException {}
