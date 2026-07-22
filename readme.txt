=== WhatsApp ===
Contributors: thebleedingdeacons
Tags: messaging, whatsapp, cloud-api, members, notifications
Requires at least: 6.1
Tested up to: 6.9
Stable tag: 1.0.5
Build date: 2026/07/22 02:15:57
Requires PHP: 8.1
License: MIT (Modified — No Resale)

Rabbit driver for the WhatsApp Business Cloud API. Sends messages to Unity members via the Meta Graph API.

== Description ==

WhatsApp is an implementation plugin for **Rabbit**. It binds a concrete `MessageService` driver that talks to the **WhatsApp Business Cloud API** (Meta Graph API), so Rabbit's `MemberMessenger` can deliver text and template messages to Unity members over WhatsApp.

It does nothing on its own — it requires Rabbit (which in turn requires Unity and Scrutiny). Rabbit owns the contracts and the member/audit glue; WhatsApp just provides the provider integration and an admin UI for the connection and a send test.

**What it does:**

* Binds `Rabbit\Messaging\Interfaces\MessageService` to a Cloud API driver on the `rabbit/loaded` action.
* Sends free-form text and pre-approved template messages via `POST /<phone-number-id>/messages`.
* Verifies the connection from the admin "Save and test connection" button.
* Stores the access token encrypted at rest (AES-256-GCM, key derived from `AUTH_KEY`/`AUTH_SALT`).

== Installation ==

1. Upload the `whatsapp` directory to `/wp-content/plugins/`.
2. Activate WhatsApp through the **Plugins** menu (Unity, Scrutiny, and Rabbit must be active first).
3. Go to **WhatsApp → Settings** and enter your phone number ID and access token, then **Save and test connection**.
4. Use **WhatsApp → Send test** to send a message to a Unity member by ID.

== Frequently Asked Questions ==

= Where do I get the phone number ID and access token? =

From the Meta for Developers dashboard: WhatsApp → API Setup. Use a system-user or permanent token with the `whatsapp_business_messaging` permission.

= Why did my free-form text message fail? =

The Cloud API only allows free-form text inside an open 24-hour conversation window. To message a member outside that window, send an approved **template** instead.

= Is the access token stored safely? =

It is encrypted at rest with a key derived from your site's `AUTH_KEY`/`AUTH_SALT`. It is never written to logs.

== Changelog ==

= 1.0.0 =
* Initial release: WhatsApp Business Cloud API driver for Rabbit, with encrypted token storage, connection test, and a send-test admin page.
