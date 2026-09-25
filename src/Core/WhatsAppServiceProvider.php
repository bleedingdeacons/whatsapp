<?php

declare(strict_types=1);

namespace Whatsapp\Core;

if (!defined('ABSPATH')) {
    exit;
}

use Psr\Container\ContainerInterface;
use Rabbit\Members\MemberMessenger;
use Rabbit\Messaging\Interfaces\MessageService;
use Rabbit\Transport\Interfaces\HttpTransport;
use Rabbit\Transport\Interfaces\HttpTransportFactory;
use Rabbit\Transport\UserAgent;
use Rabbit\Transport\WpHttpTransportFactory;
use Unity\Members\Interfaces\MemberRepository;
use Whatsapp\Admin\WhatsAppSettings;
use Whatsapp\Messaging\WhatsAppMessageService;
use Whatsapp\Messaging\WhatsAppPayloadBuilder;
use Whatsapp\Messaging\WhatsAppResponseParser;

/**
 * Wire WhatsApp's concrete driver into Unity's shared container.
 *
 * Four bindings:
 *
 *  1. {@see HttpTransportFactory} → {@see WpHttpTransportFactory}.
 *     The Rabbit library owns the WP-HTTP transport; WhatsApp just
 *     configures the factory (TLS verification + timeout from settings)
 *     and attributes the transport's HTTP logging to the "whatsapp"
 *     channel.
 *
 *  2. {@see HttpTransport} → resolved by asking the factory for a fresh
 *     instance.
 *
 *  3. {@see MessageService} → {@see WhatsAppMessageService}. Settings are
 *     read inside the factory, not at registration time, so an admin-page
 *     save takes effect on the next request without needing a reload.
 *
 *  4. {@see MemberMessenger} — the Rabbit library's member → message →
 *     driver + Scrutiny audit helper. The Rabbit plugin registered this
 *     until Rabbit became a library; it lives in Unity's container so any
 *     plugin booting on `unity/loaded` can resolve it, and it resolves the
 *     driver and Scrutiny's AuditLogger from that container at send time.
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
                // Names the plugin, its version, a contact address and
                // which deployment the traffic is from, so the Graph API
                // sees a request that introduces itself properly.
                userAgent: UserAgent::forApp('WhatsApp', defined('WHATSAPP_VERSION') ? WHATSAPP_VERSION : ''),
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

        $container->register(MemberMessenger::class, function (ContainerInterface $c) {
            return new MemberMessenger(
                $c,
                $c->get(MemberRepository::class),
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
