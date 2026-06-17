<?php

declare(strict_types=1);

namespace Whatsapp\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Psr\Container\ContainerInterface;
use Rabbit\Members\MemberMessenger;
use Rabbit\Messaging\Interfaces\MessageService;
use Rabbit\Messaging\Models\Message;
use Rabbit\Messaging\Models\Recipient;

/**
 * WhatsApp's admin settings page.
 *
 * Two halves:
 *
 *  1. A form for the Cloud API connection — base URL, API version,
 *     phone number ID, business account ID, access token, default
 *     template/language, TLS verification, timeout. Submitting POSTs to
 *     `admin-post.php`; we save in `handleSave()` and redirect to avoid
 *     resubmit-on-reload.
 *
 *  2. A "send test" panel: pick a Unity member (by ID) or a raw number,
 *     type a short message, and dispatch it through Rabbit's
 *     {@see MemberMessenger} (member ID → audited) or the bound
 *     {@see MessageService} (raw number → not member-scoped).
 *
 * Capability checks use Rabbit's capabilities: view requires
 * `rabbit_view_messaging`, saving requires `rabbit_manage_messaging`,
 * and sending a test requires `rabbit_send_message`.
 */
final class SettingsPage
{
    use \Whatsapp\Logger\HasLogger;

    /** Log to the shared "whatsapp" channel so log lines name the plugin. */
    protected static function logChannel(): string
    {
        return 'whatsapp';
    }

    /** Top-level menu slug — a container only, with no page of its own. */
    private const MENU_SLUG = 'whatsapp';

    /** Distinct slugs per page — each must differ from MENU_SLUG to render as a child. */
    private const SETTINGS_SLUG = 'whatsapp-settings';
    private const SEND_SLUG = 'whatsapp-send-test';

