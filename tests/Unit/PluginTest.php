<?php

declare(strict_types=1);

namespace Whatsapp\Tests\Unit;

use BleedingDeacons\WpMocks\WpState;
use Psr\Container\ContainerInterface;
use Rabbit\Members\MemberMessenger;
use Rabbit\Messaging\Interfaces\MessageService;
use Rabbit\Transport\Interfaces\HttpTransport;
use Rabbit\Transport\Interfaces\HttpTransportFactory;
use Whatsapp\Messaging\WhatsAppMessageService;
use Whatsapp\Plugin;

/**
 * The shape of Unity's container that WhatsApp registers against:
 * PSR-11 plus register($id, $factory), with factories resolved lazily.
 */
final class RegisteringContainer implements ContainerInterface
{
    /** @var array<string,\Closure> */
    public array $factories = [];

    public function register(string $id, \Closure $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function get(string $id): mixed
    {
        return ($this->factories[$id])($this);
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }
}

it('registers its driver and MemberMessenger into Unity\'s container', function () {
    if (!defined('WHATSAPP_OPTION_KEY')) {
        define('WHATSAPP_OPTION_KEY', 'whatsapp_settings');
    }
    // The settings page is not what this is about.
    WpState::$isAdmin = false;
    $container = new RegisteringContainer();

    Plugin::init($container);

    // MemberMessenger is resolved by consumers, not here: it needs Unity's
    // MemberRepository, and Unity is not part of this suite.
    expect(array_keys($container->factories))->toEqualCanonicalizing([
        HttpTransportFactory::class,
        HttpTransport::class,
        MessageService::class,
        MemberMessenger::class,
    ])
        ->and($container->get(MessageService::class))->toBeInstanceOf(WhatsAppMessageService::class);
});
