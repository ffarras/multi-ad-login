<?php
// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

class MADL_Logger
{

    /**
     * Log a message.
     *
     * @param string $message The message to log.
     * @param string $level   The log level (INFO, DEBUG, WARNING, ERROR).
     */
    public static function log($message, $level = 'INFO')
    {
        if (! defined('MADL_ENABLE_LOGGING') || ! MADL_ENABLE_LOGGING) {
            // For production, you might want to disable logging by default
            // and enable it via a constant in wp-config.php: define('MADL_ENABLE_LOGGING', true);
            // For this example, we'll log if WP_DEBUG_LOG is on or if MADL_ENABLE_LOGGING is explicitly true.
            if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
                if (! (defined('MADL_FORCE_LOGGING') && MADL_FORCE_LOGGING)) { // Add another constant to force logging
                    return;
                }
            }
        }

        $timestamp = current_time('mysql');
        $formatted_message = sprintf("[%s] [%s]: %s\n", $timestamp, strtoupper($level), print_r($message, true));

        // Log to dedicated plugin log file
        if (defined('MADL_LOG_FILE') && is_string(MADL_LOG_FILE)) {
            // Ensure directory exists or is writable - basic check
            $log_dir = dirname(MADL_LOG_FILE);
            if (!is_dir($log_dir)) {
                // Try to create it, suppress errors if it fails
                @mkdir($log_dir, 0755, true);
            }

            if (is_writable($log_dir) || is_writable(MADL_LOG_FILE)) {
                error_log($formatted_message, 3, MADL_LOG_FILE);
            } else {
                // Fallback if dedicated log file isn't writable
                if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG && defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("MADL Logger (Fallback): " . $formatted_message);
                }
            }
        } elseif (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG && defined('WP_DEBUG') && WP_DEBUG) {
            // Fallback to WordPress debug.log if WP_DEBUG_LOG is true
            error_log("MADL Logger: " . $formatted_message);
        }
    }
}
