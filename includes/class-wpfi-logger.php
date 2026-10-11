<?php
if (!defined('ABSPATH')) {
    exit;
}

final class WPFI_Logger {
    const OPTION = 'wpfi_import_logs';
    const LIMIT = 500;

    public function write($feed, $level, $message, array $context = []) {
        $level = sanitize_key($level);
        $message = wp_strip_all_tags((string) $message);

        if (empty($message)) {
            return;
        }

        $logs = get_option(self::OPTION, []);
        if (!is_array($logs)) {
            $logs = [];
        }

        $entry = [
            'time' => current_time('mysql'),
            'feed' => isset($feed['name']) ? sanitize_text_field($feed['name']) : 'Unknown',
            'slug' => isset($feed['slug']) ? sanitize_text_field($feed['slug']) : 'unknown',
            'level' => $level,
            'message' => $message,
            'context' => array_map('sanitize_text_field', $context),
        ];

        array_unshift($logs, $entry);
        $logs = array_slice($logs, 0, self::LIMIT);
        update_option(self::OPTION, $logs, false);

        if (function_exists('wc_get_logger')) {
            try {
                $logger = wc_get_logger();
                $logger->log($level, $message, ['source' => 'wpfi-' . ($feed['slug'] ?? 'importer')]);
            } catch (Exception $e) {
                // swallow logger issues
            }
        }
    }

    public function all($limit = 200) {
        $logs = get_option(self::OPTION, []);
        if (!is_array($logs)) {
            return [];
        }

        return array_slice($logs, 0, absint($limit));
    }

    public function clear() {
        delete_option(self::OPTION);
    }
}
