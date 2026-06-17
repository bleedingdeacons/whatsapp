<?php

declare(strict_types=1);

namespace Whatsapp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rabbit\Messaging\Models\Message;
use Rabbit\Messaging\Models\Recipient;
use Whatsapp\Messaging\WhatsAppPayloadBuilder;

final class WhatsAppPayloadBuilderTest extends TestCase
{
    public function test_text_payload(): void
    {
        $payload = (new WhatsAppPayloadBuilder())->build(
            Message::text(Recipient::to('+44 7700 900123'), 'Hello there')
        );

        $this->assertSame('whatsapp', $payload['messaging_product']);
        $this->assertSame('individual', $payload['recipient_type']);
        $this->assertSame('447700900123', $payload['to']); // + and spaces stripped
        $this->assertSame('text', $payload['type']);
        $this->assertSame('Hello there', $payload['text']['body']);
        $this->assertFalse($payload['text']['preview_url']);
        $this->assertArrayNotHasKey('template', $payload);
    }

    public function test_template_payload_with_params(): void
    {
        $payload = (new WhatsAppPayloadBuilder())->build(
            Message::template(Recipient::to('447700900123'), 'shift_reminder', 'en_GB', ['1 hour', 'Tuesday'])
        );

        $this->assertSame('template', $payload['type']);
        $this->assertSame('shift_reminder', $payload['template']['name']);
        $this->assertSame('en_GB', $payload['template']['language']['code']);

        $components = $payload['template']['components'];
        $this->assertCount(1, $components);
        $this->assertSame('body', $components[0]['type']);
        $this->assertSame(
            [
                ['type' => 'text', 'text' => '1 hour'],
                ['type' => 'text', 'text' => 'Tuesday'],
            ],
            $components[0]['parameters']
        );
    }

    public function test_template_payload_without_params_omits_components(): void
    {
        $payload = (new WhatsAppPayloadBuilder())->build(
            Message::template(Recipient::to('447700900123'), 'hello_world', 'en_US')
        );

        $this->assertSame('hello_world', $payload['template']['name']);
        $this->assertArrayNotHasKey('components', $payload['template']);
    }

    public function test_normalise_to_strips_non_digits(): void
    {
        $this->assertSame('447700900123', WhatsAppPayloadBuilder::normaliseTo('+44 (7700) 900-123'));
        $this->assertSame('', WhatsAppPayloadBuilder::normaliseTo('not a number'));
    }
}
