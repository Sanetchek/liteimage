<?php
defined('ABSPATH') || exit;

class LiteImage_Logger {
    public static function log($message) {
        if (defined('LITEIMAGE_LOG_ACTIVE') && LITEIMAGE_LOG_ACTIVE) {
            $log_message = is_array($message) ? wp_json_encode($message) : $message;
            self::log_data([
                'message' => $log_message,
                'timestamp' => gmdate('Y-m-d H:i:s')
            ]);
        }
    }

    private static function log_data($data) {
        $log_dir = LITEIMAGE_DIR . 'logs/';


        // Load WordPress filesystem
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();
        global $wp_filesystem;

        // Create logs directory if needed
        if (!$wp_filesystem->is_dir($log_dir)) {
            $wp_filesystem->mkdir($log_dir);
            $wp_filesystem->chmod($log_dir, 0755);
        }

        $log_file = $log_dir . 'log-' . gmdate('Y-m-d') . '.log';
        $log_entry = '[' . $data['timestamp'] . '] ' . $data['message'] . PHP_EOL;

        // Read existing log content (if any)
        $existing_content = '';
        if ($wp_filesystem->exists($log_file)) {
            $existing_content = $wp_filesystem->get_contents($log_file);
        }

        // Append new log entry
        $new_content = $existing_content . $log_entry;
        $wp_filesystem->put_contents($log_file, $new_content);
        $wp_filesystem->chmod($log_file, 0644);
    }
}