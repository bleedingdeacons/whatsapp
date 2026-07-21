# WhatsApp

[![CI](https://github.com/bleedingdeacons/whatsapp/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/bleedingdeacons/whatsapp/actions/workflows/ci.yml)
[![Coverage Status](https://coveralls.io/repos/github/bleedingdeacons/whatsapp/badge.svg?branch=main)](https://coveralls.io/github/bleedingdeacons/whatsapp?branch=main)
![PHPStan](https://img.shields.io/badge/PHPStan-level%205-brightgreen)
![Version](https://img.shields.io/badge/version-1.0.4-blue)
![PHP](https://img.shields.io/badge/php-8.1%2B-777bb4)
![Licence](https://img.shields.io/badge/licence-MIT%20(Modified)-green)

Rabbit driver for the **WhatsApp Business Cloud API** (Meta Graph API). It
binds a concrete `MessageService` against [Rabbit](https://github.com/thebleedingdeacons/rabbit)'s
contract so Unity members can be messaged over WhatsApp.

WhatsApp does nothing on its own — it requires Rabbit (which itself requires
Unity for member data and Scrutiny for the GDPR audit log).

## Architecture

```
Unity ── Scrutiny ── Rabbit (contracts + MemberMessenger)
                     └── WhatsApp (this plugin: Cloud API driver)
```

WhatsApp boots on `rabbit/loaded` and registers three bindings into the shared
container: an `HttpTransportFactory`, an `HttpTransport`, and the
`MessageService` → `WhatsAppMessageService` driver. Settings are read at resolve
time, so a settings change takes effect on the next request.

## How it sends

```
POST {base}/{version}/{phone_number_id}/messages
Authorization: Bearer <access token>
Content-Type: application/json

{ "messaging_product":"whatsapp", "to":"447700900123",
  "type":"text", "text":{ "body":"…" } }
```

- **Success** (200): the `messages[0].id` (`wamid.…`) becomes the `MessageResult`'s
  message id.
- **Failure**: the Graph error envelope (`error.message` + `error.code`) is turned
  into a readable `MessagingException`.
- **Connection test**: `GET {base}/{version}/{phone_number_id}?fields=display_phone_number,verified_name`.

| Class | Responsibility |
|---|---|
| `Whatsapp\Messaging\WhatsAppMessageService` | The driver: I/O + policy. |
| `Whatsapp\Messaging\WhatsAppPayloadBuilder` | `Message` → Graph API JSON (pure). |
| `Whatsapp\Messaging\WhatsAppResponseParser` | Response → `MessageResult` / error (pure). |
| `Whatsapp\Admin\WhatsAppSettings` | Settings row; access token encrypted at rest. |
| `Whatsapp\Admin\SettingsPage` | Connection form + "send test" page. |

## Settings

Stored in the `whatsapp_settings` option (removed on uninstall). Fields: phone
number ID, access token (encrypted), business account ID, base URL
(`https://graph.facebook.com`), API version (`v23.0`), default template +
language, TLS verification, timeout.

The access token is encrypted with AES-256-GCM using a key derived from the
site's `AUTH_KEY`/`AUTH_SALT`, and is never logged.

## Usage

End users send via Rabbit's helper — WhatsApp is just the bound driver:

```php
rabbit()
    ->get(\Rabbit\Members\MemberMessenger::class)
    ->sendTextToMember(123, 'Your shift starts in 1 hour.');
```

Or use **WhatsApp → Send test** in wp-admin to message a member by ID (audited)
or a raw number (ad-hoc).

## Development

```bash
composer install
composer test    # PHPUnit unit tests (payload builder, response parser, driver)
composer phpstan # PHPStan
composer cs      # PHP_CodeSniffer (WordPress standard)
```

## License

MIT (Modified — No Resale). © The Bleeding Deacons.
