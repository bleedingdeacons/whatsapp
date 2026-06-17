<?php

declare(strict_types=1);

namespace Whatsapp\Messaging;

if (!defined('ABSPATH')) {
    exit;
}

use Rabbit\Messaging\Models\Message;

/**
 * Turns a Rabbit {@see Message} into the request body the WhatsApp
 * Cloud API expects at `POST /<phone-number-id>/messages`.
 *
 * Two shapes are produced, mirroring {@see Message}'s two types:
 *
 *  - text:     {messaging_product, to, type:"text", text:{body}}
 *  - template: {messaging_product, to, type:"template",
 *               template:{name, language:{code}, components:[...]}}
 *
 * Pure and WP-free, so it can be unit-tested directly. The service
 * json-encodes whatever this returns.
 *
 * Reference: https://developers.facebook.com/docs/whatsapp/cloud-api/reference/messages
 */
final class WhatsAppPayloadBuilder
{
    /**
     * @return array<string,mixed>
     */
    public function build(Message $message): array
    {
        $base = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => self::normaliseTo($message->getTo()->getPhone()),
        ];

        if ($message->isTemplate()) {
            return $base + [
                'type' => 'template',
                'template' => $this->templatePayload($message),
            ];
        }

        // Default: free-form text.
        return $base + [
            'type' => 'text',
            'text' => [
                // preview_url off by default — links are sent as plain
                // text unless a caller opts in. Keeps unexpected link
                // previews out of member messages.
                'preview_url' => false,
                'body' => $message->getBody(),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function templatePayload(Message $message): array
    {
        $template = [
            'name' => $message->getTemplateName(),
            'language' => [
                'code' => $message->getTemplateLanguage(),
            ],
        ];

        $params = $message->getTemplateParams();
        if ($params !== []) {
            // Positional body parameters become an ordered list of text
            // parameters under a single "body" component. Named/ header/
            // button parameters are out of scope for this driver — a
            // template that needs them should be sent via a richer call
            // path; the common reminder/notification case is body-only.
            $template['components'] = [
                [
                    'type' => 'body',
                    'parameters' => array_map(
                        static fn(string $p): array => ['type' => 'text', 'text' => $p],
                        array_values($params),
                    ),
                ],
            ];
        }

        return $template;
    }

    /**
     * The Cloud API wants the destination as a country-code + number with
     * no leading `+` and no punctuation. Strip everything that isn't a
     * digit. (The message is validated upstream by
     * {@see \Rabbit\Messaging\AbstractMessageService::validateMessage()};
     * this is the provider-specific canonicalisation.)
     */
    public static function normaliseTo(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }
}
