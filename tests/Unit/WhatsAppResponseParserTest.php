<?php

declare(strict_types=1);

namespace Whatsapp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rabbit\Messaging\Interfaces\MessagingException;
use Whatsapp\Messaging\WhatsAppResponseParser;

final class WhatsAppResponseParserTest extends TestCase
{
    public function test_parses_successful_send(): void
    {
        $body = json_encode([
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => '447700900123', 'wa_id' => '447700900123']],
            'messages' => [['id' => 'wamid.HBgLABCDEF']],
        ]);

        $result = (new WhatsAppResponseParser())->parse(['status' => 200, 'body' => $body]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('wamid.HBgLABCDEF', $result->getMessageId());
        $this->assertSame('accepted', $result->getStatus());
    }

    public function test_error_status_throws_with_graph_message(): void
    {
        $body = json_encode([
            'error' => [
                'message' => 'Invalid OAuth access token.',
                'type' => 'OAuthException',
                'code' => 190,
            ],
        ]);

        $this->expectException(MessagingException::class);
        $this->expectExceptionMessage('Invalid OAuth access token. (code 190)');
        (new WhatsAppResponseParser())->parse(['status' => 401, 'body' => $body]);
    }

    public function test_success_status_without_message_id_throws(): void
    {
        $this->expectException(MessagingException::class);
        $this->expectExceptionMessage('no message id');
        (new WhatsAppResponseParser())->parse(['status' => 200, 'body' => '{"messaging_product":"whatsapp"}']);
    }

    public function test_extract_error_includes_subcode(): void
    {
        $body = json_encode([
            'error' => [
                'message' => 'Recipient not in allowed list',
                'code' => 131030,
                'error_subcode' => 2655007,
            ],
        ]);
        $this->assertSame(
            'Recipient not in allowed list (code 131030, subcode 2655007)',
            WhatsAppResponseParser::extractError($body)
        );
    }

    public function test_extract_error_returns_empty_for_non_error_body(): void
    {
        $this->assertSame('', WhatsAppResponseParser::extractError('{"ok":true}'));
        $this->assertSame('', WhatsAppResponseParser::extractError('not json'));
    }

    public function test_error_status_without_body_falls_back_to_http_code(): void
    {
        $this->expectException(MessagingException::class);
        $this->expectExceptionMessage('HTTP 500');
        (new WhatsAppResponseParser())->parse(['status' => 500, 'body' => '']);
    }
}
