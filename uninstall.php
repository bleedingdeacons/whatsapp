<?php

/**
 * Fired when WhatsApp is uninstalled.
 *
 * Removes WhatsApp's options row and the messaging roles, which WhatsApp
 * took over when Rabbit became a library. Scrutiny audit entries are
 * owned by Scrutiny and intentionally preserved.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('whatsapp_settings');

if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    \Rabbit\Capabilities\CapabilityBootstrap::remove();
}
