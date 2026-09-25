<?php

/**
 * Plugin Name: WhatsApp
 * Description: Outbound messaging to Unity members over the WhatsApp Business Cloud API (Meta Graph API). Implements the Rabbit library's MessageService contract by posting to /<phone-number-id>/messages with a bearer token, and provides the messaging roles and the MemberMessenger helper. Requires Unity for member data and Scrutiny for GDPR audit logging.
 * Version: 2.1.0
 * Requires at least: 6.1
 * Requires PHP: 8.4
 * Requires Plugins: unity, scrutiny
 * GitHub Plugin URI: https://github.com/bleedingdeacons/whatsapp
 * GitHub Branch: main
 * Author: The Bleeding Deacons
 * Author URI: https://github.com/bleedingdeacons/whatsapp
 * Contact: thebleedingdeacons@gmail.com
 * License: MIT (Modified)
 * Text Domain: whatsapp
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Set `define('WHATSAPP_KILL', true);` in wp-config.php to stand WhatsApp
// down without deactivating it. Nothing is registered, so MemberMessenger is
// absent from Unity's container. Replaces RABBIT_KILL, which did the same job
// when Rabbit was a plugin.
if (defined('WHATSAPP_KILL') && WHATSAPP_KILL === true) {
    if (is_admin()) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-warning"><p>'
                . '<strong>WhatsApp:</strong> Plugin is disabled via the '
                . '<code>WHATSAPP_KILL</code> kill switch in <code>wp-config.php</code>.'
                . '</p></div>';
        });
    }
    return;
}

// Define plugin constants
if (!function_exists('get_plugin_data')) {
    if (file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
}

$whatsapp_plugin_data = get_plugin_data(__FILE__, false, false);
define('WHATSAPP_VERSION', $whatsapp_plugin_data['Version']);
define('WHATSAPP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WHATSAPP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WHATSAPP_PLUGIN_FILE', __FILE__);

// Single wp_options key that holds the whole settings row. Must match
// the key deleted in uninstall.php ('whatsapp_settings').
define('WHATSAPP_OPTION_KEY', 'whatsapp_settings');

// Load Composer autoloader if present. It also supplies the Rabbit library.
$whatsapp_autoloader = WHATSAPP_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($whatsapp_autoloader)) {
    require_once $whatsapp_autoloader;
}

// Fallback PSR-4 autoloader for the Whatsapp namespace. Lets the plugin
// run on a fresh deployment before `composer install` has been executed.
spl_autoload_register(function ($class) {
    $prefix = 'Whatsapp\\';
    $base_dir = WHATSAPP_PLUGIN_DIR . 'src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// The messaging roles belonged to the Rabbit plugin until Rabbit became a
// library. WhatsApp is now the plugin that owns them.
register_activation_hook(__FILE__, [\Rabbit\Capabilities\CapabilityBootstrap::class, 'register']);
register_deactivation_hook(__FILE__, [\Rabbit\Capabilities\CapabilityBootstrap::class, 'remove']);

// The old Rabbit plugin strips these roles when it is deactivated, and an
// in-place upgrade never fires the activation hook, so put them back whenever
// they are missing rather than only on activation.
//
// The role is named as a literal, not CapabilityBootstrap::ROLE_OPERATOR, so
// that this check does not autoload the class on every request. The old Rabbit
// plugin's deactivation hook `require_once`s its own copy of that file; had
// this already loaded WhatsApp's vendored copy, deactivating Rabbit would die
// with "Cannot declare class", which is exactly what removing the old Beacon
// plugin did once Tamar bundled it.
add_action('init', function () {
    if (get_role('rabbit_operator') === null) {
        \Rabbit\Capabilities\CapabilityBootstrap::register();
    }
});

// Boot after Unity is loaded. Unity fires `unity/loaded` from `plugins_loaded`
// with its shared container; WhatsApp registers its driver and the Rabbit
// library's MemberMessenger into that container, so any plugin booting on the
// same action can resolve MemberMessenger from it.
add_action('unity/loaded', function ($container) {
    try {
        // Scrutiny provides the AuditLogger that records one audit entry per
        // message sent to a member (action: "message"). Sending a message
        // reads a member's mobile number — personal data — so refuse to run
        // without the audit trail rather than silently lose it.
        if (!function_exists('scrutiny')) {
            throw new \Exception('Scrutiny plugin is required but not active. Please install and activate Scrutiny before using WhatsApp (it provides the GDPR audit log).');
        }

        if (!class_exists('Whatsapp\\Plugin')) {
            throw new \Exception('Whatsapp\\Plugin class not found. Check that Plugin.php exists in the src/ directory.');
        }

        \Whatsapp\Plugin::init($container);

        /**
         * Fires after WhatsApp has registered its driver and MemberMessenger
         * into Unity's container.
         *
         * @param \Psr\Container\ContainerInterface $container The shared dependency container
         */
        do_action('whatsapp/loaded', \Whatsapp\Plugin::getContainer());
    } catch (\Exception $e) {
        function_exists('wp_log')
            ? wp_log('whatsapp')->error('WhatsApp Plugin Initialisation Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('WhatsApp Plugin Initialisation Error: ' . $e->getMessage());

        if (is_admin()) {
            add_action('admin_notices', function () use ($e) {
                echo '<div class="notice notice-error is-dismissible"><p><strong>WhatsApp Plugin Error:</strong> ' . esc_html($e->getMessage()) . '</p></div>';
            });
        }
    } catch (\Throwable $e) {
        function_exists('wp_log')
            ? wp_log('whatsapp')->critical('WhatsApp Plugin Fatal Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('WhatsApp Plugin Fatal Error: ' . $e->getMessage());
    }
}, 10);

// Surface a notice if a required plugin is not available, so an operator
// isn't left guessing why WhatsApp did nothing.
add_action('plugins_loaded', function () {
    if (!class_exists('Unity\\Plugin')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>' . esc_html__('WhatsApp', 'whatsapp') . ':</strong> ';
            echo esc_html__('This plugin requires the Unity plugin to be installed and activated.', 'whatsapp');
            echo '</p></div>';
        });
    } elseif (!function_exists('scrutiny')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>' . esc_html__('WhatsApp', 'whatsapp') . ':</strong> ';
            echo esc_html__('This plugin requires the Scrutiny plugin to be installed and activated for GDPR audit logging.', 'whatsapp');
            echo '</p></div>';
        });
    }
}, 20);
