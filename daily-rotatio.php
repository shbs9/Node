<?php
/**
 * Plugin Name: Daily Salt Rotation (MU)
 * Description: Rotates WordPress salts daily at midnight via WP-Cron for compliance requirements
 * Version: 1.0.1
 * Author: Custom Development
 * Requires: WP-CLI installed and shell_exec() enabled
 */

if (!defined('ABSPATH')) {
    exit;
}

class Daily_Salt_Rotation {

    const CRON_HOOK = 'daily_salt_rotation_event';
    const LOG_TABLE_SUFFIX = 'salt_rotation_log';
    const BACKUP_OPTION_PREFIX = 'salt_rotation_backup_';
    const BACKUPS_TO_KEEP = 5;
    const ADMIN_NOTICE_OPTION = 'salt_rotation_admin_notice_dismissed';

    const SALT_KEYS = [
        'AUTH_KEY',
        'SECURE_AUTH_KEY',
        'LOGGED_IN_KEY',
        'NONCE_KEY',
        'AUTH_SALT',
        'SECURE_AUTH_SALT',
        'LOGGED_IN_SALT',
        'NONCE_SALT'
    ];

    public static function init() {
        add_action('plugins_loaded', [__CLASS__, 'create_log_table_if_needed']);
        add_action('plugins_loaded', [__CLASS__, 'schedule_rotation']);
        add_action(self::CRON_HOOK, [__CLASS__, 'execute_rotation']);
        add_action('init', [__CLASS__, 'check_if_overdue']);
        add_action('admin_notices', [__CLASS__, 'admin_notices']);
        add_action('wp_ajax_dismiss_salt_rotation_notice', [__CLASS__, 'dismiss_admin_notice']);
    }

    public static function create_log_table_if_needed() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::LOG_TABLE_SUFFIX;

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE $table_name (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            rotation_date DATETIME NOT NULL,
            status ENUM('success','failure') NOT NULL,
            wpcli_output TEXT,
            error_message TEXT,
            backup_option_id VARCHAR(100),
            execution_time FLOAT,
            triggered_by VARCHAR(50),
            INDEX idx_rotation_date (rotation_date),
            INDEX idx_status (status)
        ) {$wpdb->get_charset_collate()};";

        dbDelta($sql);
    }

    public static function schedule_rotation() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(strtotime('tomorrow midnight'), 'daily', self::CRON_HOOK);
        }
    }

    public static function check_if_overdue() {
        if (is_admin() || defined('DOING_AJAX')) {
            return;
        }

        if (defined('SALT_ROTATION_DISABLED') && SALT_ROTATION_DISABLED === true) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE_SUFFIX;

        $last = $wpdb->get_var("SELECT rotation_date FROM $table WHERE status='success' ORDER BY rotation_date DESC LIMIT 1");

        if (!$last || strtotime($last) < strtotime('-25 hours')) {
            self::execute_rotation('overdue-fallback');
        }
    }

    public static function execute_rotation($triggered_by = 'wp-cron') {
        $start = microtime(true);

        if (defined('SALT_ROTATION_DISABLED') && SALT_ROTATION_DISABLED === true) {
            return;
        }

        $backup_id = self::backup_current_salts();
        if (!$backup_id) {
            self::log_rotation_event('failure', '', 'Backup failed', null, 0, $triggered_by);
            return;
        }

        $result = self::execute_wpcli_command();
        $time = microtime(true) - $start;

        if ($result['success']) {
            self::log_rotation_event('success', $result['output'], '', $backup_id, $time, $triggered_by);
            self::cleanup_old_backups();
        } else {
            self::log_rotation_event('failure', $result['output'], $result['error'], $backup_id, $time, $triggered_by);
            self::send_failure_notification($result['error'], $result['output']);
        }
    }

    private static function backup_current_salts() {
        $paths = [
            dirname(ABSPATH) . '/wp-config.php',
            ABSPATH . 'wp-config.php'
        ];

        $file = null;
        foreach ($paths as $path) {
            if (is_readable($path)) {
                $file = $path;
                break;
            }
        }

        if (!$file) {
            return false;
        }

        $content = file_get_contents($file);
        $backup = [];

        foreach (self::SALT_KEYS as $key) {
            if (preg_match("/define\s*\(\s*['\"]{$key}['\"]\s*,\s*['\"](.+?)['\"]\s*\)/", $content, $m)) {
                $backup[$key] = $m[1];
            }
        }

        $option = self::BACKUP_OPTION_PREFIX . time();

        add_option($option, [
            'salts' => $backup,
            'date'  => current_time('mysql')
        ], '', 'no');

        return $option;
    }

    private static function cleanup_old_backups() {
        global $wpdb;

        $keep = defined('SALT_ROTATION_BACKUP_KEEP')
            ? (int) SALT_ROTATION_BACKUP_KEEP
            : self::BACKUPS_TO_KEEP;

        $options = $wpdb->get_col(
            "SELECT option_name FROM $wpdb->options 
             WHERE option_name LIKE 'salt_rotation_backup_%'
             ORDER BY option_name DESC"
        );

        foreach (array_slice($options, $keep) as $old) {
            delete_option($old);
        }
    }

    private static function get_wpcli_path() {
        if (defined('WPCLI_PATH') && file_exists(WPCLI_PATH)) {
            return WPCLI_PATH;
        }

        if (file_exists('/usr/local/bin/wp')) {
            return '/usr/local/bin/wp';
        }

        return false;
    }

    /**
     * ✅ FIXED: Execute WP-CLI via PHP_BINARY (cron-safe)
     */
    private static function execute_wpcli_command() {
        if (!function_exists('shell_exec')) {
            return ['success' => false, 'output' => '', 'error' => 'shell_exec disabled'];
        }

        $wpcli = self::get_wpcli_path();
        if (!$wpcli) {
            return ['success' => false, 'output' => '', 'error' => 'WP-CLI not found'];
        }

        $php = PHP_BINARY ?: 'php';

        $command = sprintf(
            '%s %s config shuffle-salts 2>&1',
            escapeshellarg($php),
            escapeshellarg($wpcli)
        );

        $output = shell_exec($command);

        if ($output === null) {
            return ['success' => false, 'output' => '', 'error' => 'Execution failed'];
        }

        $success = stripos($output, 'success') !== false;

        return [
            'success' => $success,
            'output'  => trim($output),
            'error'   => $success ? '' : trim($output)
        ];
    }

    private static function log_rotation_event($status, $output, $error, $backup, $time, $trigger) {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . self::LOG_TABLE_SUFFIX,
            [
                'rotation_date' => current_time('mysql'),
                'status' => $status,
                'wpcli_output' => $output,
                'error_message' => $error,
                'backup_option_id' => $backup,
                'execution_time' => $time,
                'triggered_by' => $trigger
            ]
        );
    }

    private static function send_failure_notification($error, $details = '') {
        if (defined('SALT_ROTATION_NOTIFY_FAILURES') && SALT_ROTATION_NOTIFY_FAILURES === false) {
            return;
        }

        wp_mail(
            get_option('admin_email'),
            '⚠️ Salt Rotation Failed',
            $error . "\n\n" . $details
        );
    }

    public static function admin_notices() {}
    public static function dismiss_admin_notice() {
        update_option(self::ADMIN_NOTICE_OPTION, true);
        wp_die();
    }
}

Daily_Salt_Rotation::init();
