<?php

declare(strict_types=1);

namespace Whatsapp\Tests\Unit;

use Rabbit\Messaging\Interfaces\MessagingException;
use Rabbit\Messaging\Models\Message;
use Rabbit\Messaging\Models\Recipient;
use Rabbit\Transport\Interfaces\HttpTransport;
use Rabbit\Transport\Interfaces\TransportException;
use Whatsapp\Messaging\WhatsAppMessageService;
use Whatsapp\Messaging\WhatsAppPayloadBuilder;
use Whatsapp\Messaging\WhatsAppResponseParser;

/** Scriptable HttpTransport fake — records calls, returns queued responses. */
final class ScriptedTransport implements HttpTransport
{
    /** @var array<int,array{method:string,url:string,headers:array<string,string>,body:string}> */
    public array $calls = [];

    /** @var array<int,array{status:int,body:string,headers:array<string,string>}> */
    private array $queue = [];

    private ?string $throwMessage = null;

    public function queue(int $status, string $body): void
    {
        $this->queue[] = ['status' => $status, 'body' => $body, 'headers' => []];
    }

    public function throwOnNext(string $message): void
    {
        $this->throwMessage = $message;
    }

    public function request(string $method, string $url, array $headers = [], string $body = ''): array
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        if ($this->throwMessage !== null) {
            $msg = $this->throwMessage;
            $this->throwMessage = null;
            throw new TransportException($msg);
        }
        return array_shift($this->queue) ?? ['status' => 200, 'body' => '', 'headers' => []];
    }
}

function whatsappService(ScriptedTransport $transport, string $token = 'TKN', string $phoneId = '1234567890'): WhatsAppMessageService
{
    return new WhatsAppMessageService(
        transport: $transport,
        builder: new WhatsAppPayloadBuilder(),
        parser: new WhatsAppResponseParser(),
        accessToken: $token,
        phoneNumberId: $phoneId,
        apiVersion: 'v23.0',
        baseUrl: 'https://graph.facebook.com',
    );
}

describe('send', function () {
    it('posts to the messages endpoint with a bearer token and JSON', function () {
        $transport = new ScriptedTransport();
        $transport->queue(200, json_encode(['messages' => [['id' => 'wamid.OK']]]));

        $result = whatsappService($transport)
            ->send(Message::text(Recipient::to('+447700900123', 'Anon', 5), 'Hi'));

        expect($result->getMessageId())->toBe('wamid.OK');

        $call = $transport->calls[0];
        expect($call['method'])->toBe('POST')
            ->and($call['url'])->toBe('https://graph.facebook.com/v23.0/1234567890/messages')
            ->and($call['headers']['Authorization'])->toBe('Bearer TKN')
            ->and($call['headers']['Content-Type'])->toBe('application/json');

        $sent = json_decode($call['body'], true);
        expect($sent['messaging_product'])->toBe('whatsapp')
            ->and($sent['to'])->toBe('447700900123')
            ->and($sent['text']['body'])->toBe('Hi');
    });

    it('throws not configured without a token', function () {
        $transport = new ScriptedTransport();
        $service = whatsappService($transport, token: '');

        expect(fn () => $service->send(Message::text(Recipient::to('+447700900123'), 'Hi')))
            ->toThrow(MessagingException::class, 'not configured');

        expect($transport->calls)->toHaveCount(0); // never reached the wire
    });

    it('surfaces a Graph error', function () {
        $transport = new ScriptedTransport();
        $transport->queue(401, json_encode(['error' => ['message' => 'Bad token', 'code' => 190]]));

        whatsappService($transport)->send(Message::text(Recipient::to('+447700900123'), 'Hi'));
    })->throws(MessagingException::class, 'Bad token');

    it('wraps a transport failure', function () {
        $transport = new ScriptedTransport();
        $transport->throwOnNext('dns failure');

        whatsappService($transport)->send(Message::text(Recipient::to('+447700900123'), 'Hi'));
    })->throws(MessagingException::class, 'Could not reach the WhatsApp API');
});

it('throws on an invalid message before sending', function () {
    $transport = new ScriptedTransport();
    $service = whatsappService($transport);

    expect(fn () => $service->send(Message::text(Recipient::to('+447700900123'), '   '))) // empty body
        ->toThrow(MessagingException::class, 'non-empty body');

    expect($transport->calls)->toHaveCount(0);
});

describe('testConnection', function () {
    it('gets the phone number node', function () {
        $transport = new ScriptedTransport();
        $transport->queue(200, json_encode(['id' => '1234567890', 'display_phone_number' => '+44 7700 900123']));

        expect(whatsappService($transport)->testConnection())->toBeTrue();

        $call = $transport->calls[0];
        expect($call['method'])->toBe('GET')
            ->and($call['url'])->toBe('https://graph.facebook.com/v23.0/1234567890?fields=display_phone_number,verified_name')
            ->and($call['headers']['Authorization'])->toBe('Bearer TKN');
    });

    it('throws on failure', function () {
        $transport = new ScriptedTransport();
        $transport->queue(401, json_encode(['error' => ['message' => 'Bad token', 'code' => 190]]));

        whatsappService($transport)->testConnection();
    })->throws(MessagingException::class, 'Bad token');
});
