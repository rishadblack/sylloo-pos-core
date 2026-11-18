<?php

namespace Rishadblack\SyllooPosCore\Desks;

use RuntimeException;

/**
 * The Customer desk refuses a new customer: the message is the sentence for the cashier (till.*).
 */
class CustomerRefused extends RuntimeException {}
