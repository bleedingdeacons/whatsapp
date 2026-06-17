<?php

declare(strict_types=1);

namespace Whatsapp\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read/write access to WhatsApp's settings row.
 *
 * Settings live under a single wp_options key (defined as
 * `WHATSAPP_OPTION_KEY` in the bootstrap) so the whole config can be
 * backed up, exported, or rotated as a unit.
 *
 * The Cloud API access token is encrypted at rest with a key derived
 * from WordPress's `AUTH_KEY` / `AUTH_SALT`. This isn't a security
 * boundary against someone with full DB + filesystem access (they
 * already have AUTH_KEY), but it does mean a casual `SELECT * FROM
 * wp_options` from a read-only audit account doesn't leak the token in
 * cleartext — which is the realistic threat. A WhatsApp token is a
 * bearer credential: anyone holding it can send messages as the
 * business, so it deserves the same care as a password.
 *
 * If `AUTH_KEY` isn't usable (some odd test setups), we fall back to a
 * base64 sentinel (`plain:`) with a logged warning. The plugin still
 * works; the token is just stored as base64 of plaintext rather than as
 * a real ciphertext. Better to keep working than to fail closed when the
 * operator may not have any way to fix it.
 */
final class WhatsAppSettings
{
    /** GCM nonce length in bytes (96-bit, the AES-GCM standard). */
    private const GCM_IV_LEN = 12;

    /** GCM authentication tag length in bytes (128-bit). */
    private const GCM_TAG_LEN = 16;

    /**
     * Return a fully-populated settings array. Missing keys are filled in
     * with safe defaults so callers can rely on the shape.
     *
     * @return array{
     *   base_url:string,
     *   api_version:string,
     *   phone_number_id:string,
     *   business_account_id:string,
     *   token_cipher:string,
     *   default_template:string,
     *   default_language:string,
     *   verify_tls:bool,
     *   timeout:int
     * }
     */
    public static function load(): array
    {
        $raw = get_option(WHATSAPP_OPTION_KEY, []);
        if (!is_array($raw)) {
            $raw = [];
        }
        return [
            'base_url' => (string) ($raw['base_url'] ?? 'https://graph.facebook.com'),
            'api_version' => (string) ($raw['api_version'] ?? 'v23.0'),
            'phone_number_id' => (string) ($raw['phone_number_id'] ?? ''),
            'business_account_id' => (string) ($raw['business_account_id'] ?? ''),
            'token_cipher' => (string) ($raw['token_cipher'] ?? ''),
            'default_template' => (string) ($raw['default_template'] ?? ''),
            'default_language' => (string) ($raw['default_language'] ?? 'en_GB'),
            'verify_tls' => (bool) ($raw['verify_tls'] ?? true),
            'timeout' => max(1, (int) ($raw['timeout'] ?? 15)),
        ];
    }

    /**
     * Persist a settings array. If `access_token` is non-empty it's
     * encrypted and stored as `token_cipher`; an empty token means
     * "leave the existing cipher alone" so the admin page can submit
     * without re-typing the token every time.
     *
     * @param array<string,mixed> $input Raw $_POST-shaped data.
     */
    public static function save(array $input): void
    {
        $existing = self::load();

        $cipher = $existing['token_cipher'];
        $plaintext = trim((string) ($input['access_token'] ?? ''));
        if ($plaintext !== '') {
            $cipher = self::encrypt($plaintext);
        }

        $next = [
            'base_url' => self::sanitiseBaseUrl((string) ($input['base_url'] ?? $existing['base_url'])),
            'api_version' => self::sanitiseApiVersion((string) ($input['api_version'] ?? $existing['api_version'])),
            // The phone-number ID and WABA ID are numeric IDs from the
            // Meta dashboard. Strip anything non-digit so a pasted value
            // with stray spaces can't break the request URL.
            'phone_number_id' => preg_replace('/\D+/', '', (string) ($input['phone_number_id'] ?? $existing['phone_number_id'])) ?? '',
            'business_account_id' => preg_replace('/\D+/', '', (string) ($input['business_account_id'] ?? $existing['business_account_id'])) ?? '',
            'token_cipher' => $cipher,
            'default_template' => sanitize_text_field((string) ($input['default_template'] ?? $existing['default_template'])),
            'default_language' => sanitize_text_field((string) ($input['default_language'] ?? $existing['default_language'])),
            'verify_tls' => !empty($input['verify_tls']),
            'timeout' => max(1, min(120, (int) ($input['timeout'] ?? $existing['timeout']))),
        ];

        update_option(WHATSAPP_OPTION_KEY, $next);
    }

