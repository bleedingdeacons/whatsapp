<?php

declare(strict_types=1);

// Pest configuration.
//
// Every file in this suite ran on wp-mocks' TestCase as a PHPUnit class, so
// every file still does: the whole of tests/Unit is bound to it below. That
// brings Brain Monkey's lifecycle and Mockery integration with it, which the
// driver, builder and parser tests do not strictly need today — but a test
// that reaches WordPress-registering code (add_action(), add_filter()) only
// finds those functions defined inside that TestCase's setUp(), so keeping the
// original binding means a new test in tests/Unit never has to ask.
//
// A test that genuinely needs none of it and should run on Pest's default,
// plain PHPUnit, has to live outside tests/Unit or this binding has to be
// narrowed to name files individually.

use BleedingDeacons\WpMocks\TestCase;

pest()->extend(TestCase::class)->in('Unit');
