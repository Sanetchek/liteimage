<?php

/**
 * Logger class for LiteImage plugin
 *
 * @package LiteImage
 * @since 3.2.0
 */

namespace LiteImage\Support;

use LiteImage\Config;

defined('ABSPATH') || exit;

/**
 * Class Logger
 *
 * Handles logging functionality for the plugin
 */
class Logger
{
    /**
     * Log a message to file
     *
     * @param string|array $message Message to log
     * @return void
     */
    public static function log($message)
    {
        if (defined('LITEIMAGE_LOG_ACTIVE') && LITEIMAGE_LOG_ACTIVE) {
            $log_message = is_array($message) ? wp_json_encode($message) : $message;
            self::log_data([
                'message' => $log_message,
                'timestamp' => gmdate(Config::LOG_DATE_FORMAT)
            ]);
        }
    }

    /**
     * Get absolute log directory path under uploads
     *
     * @return string
     */
    public static function get_log_dir()
    {
        $upload_dir = wp_upload_dir();
        $basedir = isset($upload_dir['basedir']) ? rtrim($upload_dir['basedir'], '/\\') : '';
        return $basedir . '/liteimage-logs/';
    }

    /**
     * Write log data to file
     *
     * @param array $data Log data containing message and timestamp
     * @return void
     */
    private static function log_data($data)
    {
        // Use WordPress uploads directory for logs
        $log_dir = self::get_log_dir();

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

            // Add .htaccess to protect logs
            self::create_htaccess_protection($log_dir);
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

        // Clean old logs (older than 30 days)
        self::clean_old_logs($log_dir);
    }

    /**
     * Create .htaccess file to protect log directory
     *
     * @param string $log_dir Log directory path
     * @return void
     */
    private static function create_htaccess_protection($log_dir)
    {
        global $wp_filesystem;

        $htaccess_content = "# Deny access to log files\n";
        $htaccess_content .= "<Files ~ \"\\.log$\">\n";
        $htaccess_content .= "    Order allow,deny\n";
        $htaccess_content .= "    Deny from all\n";
        $htaccess_content .= "</Files>\n";

        $htaccess_file = $log_dir . '.htaccess';
        $wp_filesystem->put_contents($htaccess_file, $htaccess_content);
        $wp_filesystem->chmod($htaccess_file, 0644);
    }

    /**
     * Clean old log files (older than 30 days)
     *
     * @param string $log_dir Log directory path
     * @return void
     */
    private static function clean_old_logs($log_dir)
    {
        global $wp_filesystem;

        $files = $wp_filesystem->dirlist($log_dir);
        if (!$files) {
            return;
        }

        $current_time = time();
        $thirty_days_ago = $current_time - (30 * DAY_IN_SECONDS);

        foreach ($files as $file) {
            if (strpos($file['name'], 'log-') === 0 && strpos($file['name'], '.log') !== false) {
                $file_path = $log_dir . $file['name'];
                $file_time = $file['lastmodunix'];

                if ($file_time < $thirty_days_ago) {
                    $wp_filesystem->delete($file_path);
                }
            }
        }
    }
}

// Backward compatibility alias
class_alias('LiteImage\Support\Logger', 'LiteImage_Logger');