    /**
     * Decrypt the stored access token and return the plaintext.
     *
     * Returns '' if no token has been set yet (a fresh install).
     */
    public static function token(): string
    {
        $cipher = self::load()['token_cipher'];
        if ($cipher === '') {
            return '';
        }
        return self::decrypt($cipher);
    }

    /**
     * Whether an access token has been configured (without decrypting it).
     */
    public static function hasToken(): bool
    {
        return self::load()['token_cipher'] !== '';
    }

    /**
     * Normalise the API base URL: trim, strip a trailing slash, default
     * to the Meta Graph host if blank.
     */
    private static function sanitiseBaseUrl(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 'https://graph.facebook.com';
        }
        return rtrim(esc_url_raw($raw), '/');
    }

    /**
     * Normalise the API version. Meta uses a `vNN.N` form (e.g. v23.0).
     * We accept what the operator typed but trim it and ensure a leading
     * `v`, defaulting when blank.
     */
    private static function sanitiseApiVersion(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 'v23.0';
        }
        // Strip anything that isn't a version-ish character.
        $raw = preg_replace('/[^v0-9.]/i', '', $raw) ?? '';
        if ($raw === '') {
            return 'v23.0';
        }
        if ($raw[0] !== 'v' && $raw[0] !== 'V') {
            $raw = 'v' . $raw;
        }
        return strtolower($raw);
    }

    /**
     * Authenticated encryption: AES-256-GCM with a key derived from
     * AUTH_KEY + AUTH_SALT. Returns `gcm:base64(iv || tag || ct)`.
     *
     * GCM gives us integrity as well as confidentiality — a tampered
     * ciphertext fails to decrypt instead of silently yielding garbage
     * we'd then send as a bearer token. The 96-bit IV is the GCM
     * standard; the 128-bit auth tag is appended so decrypt() can verify.
     *
     * Falls back to a base64 sentinel (`plain:`) if OpenSSL is missing or
     * the key can't be derived — extremely rare on a modern WP host, but
     * the fallback keeps the plugin working rather than fataling.
     */
    private static function encrypt(string $plaintext): string
    {
        $key = self::deriveKey();
        if ($key === null || !function_exists('openssl_encrypt')) {
            return 'plain:' . base64_encode($plaintext);
        }
        $iv = random_bytes(self::GCM_IV_LEN);
        $tag = '';
        $ct = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::GCM_TAG_LEN
        );
        if ($ct === false || strlen($tag) !== self::GCM_TAG_LEN) {
            \Whatsapp\Plugin::logWarning('openssl_encrypt failed; storing access token unencrypted.');
            return 'plain:' . base64_encode($plaintext);
        }
        return 'gcm:' . base64_encode($iv . $tag . $ct);
    }

    private static function decrypt(string $cipher): string
    {
        if (str_starts_with($cipher, 'plain:')) {
            $b64 = substr($cipher, strlen('plain:'));
            return (string) base64_decode($b64, true);
        }
        if (str_starts_with($cipher, 'gcm:')) {
            return self::decryptGcm(substr($cipher, strlen('gcm:')));
        }
        // Unrecognised — return empty rather than guessing. The operator
        // will need to re-enter the token.
        return '';
    }

    private static function decryptGcm(string $b64): string
    {
        $key = self::deriveKey();
        if ($key === null || !function_exists('openssl_decrypt')) {
            return '';
        }
        $blob = base64_decode($b64, true);
        if (!is_string($blob) || strlen($blob) < self::GCM_IV_LEN + self::GCM_TAG_LEN) {
            return '';
        }
        $iv = substr($blob, 0, self::GCM_IV_LEN);
        $tag = substr($blob, self::GCM_IV_LEN, self::GCM_TAG_LEN);
        $ct = substr($blob, self::GCM_IV_LEN + self::GCM_TAG_LEN);
        $pt = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $pt === false ? '' : $pt;
    }

    /**
     * Derive the 32-byte key from AUTH_KEY + AUTH_SALT (with domain
     * separation so it can't collide with any other use of those
     * constants). Returns null if AUTH_KEY isn't usable.
     */
    private static function deriveKey(): ?string
    {
        if (!self::authKeyUsable()) {
            return null;
        }
        $salt = (defined('AUTH_SALT') && is_string(AUTH_SALT)) ? AUTH_SALT : '';
        // HMAC output is 32 bytes — exactly an AES-256 key.
        return hash_hmac('sha256', 'whatsapp/token/v1', (string) AUTH_KEY . $salt, true);
    }

    private static function authKeyUsable(): bool
    {
        return defined('AUTH_KEY') && AUTH_KEY !== '' && AUTH_KEY !== 'put your unique phrase here';
    }
}
