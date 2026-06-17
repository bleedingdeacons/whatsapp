<?php

/**
 * Fired when WhatsApp is uninstalled.
 *
 * Removes WhatsApp's options row. Rabbit's capabilities are owned by
 * Rabbit and cleaned up by its own uninstaller; Scrutiny audit
 * entries are owned by Scrutiny and intentionally preserved.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('whatsapp_settings');
