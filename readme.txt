=== WhatsApp ===
Contributors: thebleedingdeacons
Tags: messaging, whatsapp, cloud-api, members, notifications
Requires at least: 6.1
Tested up to: 7.1.1
Stable tag: 2.0.4
Build date: 2026/09/25 21:10:53
Requires PHP: 8.4
License: MIT (Modified — No Resale)

Outbound messaging to Unity members over the WhatsApp Business Cloud API (Meta Graph API), built on the Rabbit library it bundles.

== Description ==

WhatsApp is the member-messaging plugin, built on the **Rabbit** library it bundles. It binds a concrete `MessageService` driver that talks to the **WhatsApp Business Cloud API** (Meta Graph API), so Rabbit's `MemberMessenger` can deliver text and template messages to Unity members over WhatsApp.

It requires Unity (member data) and Scrutiny (the GDPR audit log). Rabbit supplies the contracts and the member/audit glue; WhatsApp wires them into Unity's container, owns the messaging roles, and provides the provider integration and an admin UI for the connection and a send test.

**What it does:**

* Binds `Rabbit\Messaging\Interfaces\MessageService` to a Cloud API driver, and registers Rabbit's `MemberMessenger`, in Unity's container on the `unity/loaded` action.
* Sends free-form text and pre-approved template messages via `POST /<phone-number-id>/messages`.
* Verifies the connection from the admin "Save and test connection" button.
* Stores the access token encrypted at rest (AES-256-GCM, key derived from `AUTH_KEY`/`AUTH_SALT`).

== Installation ==

1. Upload the `whatsapp` directory to `/wp-content/plugins/`.
2. Activate WhatsApp through the **Plugins** menu (Unity and Scrutiny must be active first). This creates the Messaging Operator, Sender and Viewer roles.
3. Go to **WhatsApp → Settings** and enter your phone number ID and access token, then **Save and test connection**.
4. Use **WhatsApp → Send test** to send a message to a Unity member by ID.

== Frequently Asked Questions ==

= Where do I get the phone number ID and access token? =

From the Meta for Developers dashboard: WhatsApp → API Setup. Use a system-user or permanent token with the `whatsapp_business_messaging` permission.

= Why did my free-form text message fail? =

The Cloud API only allows free-form text inside an open 24-hour conversation window. To message a member outside that window, send an approved **template** instead.

= Is the access token stored safely? =

It is encrypted at rest with a key derived from your site's `AUTH_KEY`/`AUTH_SALT`. It is never written to logs.

= Do I still need the Rabbit plugin? =

No. Rabbit is now a library bundled inside WhatsApp. If the old Rabbit plugin is still installed, deactivate and delete it; WhatsApp puts back the messaging roles that Rabbit's deactivation removes.

= How do I disable WhatsApp without deactivating it? =

Define `WHATSAPP_KILL` as `true` in `wp-config.php`. WhatsApp short-circuits before registering anything, so `MemberMessenger` is absent from Unity's container. It replaces `RABBIT_KILL`.

== Changelog ==

= 1.0.0 =
* Initial release: WhatsApp Business Cloud API driver for Rabbit, with encrypted token storage, connection test, and a send-test admin page.
