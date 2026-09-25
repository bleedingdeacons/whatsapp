# WhatsApp

[![CI](https://github.com/bleedingdeacons/whatsapp/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/bleedingdeacons/whatsapp/actions/workflows/ci.yml)
[![Semgrep](https://github.com/bleedingdeacons/whatsapp/actions/workflows/semgrep.yml/badge.svg?branch=main)](https://github.com/bleedingdeacons/whatsapp/actions/workflows/semgrep.yml)
[![Coverage Status](https://coveralls.io/repos/github/bleedingdeacons/whatsapp/badge.svg?branch=main)](https://coveralls.io/github/bleedingdeacons/whatsapp?branch=main)
![PHPStan](https://img.shields.io/badge/dynamic/yaml?url=https%3A%2F%2Fraw.githubusercontent.com%2Fbleedingdeacons%2Fwhatsapp%2Fmain%2Fphpstan.neon.dist&query=%24.parameters.level&label=PHPStan&prefix=level%20&color=brightgreen)
![PHPCS](https://img.shields.io/badge/dynamic/xml?url=https%3A%2F%2Fraw.githubusercontent.com%2Fbleedingdeacons%2Fwhatsapp%2Fmain%2F.phpcs.xml.dist&query=%2Fruleset%2Frule%5B1%5D%2F%40ref&label=PHPCS&color=brightgreen)
![Version](https://img.shields.io/badge/version-2.1.0-blue)
![PHP](https://img.shields.io/badge/php-8.1%2B-777bb4)
![Licence](https://img.shields.io/badge/licence-MIT%20(Modified)-green)

Outbound messaging to **Unity** members over the **WhatsApp Business Cloud API**
(Meta Graph API). It is built on the [Rabbit](https://github.com/bleedingdeacons/rabbit)
library — a Composer dependency, not a plugin — and implements Rabbit's
`MessageService` contract so Unity members can be messaged over WhatsApp.

WhatsApp requires **Unity** (member data) and **Scrutiny** (the GDPR audit log).

## Architecture

```
Unity (plugins_loaded) ──unity/loaded──▶ WhatsApp ──registers──▶ Unity's container ◀──get── any plugin on unity/loaded
```

- **Rabbit** is a library WhatsApp requires through Composer. It defines
  `MessageService`, the message models, the HTTP transport, the messaging roles
  and the `MemberMessenger` helper.
- **WhatsApp** boots on `unity/loaded` and registers four bindings into Unity's
  shared container: an `HttpTransportFactory`, an `HttpTransport`, the
  `MessageService` → `WhatsAppMessageService` driver, and Rabbit's
  `MemberMessenger`. Settings are read at resolve time, so a settings change
  takes effect on the next request.
- **WhatsApp owns what the Rabbit plugin used to**: it registers
  `MemberMessenger`, refuses to boot without Scrutiny, and registers the
  `rabbit_*` roles on activation, removes them on deactivation and uninstall,
  and re-registers them if they go missing.

Until Rabbit v2.1.0, Rabbit was a plugin that had to be active alongside
WhatsApp, and WhatsApp bound its driver on the `rabbit/loaded` action.

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

Other plugins send through Rabbit's `MemberMessenger`, which WhatsApp registers
in Unity's container:

```php
unity()
    ->get(\Rabbit\Members\MemberMessenger::class)
    ->sendTextToMember(123, 'Your shift starts in 1 hour.');
```

Or use **WhatsApp → Send test** in wp-admin to message a member by ID (audited)
or a raw number (ad-hoc).

## Capabilities

| Capability | Meaning |
|---|---|
| `rabbit_manage_messaging` | Configure the provider connection. |
| `rabbit_send_message` | Send messages to members. |
| `rabbit_view_messaging` | View messaging status / settings. |

Roles `rabbit_operator`, `rabbit_sender` and `rabbit_viewer` are created on
activation; administrators inherit all three capabilities. The names keep the
`rabbit_` prefix from when the Rabbit plugin owned them, so existing role
assignments carry over.

## Kill switch

Define `WHATSAPP_KILL` as `true` in `wp-config.php` to stand WhatsApp down
without deactivating it. Nothing is registered, so `MemberMessenger` is absent
from Unity's container. It replaces `RABBIT_KILL`.

## Development

Install the dev dependencies and run the suite from the plugin directory:

```bash
composer install
```

| Command | Description |
|---|---|
| `composer test` | Run the full Pest test suite (payload builder, response parser, driver) |
| `composer test:unit` | Run unit tests only |
| `composer test:integration` | Run integration tests only |
| `composer test:coverage` | Generate an HTML coverage report |
| `composer phpstan` | Run PHPStan static analysis |
| `composer phpcs` | Check coding standards |
| `composer phpcs:fix` | Auto-fix coding standard violations |
| `composer check` | Run CS + PHPStan + tests in sequence |

Line coverage is reported to [Coveralls](https://coveralls.io/github/bleedingdeacons/whatsapp?branch=main)
on every CI run — see the coverage badge at the top of this file.

The suite is written in [Pest](https://pestphp.com) (running on PHPUnit) and lives in
`tests/Unit/`. Run it through `composer test` or `vendor/bin/pest`, not PHPUnit directly —
`vendor/bin/phpunit` cannot load Pest's closure-based files.

## License

MIT (Modified — No Resale). © The Bleeding Deacons.
