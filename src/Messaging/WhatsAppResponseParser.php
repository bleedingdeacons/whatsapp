<?php

declare(strict_types=1);

namespace Whatsapp\Messaging;

if (!defined('ABSPATH')) {
    exit;
}

use Rabbit\Messaging\Interfaces\MessagingException;
use Rabbit\Messaging\Models\MessageResult;

/**
 * Interprets a WhatsApp Cloud API HTTP response.
 *
 * A successful send returns 200 with:
 *   { "messaging_product":"whatsapp",
 *     "contacts":[{"input":"…","wa_id":"…"}],
 *     "messages":[{"id":"wamid.HBg…"}] }
 *
 * A failure returns a 4xx/5xx with a Graph error envelope:
 *   { "error":{ "message":"…","type":"OAuthException","code":190,
 *               "error_subcode":…, "fbtrace_id":"…" } }
 *
 * Pure and WP-free so it can be unit-tested directly. {@see parse()}
 * returns a {@see MessageResult} on success and throws a
 * {@see MessagingException} with a readable message on any failure.
 */
final class WhatsAppResponseParser
{
    /**
     * @param array{status:int,body:string,headers?:array<string,string>} $response
     *
     * @throws MessagingException
     */
    public function parse(array $response): MessageResult
    {
        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');

        $decoded = json_decode($body, true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($status < 200 || $status >= 300) {
            $error = self::extractError($body);
            throw new MessagingException(
                $error !== ''
                    ? 'WhatsApp API error: ' . $error
                    : 'WhatsApp API returned HTTP ' . $status . '.'
            );
        }

        $messageId = '';
        if (isset($decoded['messages'][0]['id']) && is_string($decoded['messages'][0]['id'])) {
            $messageId = $decoded['messages'][0]['id'];
        }

        if ($messageId === '') {
            // 2xx but no message id — the request was accepted in a shape
            // we don't recognise. Surface it rather than reporting a
            // success we can't substantiate.
            throw new MessagingException(
                'WhatsApp API returned HTTP ' . $status . ' but no message id; the response could not be interpreted.'
            );
        }

        return MessageResult::accepted($messageId, $decoded);
    }

    /**
     * Pull a human-readable error string out of a Graph error envelope.
     * Returns '' if the body carries no recognisable error. Includes the
     * numeric code in parentheses when present, since Meta's codes (e.g.
     * 190 = bad/expired token, 131030 = recipient not in allowed list)
     * are the fastest way for an operator to diagnose the problem.
     */
    public static function extractError(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['error']) || !is_array($decoded['error'])) {
            return '';
        }
        $error = $decoded['error'];
        $message = (string) ($error['message'] ?? '');
        $code = $error['code'] ?? null;
        $subcode = $error['error_subcode'] ?? null;

        if ($message === '' && $code === null) {
            return '';
        }

        $parts = [];
        if ($message !== '') {
            $parts[] = $message;
        }
        $codeBits = [];
        if ($code !== null) {
            $codeBits[] = 'code ' . (string) $code;
        }
        if ($subcode !== null) {
            $codeBits[] = 'subcode ' . (string) $subcode;
        }
        if ($codeBits !== []) {
            $parts[] = '(' . implode(', ', $codeBits) . ')';
        }

        return implode(' ', $parts);
    }
}
