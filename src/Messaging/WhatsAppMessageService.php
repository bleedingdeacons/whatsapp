<?php

declare(strict_types=1);

namespace Whatsapp\Messaging;

if (!defined('ABSPATH')) {
    exit;
}

use Rabbit\Messaging\AbstractMessageService;
use Rabbit\Messaging\Interfaces\MessagingException;
use Rabbit\Messaging\Models\Message;
use Rabbit\Messaging\Models\MessageResult;
use Rabbit\Transport\Interfaces\HttpTransport;
use Rabbit\Transport\Interfaces\TransportException;

/**
 * Concrete Rabbit driver for the WhatsApp Business Cloud API.
 *
 * Sending is a single JSON POST authenticated with a bearer token:
 *
 *   POST {base}/{version}/{phone_number_id}/messages
 *   Authorization: Bearer <access token>
 *   Content-Type: application/json
 *   { messaging_product:"whatsapp", to:"…", type:"text", text:{body} }
 *
 * The connection test reads the phone-number node:
 *
 *   GET {base}/{version}/{phone_number_id}?fields=display_phone_number,verified_name
 *
 * Payload construction and response interpretation live in
 * {@see WhatsAppPayloadBuilder} and {@see WhatsAppResponseParser} (both
 * pure and unit-tested); this class is the I/O + policy layer.
 *
 * Failure handling follows the contract: transport-level failures and
 * provider rejections both surface as {@see MessagingException}. The
 * access token and message body are never written to the log.
 */
final class WhatsAppMessageService extends AbstractMessageService
{
    use \Whatsapp\Logger\HasLogger;

    /**
     * Log to the shared "whatsapp" channel rather than the default
     * (class-name-derived) channel, so send / connection-test activity
     * lands alongside the rest of the plugin's logging.
     */
    protected static function logChannel(): string
    {
        return 'whatsapp';
    }

    public function __construct(
        private readonly HttpTransport $transport,
        private readonly WhatsAppPayloadBuilder $builder,
        private readonly WhatsAppResponseParser $parser,
        private readonly string $accessToken,
        private readonly string $phoneNumberId,
        private readonly string $apiVersion = 'v23.0',
        private readonly string $baseUrl = 'https://graph.facebook.com',
    ) {
    }

    public function send(Message $message): MessageResult
    {
        // Shared validation (recipient present + plausible, body/template
        // sanity). Throws MessagingException on the first problem.
        $this->validateMessage($message);
        $this->assertConfigured();

        $url = $this->messagesUrl();
        $payload = $this->builder->build($message);
        $body = (string) wp_json_encode($payload);

        self::logInfo('Sending WhatsApp message', [
            'type' => $message->getType(),
            'to' => self::maskNumber($message->getTo()->getPhone()),
            'phone_number_id' => $this->phoneNumberId,
            'template' => $message->isTemplate() ? $message->getTemplateName() : '',
        ]);

        try {
            $resp = $this->transport->request('POST', $url, $this->authHeaders(), $body);
        } catch (TransportException $e) {
            self::logError('Transport failure sending WhatsApp message', [
                'phone_number_id' => $this->phoneNumberId,
                'error' => $e->getMessage(),
            ]);
            throw new MessagingException('Could not reach the WhatsApp API: ' . $e->getMessage(), 0, $e);
        }

        // Parser returns a MessageResult on success, or throws a
        // MessagingException carrying the Graph error message.
        $result = $this->parser->parse($resp);

        self::logInfo('WhatsApp message accepted', [
            'phone_number_id' => $this->phoneNumberId,
            'message_id' => $result->getMessageId(),
            'status' => $result->getStatus(),
        ]);

        return $result;
    }

    public function testConnection(): bool
    {
        $this->assertConfigured();

        $url = $this->phoneNumberUrl() . '?fields=display_phone_number,verified_name';

        self::logInfo('WhatsApp connection test started', [
            'phone_number_id' => $this->phoneNumberId,
            'base_url' => $this->baseUrl,
            'api_version' => $this->apiVersion,
        ]);

        try {
            $resp = $this->transport->request('GET', $url, $this->authHeaders());
        } catch (TransportException $e) {
            self::logError('WhatsApp connection test failed at the network layer', [
                'phone_number_id' => $this->phoneNumberId,
                'error' => $e->getMessage(),
            ]);
            throw new MessagingException('Could not reach the WhatsApp API: ' . $e->getMessage(), 0, $e);
        }

        $status = (int) $resp['status'];
        $rawBody = (string) $resp['body'];

        if ($status < 200 || $status >= 300) {
            $error = WhatsAppResponseParser::extractError($rawBody);
            self::logError('WhatsApp connection test rejected', [
                'phone_number_id' => $this->phoneNumberId,
                'status' => $status,
            ]);
            throw new MessagingException(
                $error !== ''
                    ? 'WhatsApp rejected the connection: ' . $error
                    : 'WhatsApp returned HTTP ' . $status . ' for the connection test. Check the access token and phone number ID.'
            );
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded) || !isset($decoded['id'])) {
            self::logError('WhatsApp connection test returned an unexpected body', [
                'phone_number_id' => $this->phoneNumberId,
                'status' => $status,
            ]);
            throw new MessagingException(
                'WhatsApp answered the connection test but the response was not the expected phone-number node. Check the phone number ID.'
            );
        }

        self::logInfo('WhatsApp connection test succeeded', [
            'phone_number_id' => $this->phoneNumberId,
            'status' => $status,
        ]);
        return true;
    }

    // -- internals --------------------------------------------------------

    /**
     * @throws MessagingException
     */
    private function assertConfigured(): void
    {
        if ($this->accessToken === '' || $this->phoneNumberId === '') {
            self::logError('WhatsApp send/test aborted: not configured', [
                'has_token' => $this->accessToken !== '',
                'has_phone_number_id' => $this->phoneNumberId !== '',
            ]);
            throw new MessagingException(
                'WhatsApp is not configured: set the access token and phone number ID under Settings → WhatsApp.'
            );
        }
    }

    /**
     * @return array<string,string>
     */
    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Content-Type' => 'application/json',
        ];
    }

    private function messagesUrl(): string
    {
        return $this->phoneNumberUrl() . '/messages';
    }

    private function phoneNumberUrl(): string
    {
        return rtrim($this->baseUrl, '/')
            . '/' . rawurlencode($this->apiVersion)
            . '/' . rawurlencode($this->phoneNumberId);
    }
}
