<?php

declare(strict_types=1);

namespace Whatsapp\Core;

if (!defined('ABSPATH')) {
    exit;
}

use Psr\Container\ContainerInterface;
use Rabbit\Messaging\Interfaces\MessageService;
use Rabbit\Transport\Interfaces\HttpTransport;
use Rabbit\Transport\Interfaces\HttpTransportFactory;
use Rabbit\Transport\WpHttpTransportFactory;
use Whatsapp\Admin\WhatsAppSettings;
use Whatsapp\Messaging\WhatsAppMessageService;
use Whatsapp\Messaging\WhatsAppPayloadBuilder;
use Whatsapp\Messaging\WhatsAppResponseParser;

/**
 * Wire WhatsApp's concrete driver into Rabbit's (Unity's) container.
 *
 * Three bindings:
 *
 *  1. {@see HttpTransportFactory} → {@see WpHttpTransportFactory}.
 *     Rabbit owns the WP-HTTP transport; WhatsApp just configures the
 *     factory (TLS verification + timeout from settings) and attributes
 *     the transport's HTTP logging to the "whatsapp" channel.
 *
 *  2. {@see HttpTransport} → resolved by asking the factory for a fresh
 *     instance.
 *
 *  3. {@see MessageService} → {@see WhatsAppMessageService}. Settings are
 *     read inside the factory, not at registration time, so an admin-page
 *     save takes effect on the next request without needing a reload.
 *
 * All bindings are factories so a request that never sends a message
 * doesn't pay the cost of building them.
 */
final class WhatsAppServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        if (!method_exists($container, 'register')) {
            \Whatsapp\Plugin::logError(
                'Container does not support register() bindings; WhatsApp cannot register its driver.',
                ['container_class' => get_class($container)]
            );
            return;
        }

        $container->register(HttpTransportFactory::class, function () {
            $settings = WhatsAppSettings::load();
            return new WpHttpTransportFactory(
                verifyTls: $settings['verify_tls'],
                timeoutSeconds: $settings['timeout'],
                userAgent: 'WhatsApp for Rabbit (WordPress)',
                // Attribute the generic Rabbit transport's HTTP logging
                // to WhatsApp's own channel.
                logChannel: 'whatsapp',
            );
        });

        $container->register(HttpTransport::class, function (ContainerInterface $c) {
            /** @var HttpTransportFactory $factory */
            $factory = $c->get(HttpTransportFactory::class);
            return $factory->create();
        });

        $container->register(MessageService::class, function (ContainerInterface $c) {
            /** @var HttpTransport $transport */
            $transport = $c->get(HttpTransport::class);
            $settings = WhatsAppSettings::load();

            return new WhatsAppMessageService(
                transport: $transport,
                builder: new WhatsAppPayloadBuilder(),
                parser: new WhatsAppResponseParser(),
                accessToken: WhatsAppSettings::token(),
                phoneNumberId: $settings['phone_number_id'],
                apiVersion: $settings['api_version'],
                baseUrl: $settings['base_url'],
            );
        });

        /**
         * Fires after WhatsApp has bound its services into the container.
         * Useful for sibling plugins that want to wrap or decorate the
         * driver — e.g. a rate limiter, an outbound log.
         *
         * @param ContainerInterface $container
         */
        do_action('whatsapp/register_services', $container);
    }
}
