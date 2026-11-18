<?php

namespace Rishadblack\SyllooPosCore\Services;

use Rishadblack\SyllooAppBase\Erp\ErpCircuitBreaker as BaseErpCircuitBreaker;

/**
 * One shared "the ERP is offline until T" for every background call (push, pull, images, serial checks), so a till
 * without internet stops dialling on every trigger. The rule is the shared base's ({@see BaseErpCircuitBreaker}, the
 * same cache keys): after FAILURES_TO_OPEN failed connections in a row the breaker opens for 30 seconds, doubling up
 * to 10 minutes while the ERP stays away. The heartbeat, the sign-in and the activation always try (they are the
 * probes), and any answer from the ERP closes it again.
 */
class ErpCircuitBreaker extends BaseErpCircuitBreaker {}