    public function __construct(private ContainerInterface $container)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_post_whatsapp_save_settings', [$this, 'handleSave']);
        add_action('admin_post_whatsapp_test_connection', [$this, 'handleTest']);
        add_action('admin_post_whatsapp_send_test', [$this, 'handleSendTest']);
    }

    public function addMenu(): void
    {
        add_menu_page(
            __('WhatsApp — Messaging', 'whatsapp'),
            __('WhatsApp', 'whatsapp'),
            'rabbit_view_messaging',
            self::MENU_SLUG,
            '__return_null',
            'dashicons-format-chat',
            58
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('WhatsApp — Settings', 'whatsapp'),
            __('Settings', 'whatsapp'),
            'rabbit_view_messaging',
            self::SETTINGS_SLUG,
            [$this, 'renderSettings']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('WhatsApp — Send test', 'whatsapp'),
            __('Send test', 'whatsapp'),
            'rabbit_send_message',
            self::SEND_SLUG,
            [$this, 'renderSendTest']
        );

        // add_menu_page auto-creates a first submenu that duplicates the
        // parent label and points at the empty container page. Strip it
        // after every admin_menu callback has registered.
        add_action('admin_menu', [$this, 'removeDuplicateSubmenu'], 999);
    }

    /**
     * Remove the auto-generated first submenu whose slug equals the
     * parent menu slug, leaving just the real child pages.
     */
    public function removeDuplicateSubmenu(): void
    {
        global $submenu;

        if (empty($submenu[self::MENU_SLUG])) {
            return;
        }

        foreach ($submenu[self::MENU_SLUG] as $index => $item) {
            if (isset($item[2]) && $item[2] === self::MENU_SLUG) {
                unset($submenu[self::MENU_SLUG][$index]);
                break;
            }
        }
    }

    public function renderSettings(): void
    {
        if (!current_user_can('rabbit_view_messaging')) {
            wp_die(esc_html__('You do not have permission to view this page.', 'whatsapp'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('WhatsApp — Settings', 'whatsapp') . '</h1>';
        $this->renderFlashNotice();
        $this->renderSettingsForm(WhatsAppSettings::load());
        echo '</div>';
    }

    public function renderSendTest(): void
    {
        if (!current_user_can('rabbit_send_message')) {
            wp_die(esc_html__('You do not have permission to send messages.', 'whatsapp'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('WhatsApp — Send test', 'whatsapp') . '</h1>';
        $this->renderFlashNotice();
        $this->renderSendTestForm();
        echo '</div>';
    }

    private function renderFlashNotice(): void
    {
        $notice = $this->consumeFlash();
        if ($notice !== null) {
            echo '<div class="notice notice-' . esc_attr($notice['type']) . ' is-dismissible"><p>'
                . esc_html($notice['message']) . '</p></div>';
        }
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function renderSettingsForm(array $settings): void
    {
        $canEdit = current_user_can('rabbit_manage_messaging');
        $disabled = $canEdit ? '' : ' disabled';
        echo '<h2>' . esc_html__('Cloud API connection', 'whatsapp') . '</h2>';
        if (!$canEdit) {
            echo '<p><em>' . esc_html__('Read-only — your role can view but not change these settings.', 'whatsapp') . '</em></p>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="whatsapp_save_settings">';
        wp_nonce_field('whatsapp_save_settings');

        echo '<table class="form-table"><tbody>';

        echo '<tr><th><label for="wa-phone-number-id">' . esc_html__('Phone number ID', 'whatsapp') . '</label></th>';
        echo '<td><input id="wa-phone-number-id" name="phone_number_id" type="text" class="regular-text" value="' . esc_attr((string) $settings['phone_number_id']) . '"' . $disabled . '>';
        echo '<p class="description">' . esc_html__('Numeric ID from Meta → WhatsApp → API Setup (the sender phone number ID).', 'whatsapp') . '</p></td></tr>';

        echo '<tr><th><label for="wa-token">' . esc_html__('Access token', 'whatsapp') . '</label></th>';
        echo '<td><input id="wa-token" name="access_token" type="password" class="regular-text" autocomplete="new-password"' . $disabled . '>';
        if ((string) $settings['token_cipher'] !== '') {
            echo '<p class="description">' . esc_html__('A token is set (stored encrypted). Leave blank to keep it; type a new one to replace it.', 'whatsapp') . '</p>';
        } else {
            echo '<p class="description">' . esc_html__('System-user or permanent token with whatsapp_business_messaging permission.', 'whatsapp') . '</p>';
        }
        echo '</td></tr>';

        echo '<tr><th><label for="wa-waba-id">' . esc_html__('Business account ID', 'whatsapp') . '</label></th>';
        echo '<td><input id="wa-waba-id" name="business_account_id" type="text" class="regular-text" value="' . esc_attr((string) $settings['business_account_id']) . '"' . $disabled . '>';
        echo '<p class="description">' . esc_html__('Optional — your WhatsApp Business Account (WABA) ID, for reference.', 'whatsapp') . '</p></td></tr>';

        echo '<tr><th><label for="wa-base-url">' . esc_html__('API base URL', 'whatsapp') . '</label></th>';
        echo '<td><input id="wa-base-url" name="base_url" type="url" class="regular-text" value="' . esc_attr((string) $settings['base_url']) . '"' . $disabled . '>';
        echo '<p class="description">' . esc_html__('Default: https://graph.facebook.com — no trailing slash.', 'whatsapp') . '</p></td></tr>';

        echo '<tr><th><label for="wa-api-version">' . esc_html__('API version', 'whatsapp') . '</label></th>';
        echo '<td><input id="wa-api-version" name="api_version" type="text" class="regular-text" value="' . esc_attr((string) $settings['api_version']) . '"' . $disabled . '>';
        echo '<p class="description">' . esc_html__('Graph API version, e.g. v23.0.', 'whatsapp') . '</p></td></tr>';

        echo '<tr><th><label for="wa-default-template">' . esc_html__('Default template', 'whatsapp') . '</label></th>';
        echo '<td><input id="wa-default-template" name="default_template" type="text" class="regular-text" value="' . esc_attr((string) $settings['default_template']) . '"' . $disabled . '>';
        echo '<p class="description">' . esc_html__('Optional — a template name to offer by default on the send-test page.', 'whatsapp') . '</p></td></tr>';

        echo '<tr><th><label for="wa-default-language">' . esc_html__('Default template language', 'whatsapp') . '</label></th>';
        echo '<td><input id="wa-default-language" name="default_language" type="text" class="regular-text" value="' . esc_attr((string) $settings['default_language']) . '"' . $disabled . '>';
        echo '<p class="description">' . esc_html__('Language code for templates, e.g. en_GB.', 'whatsapp') . '</p></td></tr>';

        echo '<tr><th><label for="wa-verify-tls">' . esc_html__('Verify TLS certificate', 'whatsapp') . '</label></th>';
        echo '<td><label><input id="wa-verify-tls" name="verify_tls" type="checkbox" value="1"' . checked((bool) $settings['verify_tls'], true, false) . $disabled . '> ' . esc_html__('Recommended on; disable only for development.', 'whatsapp') . '</label></td></tr>';

        echo '<tr><th><label for="wa-timeout">' . esc_html__('Timeout (seconds)', 'whatsapp') . '</label></th>';
        echo '<td><input id="wa-timeout" name="timeout" type="number" min="1" max="120" value="' . esc_attr((string) $settings['timeout']) . '"' . $disabled . '></td></tr>';

        echo '</tbody></table>';

        if ($canEdit) {
            echo '<p class="submit">';
            echo '<button type="submit" name="submit" class="button button-primary">' . esc_html__('Save settings', 'whatsapp') . '</button> ';
            echo '<button type="submit" name="whatsapp_do_test" value="1" class="button">' . esc_html__('Save and test connection', 'whatsapp') . '</button>';
            echo '</p>';
        }
        echo '</form>';
    }

    private function renderSendTestForm(): void
    {
        if (!WhatsAppSettings::hasToken()) {
            echo '<div class="notice notice-warning"><p>'
                . esc_html__('No access token is configured yet. Set one under Settings before sending a test.', 'whatsapp')
                . '</p></div>';
        }

        echo '<p>' . esc_html__('Send a free-form text message to a Unity member (by ID) or to a raw number. Free-form text only reaches recipients inside an open 24-hour conversation window; outside it you must use an approved template.', 'whatsapp') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="whatsapp_send_test">';
        wp_nonce_field('whatsapp_send_test');

        echo '<table class="form-table"><tbody>';

        echo '<tr><th><label for="wa-test-member">' . esc_html__('Unity member ID', 'whatsapp') . '</label></th>';
        echo '<td><input id="wa-test-member" name="test_member_id" type="number" min="0" class="small-text" value="">';
        echo '<p class="description">' . esc_html__('The member to message. Their mobile number is read from Unity, and the send is recorded in the Scrutiny audit log.', 'whatsapp') . '</p></td></tr>';

        echo '<tr><th><label for="wa-test-phone">' . esc_html__('…or raw number', 'whatsapp') . '</label></th>';
        echo '<td><input id="wa-test-phone" name="test_phone" type="text" class="regular-text" value="" placeholder="+447700900123">';
        echo '<p class="description">' . esc_html__('Used only if no member ID is given. An ad-hoc send to a raw number is not member-scoped and is not audited against a member.', 'whatsapp') . '</p></td></tr>';

        echo '<tr><th><label for="wa-test-body">' . esc_html__('Message', 'whatsapp') . '</label></th>';
        echo '<td><textarea id="wa-test-body" name="test_body" rows="4" class="large-text"></textarea></td></tr>';

        echo '</tbody></table>';

        echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__('Send test message', 'whatsapp') . '</button></p>';
        echo '</form>';
    }

    public function handleSave(): void
    {
        if (!current_user_can('rabbit_manage_messaging')) {
            wp_die(esc_html__('You do not have permission to change WhatsApp settings.', 'whatsapp'));
        }
        check_admin_referer('whatsapp_save_settings');

        WhatsAppSettings::save($_POST);

        if (isset($_POST['whatsapp_do_test'])) {
            $this->runConnectionTest();
            $this->redirectTo(self::SETTINGS_SLUG);
            return;
        }

        $this->setFlash('success', __('WhatsApp settings saved.', 'whatsapp'));
        $this->redirectTo(self::SETTINGS_SLUG);
    }

    public function handleTest(): void
    {
        if (!current_user_can('rabbit_view_messaging')) {
            wp_die(esc_html__('You do not have permission to test the WhatsApp connection.', 'whatsapp'));
        }
        check_admin_referer('whatsapp_test_connection');

        $this->runConnectionTest();
        $this->redirectTo(self::SETTINGS_SLUG);
    }

    private function runConnectionTest(): void
    {
        try {
            /** @var MessageService $service */
            $service = $this->container->get(MessageService::class);
            $service->testConnection();
            $this->setFlash('success', __('Connection OK — WhatsApp reached the Cloud API and recognised the phone number ID.', 'whatsapp'));
        } catch (\Throwable $e) {
            $this->setFlash('error', __('Connection failed: ', 'whatsapp') . $e->getMessage());
        }
    }

    public function handleSendTest(): void
    {
        if (!current_user_can('rabbit_send_message')) {
            wp_die(esc_html__('You do not have permission to send messages.', 'whatsapp'));
        }
        check_admin_referer('whatsapp_send_test');

        $memberId = (int) ($_POST['test_member_id'] ?? 0);
        $phone = sanitize_text_field((string) ($_POST['test_phone'] ?? ''));
        $body = sanitize_textarea_field((string) ($_POST['test_body'] ?? ''));

        if (trim($body) === '') {
            $this->setFlash('error', __('Enter a message to send.', 'whatsapp'));
            $this->redirectTo(self::SEND_SLUG);
            return;
        }

        try {
            if ($memberId > 0) {
                // Member send — goes through MemberMessenger so it is
                // audited in Scrutiny (action "message").
                /** @var MemberMessenger $rabbit */
                $rabbit = $this->container->get(MemberMessenger::class);
                $result = $rabbit->sendTextToMember($memberId, $body);
                $this->setFlash(
                    'success',
                    sprintf(
                        /* translators: 1: member ID, 2: provider message id */
                        __('Message sent to member %1$d. Provider message id: %2$s', 'whatsapp'),
                        $memberId,
                        $result->getMessageId()
                    )
                );
            } elseif ($phone !== '') {
                // Ad-hoc raw-number send — bypasses member resolution and
                // is not member-scoped in the audit log.
                /** @var MessageService $service */
                $service = $this->container->get(MessageService::class);
                $result = $service->send(Message::text(Recipient::to($phone), $body));
                $this->setFlash(
                    'success',
                    sprintf(
                        /* translators: %s: provider message id */
                        __('Message sent. Provider message id: %s', 'whatsapp'),
                        $result->getMessageId()
                    )
                );
            } else {
                $this->setFlash('error', __('Provide a Unity member ID or a raw number.', 'whatsapp'));
            }
        } catch (\Throwable $e) {
            $this->setFlash('error', __('Send failed: ', 'whatsapp') . $e->getMessage());
        }

        $this->redirectTo(self::SEND_SLUG);
    }

    /**
     * Flash messages travel through a transient keyed by user ID —
     * survives the post-redirect-get without leaking between admins.
     */
    private function setFlash(string $type, string $message): void
    {
        set_transient($this->flashKey(), ['type' => $type, 'message' => $message], 30);
    }

    /**
     * @return array{type:string,message:string}|null
     */
    private function consumeFlash(): ?array
    {
        $flash = get_transient($this->flashKey());
        if (!is_array($flash) || !isset($flash['type'], $flash['message'])) {
            return null;
        }
        delete_transient($this->flashKey());
        return ['type' => (string) $flash['type'], 'message' => (string) $flash['message']];
    }

    private function flashKey(): string
    {
        return 'whatsapp_flash_' . get_current_user_id();
    }

    private function redirectTo(string $slug): void
    {
        wp_safe_redirect(admin_url('admin.php?page=' . $slug));
        exit;
    }
}
