<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Rishadblack\SyllooPosCore\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Feature');

pest()->use(RefreshDatabase::class)->in('Feature');
