<?php

declare(strict_types=1);

/**
 * Test bootstrap for WhatsApp (Pest, running on PHPUnit).
 *
 * WordPress stand-ins come from bleedingdeacons/wp-mocks, shared across the
 * plugin suite. Its bootstrap loads Patchwork before anything patchable, so
 * anything below that defines WordPress functions of its own must stay after
 * the Bootstrap::load() call, not before it.
 *
 * Not loaded here: the `sentinel` stub group. Whatsapp\Logger\HasLogger is
 * written to degrade to error_log() when wp_log() is absent, and that is the
 * branch these tests run; defining wp_log() would silently route logging
 * somewhere no test looks.
 *
 * The Rabbit library this driver builds on is a Composer dependency, so the
 * autoloader below supplies it. Beyond the stubs this loads only the WhatsApp
 * source under test.
 */

use BleedingDeacons\WpMocks\Bootstrap;
use BleedingDeacons\WpMocks\WpState;

require_once __DIR__ . '/../vendor/autoload.php';

Bootstrap::load(['wordpress']);

// Makes plugins_url()/plugin_dir_url() answer with WhatsApp's own path.
WpState::$pluginSlug = 'whatsapp';

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// --- WhatsApp source under test ----------------------------------------
$src = __DIR__ . '/../src';
require_once $src . '/Logger/HasLogger.php';
require_once $src . '/Messaging/WhatsAppPayloadBuilder.php';
require_once $src . '/Messaging/WhatsAppResponseParser.php';
require_once $src . '/Messaging/WhatsAppMessageService.php';
