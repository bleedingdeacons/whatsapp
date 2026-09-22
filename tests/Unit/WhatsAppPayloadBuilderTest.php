<?php

declare(strict_types=1);

namespace Whatsapp\Tests\Unit;

use Rabbit\Messaging\Models\Message;
use Rabbit\Messaging\Models\Recipient;
use Whatsapp\Messaging\WhatsAppPayloadBuilder;

it('builds a text payload', function () {
    $payload = (new WhatsAppPayloadBuilder())->build(
        Message::text(Recipient::to('+44 7700 900123'), 'Hello there')
    );

    expect($payload['messaging_product'])->toBe('whatsapp')
        ->and($payload['recipient_type'])->toBe('individual')
        ->and($payload['to'])->toBe('447700900123') // + and spaces stripped
        ->and($payload['type'])->toBe('text')
        ->and($payload['text']['body'])->toBe('Hello there')
        ->and($payload['text']['preview_url'])->toBeFalse()
        ->and($payload)->not->toHaveKey('template');
});

it('builds a template payload with params', function () {
    $payload = (new WhatsAppPayloadBuilder())->build(
        Message::template(Recipient::to('447700900123'), 'shift_reminder', 'en_GB', ['1 hour', 'Tuesday'])
    );

    expect($payload['type'])->toBe('template')
        ->and($payload['template']['name'])->toBe('shift_reminder')
        ->and($payload['template']['language']['code'])->toBe('en_GB');

    $components = $payload['template']['components'];
    expect($components)->toHaveCount(1)
        ->and($components[0]['type'])->toBe('body')
        ->and($components[0]['parameters'])->toBe([
            ['type' => 'text', 'text' => '1 hour'],
            ['type' => 'text', 'text' => 'Tuesday'],
        ]);
});

it('omits components from a template payload without params', function () {
    $payload = (new WhatsAppPayloadBuilder())->build(
        Message::template(Recipient::to('447700900123'), 'hello_world', 'en_US')
    );

    expect($payload['template']['name'])->toBe('hello_world')
        ->and($payload['template'])->not->toHaveKey('components');
});

it('strips non-digits when normalising the recipient', function () {
    expect(WhatsAppPayloadBuilder::normaliseTo('+44 (7700) 900-123'))->toBe('447700900123')
        ->and(WhatsAppPayloadBuilder::normaliseTo('not a number'))->toBe('');
});
