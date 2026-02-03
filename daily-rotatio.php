<?php
/**
 * Plugin Name: Daily Salt Rotation (MU)
 * Description: Rotates WordPress salts daily using WP-CLI
 * Version: 1.0.3
 * Author: Custom Development
 */

if (!defined('ABSPATH')) {
    exit;
}

class Daily_Salt_Rotation {

    const CRON_HOOK = 'daily_salt_rotation_event';
    const LOG_TABLE_SUFFIX = 'salt_rotation_log';
    const BACKUP_OPTION_PREFIX = 'salt_rotation_backup_';

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
        add_action('plugins_loaded', [__CLASS__, 'create_table']);
        add_action('plugins_loaded', [__CLASS__, 'schedule']);
        add_action(self::CRON_HOOK, [__CLASS__, 'rotate']);
    }

    public static function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE_SUFFIX;

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("
            CREATE TABLE $table (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                rotation_date DATETIME NOT NULL,
                status VARCHAR(10),
                wpcli_output LONGTEXT,
                error_message LONGTEXT
            ) {$wpdb->get_charset_collate()};
        ");
    }

    public static function schedule() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(strtotime('tomorrow midnight'), 'daily', self::CRON_HOOK);
        }
    }

    public static function rotate() {
        global $wpdb;

        $backup = self::backup_salts();
        if (!$backup) {
            self::log('failure', '', 'Backup failed');
            return;
        }

        $cmd = '/usr/local/bin/wp config shuffle-salts --path=/srv/htdocs/__wp__/ --allow-root 2>&1';
        $output = shell_exec($cmd);

        if ($output && stripos($output, 'Success') !== false) {
            self::log('success', $output, '');
        } else {
            self::log('failure', $output ?: '', 'WP-CLI failed');
        }
    }

    /**
     * ✅ FIXED REGEX (NO SYNTAX ERRORS)
     */
    private static function backup_salts() {
        $config = dirname(ABSPATH) . '/wp-config.php';
        if (!file_exists($config)) {
            return false;
        }

        $contents = file_get_contents($config);
        if (!$contents) {
            return false;
        }

        $salts = [];

        foreach (self::SALT_KEYS as $key) {
            $pattern = "/define\\(\\s*['\"]" . preg_quote($key, '/') . "['\"]\\s*,\\s*['\"](.+?)['\"]\\s*\\)/";
            if (preg_match($pattern, $contents, $m)) {
                $salts[$key] = $m[1];
            }
        }

        if (count($salts) < 4) {
            return false;
        }

        add_option(
            self::BACKUP_OPTION_PREFIX . time(),
            [
                'salts' => $salts,
                'date'  => current_time('mysql')
            ],
            '',
            'no'
        );

        return true;
    }

    private static function log($status, $output, $error) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . self::LOG_TABLE_SUFFIX,
            [
                'rotation_date' => current_time('mysql'),
                'status' => $status,
                'wpcli_output' => $output,
                'error_message' => $error
            ]
        );
    }
}

Daily_Salt_Rotation::init();
