<?php
if (!defined('ABSPATH')) {
    exit;
}

final class WPFI_Plugin {
    private static $instance;

    public static function instance() {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate() {
        if (!get_option(WPFI_Feed_Repository::OPTION)) {
            update_option(WPFI_Feed_Repository::OPTION, [], false);
        }

        flush_rewrite_rules();
    }

    public static function deactivate() {
        if (class_exists('WPFI_Scheduler')) {
            wp_clear_scheduled_hook(WPFI_Scheduler::HOOK);
        }

        flush_rewrite_rules();
    }

    public function boot() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error is-dismissible"><p><strong>WooCommerce XML Feed Importer:</strong> This plugin requires WooCommerce to be activated.</p></div>';
            });
            return false;
        }

        try {
            $repository = new WPFI_Feed_Repository();
            $logger = new WPFI_Logger();
            $importer = new WPFI_Importer($logger);
            $scheduler = new WPFI_Scheduler($repository, $importer);

            add_action('init', function () use ($scheduler) {
                if (wp_doing_cron()) {
                    $scheduler->sync();
                }
            }, 20);

            new WPFI_Admin($repository, $scheduler, $importer, $logger);
            return true;
        } catch (Exception $e) {
            error_log('WPFI Error: ' . $e->getMessage());

            add_action('admin_notices', function () use ($e) {
                echo '<div class="notice notice-error is-dismissible"><p><strong>WooCommerce XML Feed Importer Error:</strong> ' . esc_html($e->getMessage()) . '</p></div>';
            });
            return false;
        }
    }
}
