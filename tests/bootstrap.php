<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for WhatsApp.
 *
 * Defines ABSPATH and a couple of WP function shims, loads the Rabbit
 * source this driver depends on (interfaces, models, abstract base,
 * transport contracts), then the WhatsApp source under test. Mirrors the
 * way Tamar's test bootstrap pulls in Beacon's source.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0, int $depth = 512): string|false
    {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key): string
    {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $key) ?? '');
    }
}

// --- Rabbit source (the contracts this driver builds on) -------------
$rabbit = __DIR__ . '/../../rabbit/src';
require_once $rabbit . '/Logger/HasLogger.php';
require_once $rabbit . '/Messaging/Interfaces/MessagingException.php';
require_once $rabbit . '/Messaging/Models/Recipient.php';
require_once $rabbit . '/Messaging/Models/Message.php';
require_once $rabbit . '/Messaging/Models/MessageResult.php';
require_once $rabbit . '/Messaging/Interfaces/MessageService.php';
require_once $rabbit . '/Messaging/AbstractMessageService.php';
require_once $rabbit . '/Transport/Interfaces/TransportException.php';
require_once $rabbit . '/Transport/Interfaces/HttpTransport.php';

// --- WhatsApp source under test ----------------------------------------
$src = __DIR__ . '/../src';
require_once $src . '/Logger/HasLogger.php';
require_once $src . '/Messaging/WhatsAppPayloadBuilder.php';
require_once $src . '/Messaging/WhatsAppResponseParser.php';
require_once $src . '/Messaging/WhatsAppMessageService.php';
