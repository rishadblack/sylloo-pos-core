<?php

namespace Rishadblack\SyllooPosCore\Desks;

use RuntimeException;

/**
 * The Shift desk refuses: the message is the sentence for the cashier (shift.*, in the till's language).
 */
class ShiftRefused extends RuntimeException {}
