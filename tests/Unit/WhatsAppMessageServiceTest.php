<?php

declare(strict_types=1);

namespace Whatsapp\Tests\Unit;

use PHPUnit\Framework\TestCase;
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

final class WhatsAppMessageServiceTest extends TestCase
{
    private function makeService(ScriptedTransport $transport, string $token = 'TKN', string $phoneId = '1234567890'): WhatsAppMessageService
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

    public function test_send_posts_to_messages_endpoint_with_bearer_and_json(): void
    {
        $transport = new ScriptedTransport();
        $transport->queue(200, json_encode(['messages' => [['id' => 'wamid.OK']]]));

        $result = $this->makeService($transport)
            ->send(Message::text(Recipient::to('+447700900123', 'Anon', 5), 'Hi'));

        $this->assertSame('wamid.OK', $result->getMessageId());

        $call = $transport->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://graph.facebook.com/v23.0/1234567890/messages', $call['url']);
        $this->assertSame('Bearer TKN', $call['headers']['Authorization']);
        $this->assertSame('application/json', $call['headers']['Content-Type']);

        $sent = json_decode($call['body'], true);
        $this->assertSame('whatsapp', $sent['messaging_product']);
        $this->assertSame('447700900123', $sent['to']);
        $this->assertSame('Hi', $sent['text']['body']);
    }

    public function test_send_without_token_throws_not_configured(): void
    {
        $transport = new ScriptedTransport();
        $service = $this->makeService($transport, token: '');

        $this->expectException(MessagingException::class);
        $this->expectExceptionMessage('not configured');
        $service->send(Message::text(Recipient::to('+447700900123'), 'Hi'));

        $this->assertCount(0, $transport->calls); // never reached the wire
    }

    public function test_send_surfaces_graph_error(): void
    {
        $transport = new ScriptedTransport();
        $transport->queue(401, json_encode(['error' => ['message' => 'Bad token', 'code' => 190]]));

        $this->expectException(MessagingException::class);
        $this->expectExceptionMessage('Bad token');
        $this->makeService($transport)->send(Message::text(Recipient::to('+447700900123'), 'Hi'));
    }

    public function test_send_wraps_transport_failure(): void
    {
        $transport = new ScriptedTransport();
        $transport->throwOnNext('dns failure');

        $this->expectException(MessagingException::class);
        $this->expectExceptionMessage('Could not reach the WhatsApp API');
        $this->makeService($transport)->send(Message::text(Recipient::to('+447700900123'), 'Hi'));
    }

    public function test_invalid_message_throws_before_sending(): void
    {
        $transport = new ScriptedTransport();
        $service = $this->makeService($transport);

        try {
            $service->send(Message::text(Recipient::to('+447700900123'), '   ')); // empty body
            $this->fail('expected MessagingException');
        } catch (MessagingException $e) {
            $this->assertStringContainsString('non-empty body', $e->getMessage());
        }
        $this->assertCount(0, $transport->calls);
    }

    public function test_test_connection_gets_phone_number_node(): void
    {
        $transport = new ScriptedTransport();
        $transport->queue(200, json_encode(['id' => '1234567890', 'display_phone_number' => '+44 7700 900123']));

        $this->assertTrue($this->makeService($transport)->testConnection());

        $call = $transport->calls[0];
        $this->assertSame('GET', $call['method']);
        $this->assertSame(
            'https://graph.facebook.com/v23.0/1234567890?fields=display_phone_number,verified_name',
            $call['url']
        );
        $this->assertSame('Bearer TKN', $call['headers']['Authorization']);
    }

    public function test_test_connection_failure_throws(): void
    {
        $transport = new ScriptedTransport();
        $transport->queue(401, json_encode(['error' => ['message' => 'Bad token', 'code' => 190]]));

        $this->expectException(MessagingException::class);
        $this->expectExceptionMessage('Bad token');
        $this->makeService($transport)->testConnection();
    }
}
