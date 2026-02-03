<?php
/**
 * Plugin Name: Daily Salt Rotation (MU)
 * Description: Rotates WordPress salts daily at midnight via WP-Cron for compliance requirements
 * Version: 1.0.2
 * Author: Custom Development
 *
 * ⚠️ WARNING:
 * - Logs out all users on rotation
 * - Invalidates all nonces
 * - Can affect plugins that encrypt data using salts
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Daily_Salt_Rotation {

    const CRON_HOOK = 'daily_salt_rotation_event';
    const LOG_TABLE_SUFFIX = 'salt_rotation_log';
    const BACKUP_OPTION_PREFIX = 'salt_rotation_backup_';
    const BACKUPS_TO_KEEP = 5;

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
    }

    public static function create_log_table_if_needed() {
        global $wpdb;

        $table = $wpdb->prefix . self::LOG_TABLE_SUFFIX;

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        dbDelta("
            CREATE TABLE $table (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                rotation_date DATETIME NOT NULL,
                status VARCHAR(10) NOT NULL,
                wpcli_output LONGTEXT,
                error_message LONGTEXT,
                backup_option_id VARCHAR(100),
                execution_time FLOAT,
                triggered_by VARCHAR(50),
                KEY rotation_date (rotation_date)
            ) $charset;
        ");
    }

    public static function schedule_rotation() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(strtotime('tomorrow midnight'), 'daily', self::CRON_HOOK);
        }
    }

    public static function check_if_overdue() {
        global $wpdb;

        if (is_admin()) return;

        $table = $wpdb->prefix . self::LOG_TABLE_SUFFIX;

        $last = $wpdb->get_var(
            "SELECT rotation_date FROM $table WHERE status='success' ORDER BY rotation_date DESC LIMIT 1"
        );

        if (!$last || strtotime($last) < strtotime('-25 hours')) {
            self::execute_rotation('overdue-fallback');
        }
    }

    public static function execute_rotation($triggered_by = 'wp-cron') {
        $start = microtime(true);

        $backup = self::backup_current_salts();
        if (!$backup) {
            self::log('failure', '', 'Backup failed', null, 0, $triggered_by);
            return;
        }

        $result = self::execute_wpcli_command();
        $time = microtime(true) - $start;

        if ($result['success']) {
            self::log('success', $result['output'], '', $backup, $time, $triggered_by);
            self::cleanup_old_backups();
        } else {
            self::log('failure', $result['output'], $result['error'], $backup, $time, $triggered_by);
        }
    }

    private static function backup_current_salts() {
        $paths = [
            dirname(ABSPATH) . '/wp-config.php',
            ABSPATH . 'wp-config.php'
        ];

        foreach ($paths as $file) {
            if (file_exists($file) && is_readable($file)) {
                $config = file_get_contents($file);
                break;
            }
        }

        if (empty($config)) return false;

        $backup = [];

        foreach (self::SALT_KEYS as $key) {
            if (preg_match("/define\\(['\"]$key['\"],\\s*['\"](.+?)['\"]\\)/", $config, $m)) {
                $backup[$key] = $m[1];
            }
        }

        if (count($backup) < 4) return false;

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

        foreach (array_slice($options, $keep) as $opt) {
            delete_option($opt);
        }
    }

    /**
     * ✅ FIXED WP-CLI EXECUTION (Convesio-safe)
     */
    private static function execute_wpcli_command() {

        if (!function_exists('shell_exec')) {
            return [
                'success' => false,
                'output'  => '',
                'error'   => 'shell_exec disabled'
            ];
        }

        $wpcli = '/usr/local/bin/wp';

        if (!file_exists($wpcli) || !is_executable($wpcli)) {
            return [
                'success' => false,
                'output'  => '',
                'error'   => 'WP-CLI not executable at /usr/local/bin/wp'
            ];
        }

        $command = $wpcli
            . ' config shuffle-salts'
            . ' --path=/srv/htdocs/__wp__/'
            . ' --allow-root 2>&1';

        $output = shell_exec($command);

        if ($output === null) {
            return [
                'success' => false,
                'output'  => '',
                'error'   => 'shell_exec returned NULL'
            ];
        }

        $success =
            stripos($output, 'Success') !== false ||
            stripos($output, 'Shuffled the salt keys') !== false;

        return [
            'success' => $success,
            'output'  => trim($output),
            'error'   => $success ? '' : trim($output)
        ];
    }

    private static function log($status, $output, $error, $backup, $time, $trigger) {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . self::LOG_TABLE_SUFFIX,
            [
                'rotation_date'   => current_time('mysql'),
                'status'          => $status,
                'wpcli_output'    => $output,
                'error_message'   => $error,
                'backup_option_id'=> $backup,
                'execution_time'  => $time,
                'triggered_by'    => $trigger
            ]
        );
    }
}

Daily_Salt_Rotation::init();
