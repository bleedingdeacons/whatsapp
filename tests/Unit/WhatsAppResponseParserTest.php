<?php

declare(strict_types=1);

namespace Whatsapp\Tests\Unit;

use Rabbit\Messaging\Interfaces\MessagingException;
use Whatsapp\Messaging\WhatsAppResponseParser;

describe('parse', function () {
    it('parses a successful send', function () {
        $body = json_encode([
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => '447700900123', 'wa_id' => '447700900123']],
            'messages' => [['id' => 'wamid.HBgLABCDEF']],
        ]);

        $result = (new WhatsAppResponseParser())->parse(['status' => 200, 'body' => $body]);

        expect($result->isSuccess())->toBeTrue()
            ->and($result->getMessageId())->toBe('wamid.HBgLABCDEF')
            ->and($result->getStatus())->toBe('accepted');
    });

    it('throws with the Graph message on an error status', function () {
        $body = json_encode([
            'error' => [
                'message' => 'Invalid OAuth access token.',
                'type' => 'OAuthException',
                'code' => 190,
            ],
        ]);

        (new WhatsAppResponseParser())->parse(['status' => 401, 'body' => $body]);
    })->throws(MessagingException::class, 'Invalid OAuth access token. (code 190)');

    it('throws on a success status without a message id', function () {
        (new WhatsAppResponseParser())->parse(['status' => 200, 'body' => '{"messaging_product":"whatsapp"}']);
    })->throws(MessagingException::class, 'no message id');

    it('falls back to the HTTP code on an error status without a body', function () {
        (new WhatsAppResponseParser())->parse(['status' => 500, 'body' => '']);
    })->throws(MessagingException::class, 'HTTP 500');
});

describe('extractError', function () {
    it('includes the subcode', function () {
        $body = json_encode([
            'error' => [
                'message' => 'Recipient not in allowed list',
                'code' => 131030,
                'error_subcode' => 2655007,
            ],
        ]);
        expect(WhatsAppResponseParser::extractError($body))
            ->toBe('Recipient not in allowed list (code 131030, subcode 2655007)');
    });

    it('returns empty for a non-error body', function () {
        expect(WhatsAppResponseParser::extractError('{"ok":true}'))->toBe('')
            ->and(WhatsAppResponseParser::extractError('not json'))->toBe('');
    });
});
