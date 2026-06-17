<?php

declare(strict_types=1);

namespace Whatsapp;

if (!defined('ABSPATH')) {
    exit;
}

use Psr\Container\ContainerInterface;
use Whatsapp\Core\WhatsAppServiceProvider;
use Whatsapp\Admin\SettingsPage;

/**
 * Main WhatsApp Plugin Class.
 *
 * WhatsApp is the implementation plugin — it binds a concrete driver for
 * {@see \Rabbit\Messaging\Interfaces\MessageService} against
 * Rabbit's contract, talking to the Meta WhatsApp Business Cloud API.
 *
 * The class is intentionally thin. Real work happens in the service
 * provider (container wiring) and the admin page (UI).
 */
class Plugin
{
    use \Whatsapp\Logger\HasLogger;

    protected static function logChannel(): string
    {
        return 'whatsapp';
    }

    private static ?ContainerInterface $container = null;
    private static bool $initialized = false;

    public static function init(ContainerInterface $container): void
    {
        if (self::$initialized) {
            return;
        }

        self::$container = $container;

        (new WhatsAppServiceProvider())->register($container);

        // Admin UI bootstraps itself — it reads the bound service out of
        // the container when it needs it rather than holding a reference
        // at construction time, so a later override of MessageService is
        // picked up automatically.
        if (is_admin()) {
            (new SettingsPage($container))->register();
        }

        self::$initialized = true;

        self::logDebug('Initialised', ['version' => defined('WHATSAPP_VERSION') ? WHATSAPP_VERSION : 'unknown']);
    }

    public static function getContainer(): ContainerInterface
    {
        if (self::$container === null) {
            throw new \RuntimeException('WhatsApp Plugin not initialised — wait for the whatsapp/loaded action.');
        }
        return self::$container;
    }

    public static function isInitialized(): bool
    {
        return self::$initialized;
    }
}
