<?php

namespace Rishadblack\SyllooPosCore\Desks;

use RuntimeException;

/**
 * A sale the desk will not save (an empty cart, a wrong quantity, money that does not cover it): the message is the
 * sentence the cashier sees.
 */
class SaleRefused extends RuntimeException {}
