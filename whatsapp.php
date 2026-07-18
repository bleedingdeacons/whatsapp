<?php

declare(strict_types=1);

/**
 * Plugin Name: WhatsApp
 * Description: Rabbit driver for the WhatsApp Business Cloud API (Meta Graph API). Implements Rabbit's MessageService contract by posting to /<phone-number-id>/messages with a bearer token. Requires the Rabbit plugin to be installed and active.
 * Version: 1.0.1
 * Requires at least: 6.1
 * Requires PHP: 8.1
 * Requires Plugins: rabbit
 * GitHub Plugin URI: https://github.com/thebleedingdeacons/whatsapp
 * GitHub Branch: main
 * Author: The Bleeding Deacons
 * Author URI: https://github.com/bleedingdeacons/whatsapp
 * Contact: thebleedingdeacons@gmail.com
 * License: MIT (Modified)
 * Text Domain: whatsapp
 */

if (!defined('ABSPATH')) {
    exit;
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

// Load Composer autoloader if present.
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

// WhatsApp binds its driver on `rabbit/loaded`, which fires during
// `unity/loaded` (Rabbit boots on Unity). We hook there to register
// against whichever container Rabbit ended up using (Unity's shared
// container).
add_action('rabbit/loaded', function ($container) {
    try {
        if (!class_exists('Whatsapp\\Plugin')) {
            throw new \Exception('Whatsapp\\Plugin class not found. Check that Plugin.php exists in the src/ directory.');
        }

        \Whatsapp\Plugin::init($container);

        /**
         * Fires after WhatsApp has bound its concrete driver against
         * Rabbit's MessageService contract.
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
});

// Surface a notice if Rabbit never loaded (e.g. it isn't installed or
// active, or one of its own dependencies — Unity/Scrutiny — is missing).
// Rabbit fires `rabbit/loaded` from `unity/loaded`, so by
// `admin_init` we know whether it ran. If it didn't, WhatsApp can't have
// bound anything.
add_action('admin_init', function () {
    if (did_action('rabbit/loaded')) {
        return;
    }
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error is-dismissible"><p>'
            . '<strong>WhatsApp Plugin Error:</strong> '
            . esc_html__('WhatsApp requires the Rabbit plugin (and its dependencies Unity and Scrutiny) to be installed and activated.', 'whatsapp')
            . '</p></div>';
    });
});
