<?php

if (!defined('ABSPATH')) exit;

class Hot_Tub_Finder_Analytics {
    private static $htf_custom_csv_filename = '';
    private static $htf_custom_csv_realpath = '';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_analytics_menu']);
        add_action('wp_ajax_htf_log_event', [__CLASS__, 'log_event']);
        add_action('wp_ajax_nopriv_htf_log_event', [__CLASS__, 'log_event']);
        add_action('admin_init', [__CLASS__, 'ensure_analytics_table']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
        add_action('wp_ajax_htf_get_analytics_data', [__CLASS__, 'ajax_get_analytics_data']);
        add_action('admin_init', [__CLASS__, 'maybe_reschedule_report_cron']);
        add_action('htf_analytics_send_report', [__CLASS__, 'cron_send_report']);
    }

    public static function ensure_analytics_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'htf_analytics';

        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s", $table
        )) === $table;

        $charset_collate = $wpdb->get_charset_collate();
        $sql_create = "CREATE TABLE $table (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(32) NOT NULL,
            seats VARCHAR(8),
            power VARCHAR(8),
            lounger VARCHAR(8),
            product_id VARCHAR(64),
            device VARCHAR(16),
            referrer TEXT,
            country VARCHAR(64),
            city VARCHAR(64),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            user_hash CHAR(64)
        ) $charset_collate;";

        if (!$table_exists) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql_create);
            return;
        }

        // Add user_hash if missing
        $col_hash = $wpdb->get_row("SHOW COLUMNS FROM `$table` LIKE 'user_hash'", ARRAY_A);
        if (!$col_hash) {
            $wpdb->query("ALTER TABLE `$table` ADD COLUMN `user_hash` CHAR(64) NULL AFTER `created_at`");
        }
        // Remove ip if present (privacy)
        $col_ip = $wpdb->get_row("SHOW COLUMNS FROM `$table` LIKE 'ip'", ARRAY_A);
        if ($col_ip) {
            $wpdb->query("ALTER TABLE `$table` DROP COLUMN `ip`");
        }
        // Make sure product_id is VARCHAR(64)
        $col = $wpdb->get_row("SHOW COLUMNS FROM `$table` LIKE 'product_id'", ARRAY_A);
        if ($col && stripos($col['Type'], 'varchar') === false) {
            $sql_alter = "ALTER TABLE `$table` MODIFY COLUMN `product_id` VARCHAR(64)";
            $wpdb->query($sql_alter);
        }
    }

    public static function log_event() {
        $event_type = sanitize_text_field($_POST['event_type']);
        $seats = isset($_POST['seats']) ? sanitize_text_field($_POST['seats']) : '';
        $power = isset($_POST['power']) ? sanitize_text_field($_POST['power']) : '';
        $lounger = isset($_POST['lounger']) ? sanitize_text_field($_POST['lounger']) : '';
        $product_id = isset($_POST['product_id']) ? sanitize_text_field($_POST['product_id']) : '';
        $device = isset($_POST['device']) ? sanitize_text_field($_POST['device']) : '';
        $referrer = isset($_POST['referrer']) ? esc_url_raw($_POST['referrer']) : '';

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_hash = hash('sha256', 'murphy_herm_bax' . $ip);

        $country = $city = '';
        $geo = @json_decode(@file_get_contents("http://ip-api.com/json/$ip"));
        if($geo && $geo->status == 'success') {
            $country = $geo->country;
            $city = $geo->city;
        }

        global $wpdb;
        $result = $wpdb->insert($wpdb->prefix . 'htf_analytics', [
            'event_type' => $event_type,
            'seats' => $seats,
            'power' => $power,
            'lounger' => $lounger,
            'product_id' => $product_id,
            'device' => $device,
            'referrer' => $referrer,
            'country' => $country,
            'city' => $city,
            'created_at' => current_time('mysql'),
            'user_hash' => $user_hash,
        ]);

        if ($result === false) {
            error_log('htf Analytics Insert Error: ' . $wpdb->last_error);
            wp_send_json_error(['db_error' => $wpdb->last_error]);
        }
        wp_send_json_success();
    }

    public static function add_analytics_menu() {
        add_submenu_page(
            'hot-tub-finder',
            'Analytics',
            'Analytics',
            'manage_options',
            'htf-analytics',
            [__CLASS__, 'analytics_page']
        );
    }

    public static function enqueue_admin_assets($hook) {
        if ($hook !== 'hot-tub-finder_page_htf-analytics') return;
        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', [], '4.3.0', true);
        wp_enqueue_script('htf-admin-analytics', plugins_url('../assets/hot-tub-finder-admin-analytics.js', __FILE__), ['chartjs'], '1.0', true);
        wp_localize_script('htf-admin-analytics', 'htfAnalyticsAjax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('htf_analytics_nonce'),
        ]);
        wp_enqueue_style('htf-analytics-admin', plugins_url('../assets/hot-tub-finder-admin-analytics.css', __FILE__), [], '1.0');
    }

    public static function analytics_page() {
        // Handle settings save
        if (isset($_POST['htf_analytics_save_report_settings'])) {
            check_admin_referer('htf_analytics_report_settings');
            update_option('htf_analytics_report_enabled', !empty($_POST['htf_analytics_report_enabled']));
            update_option('htf_analytics_report_frequency', $_POST['htf_analytics_report_frequency'] === 'weekly' ? 'weekly' : 'monthly');
            update_option('htf_analytics_report_emails', sanitize_text_field($_POST['htf_analytics_report_emails']));
            self::maybe_reschedule_report_cron();
            echo '<div class="notice notice-success"><p>Analytics email settings saved.</p></div>';
        }

        // Handle test email
        if (isset($_POST['htf_analytics_send_test_email'])) {
            check_admin_referer('htf_analytics_report_settings');
            $emails = array_filter(array_map('trim', preg_split('/[\s,]+/', sanitize_text_field($_POST['htf_analytics_report_emails']))));
            if (!empty($emails)) {
                $freq = $_POST['htf_analytics_report_frequency'] === 'weekly' ? 'weekly' : 'monthly';
                $from = $freq === 'weekly' ? date('Y-m-d', strtotime('-1 week')) : date('Y-m-d', strtotime('-1 month'));
                $to = date('Y-m-d');
                $upload_dir = wp_upload_dir();
                $tmpfile = tempnam($upload_dir['basedir'], 'htf_analytics_test_');
                self::export_csv($from, $to, $tmpfile);
                $site_title = get_bloginfo('name');
                $site_email = self::get_site_email();
                $subject = "{$site_title}'s Hot Tub Finder Analytics Test Report ($from to $to)";
                $body = "This is a test analytics report for {$site_title} ($from to $to). If you received this, the analytics email system is working.";
                $headers = ['From: ' . $site_title . ' <' . $site_email . '>'];
                self::send_mail_with_csv($emails, $subject, $body,$tmpfile, $filename = preg_replace ('/\s+/', '_', $site_title) . "_Analytics_TEST_{$from}_to_{$to}.csv", $headers);
                @unlink($tmpfile);
                echo '<div class="notice notice-success"><p>Test analytics email sent.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Please enter at least one valid email address to send a test email.</p></div>';
            }
        }

        // Handle CSV export
        if (isset($_GET['export']) && $_GET['export'] == 'csv') {
            self::export_csv();
            exit;
        }

        $enabled = get_option('htf_analytics_report_enabled', false);
        $emails = get_option('htf_analytics_report_emails', '');
        $frequency = get_option('htf_analytics_report_frequency', 'monthly');
        ?>
        <div class="wrap" id="htf-analytics-admin-app">
            <h1>Hot Tub Finder Analytics</h1>

            <form method="post" style="margin-bottom:32px;">
                <?php wp_nonce_field('htf_analytics_report_settings'); ?>
                <h3>Analytics Email Reports</h3>
                <p>
                    <label>
                        <input type="checkbox" name="htf_analytics_report_enabled" value="1" <?php checked($enabled); ?>>
                        Enable automatic analytics emails
                    </label>
                </p>
                <p>
                    <label>Recipient Emails (comma or newline separated):<br>
                    <textarea name="htf_analytics_report_emails" rows="3" style="width:350px;"><?php echo esc_textarea($emails); ?></textarea></label>
                </p>
                <p>
                    <label>Frequency:
                        <select name="htf_analytics_report_frequency">
                            <option value="weekly" <?php selected($frequency, 'weekly'); ?>>Weekly</option>
                            <option value="monthly" <?php selected($frequency, 'monthly'); ?>>Monthly</option>
                        </select>
                    </label>
                </p>
                <p>
                    <button type="submit" name="htf_analytics_save_report_settings" class="button button-primary">Save Settings</button>
                    <button type="submit" name="htf_analytics_send_test_email" class="button">Send Test Email Now</button>
                </p>
            </form>

            <form id="htf-analytics-filter-form" method="get" style="margin-bottom:18px;">
                <input type="hidden" name="page" value="htf-analytics">
                <label>
                    From: <input type="date" name="from" id="htf-analytics-from">
                </label>
                <label style="margin-left:10px;">
                    To: <input type="date" name="to" id="htf-analytics-to">
                </label>
                <button type="button" id="htf-analytics-apply" class="button">Apply</button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=htf-analytics&export=csv')); ?>" class="button button-primary" style="margin-left:18px;">Download CSV</a>
            </form>
            <div id="htf-analytics-summary"></div>
            <div class="htf-analytics-charts-row">
                <div class="htf-analytics-chart-card">
                    <h3>Product Views</h3>
                    <div class="htf-analytics-pie-wrap">
                        <canvas id="htf-analytics-product-views-chart"></canvas>
                    </div>
                </div>
                <div class="htf-analytics-chart-card">
                    <h3>Event Types</h3>
                    <div class="htf-analytics-pie-wrap">
                        <canvas id="htf-analytics-event-chart"></canvas>
                    </div>
                </div>
                <div class="htf-analytics-chart-card">
                    <h3>Device</h3>
                    <div class="htf-analytics-pie-wrap">
                        <canvas id="htf-analytics-device-chart"></canvas>
                    </div>
                </div>
                <div class="htf-analytics-chart-card">
                    <h3>Country</h3>
                    <div class="htf-analytics-pie-wrap">
                        <canvas id="htf-analytics-country-chart"></canvas>
                    </div>
                </div>
            </div>
            <div id="htf-analytics-table-wrap">
                <table id="htf-analytics-table" class="widefat">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Event</th>
                            <th>Seats</th>
                            <th>Power</th>
                            <th>Lounger</th>
                            <th>Product ID</th>
                            <th>Device</th>
                            <th>Country</th>
                            <th>City</th>
                            <th>User (Anon)</th>
                            <th>Referrer</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="11" style="text-align:center;">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        add_action('admin_footer', function() {
            ?>
            <script>
            document.addEventListener('DOMContentLoaded', function(){
                if(typeof htfAnalyticsInit === 'function') htfAnalyticsInit();
            });
            </script>
            <?php
        });
    }

    public static function export_csv($from = '', $to = '', $output = 'php://output') {
        global $wpdb;
        if ($output === 'php://output' && ob_get_length()) ob_clean();

        $table = $wpdb->prefix . 'htf_analytics';
        $where = '1=1';

        if (!$from && isset($_GET['from']) && $_GET['from']) {
            $from = sanitize_text_field($_GET['from']);
        }
        if (!$to && isset($_GET['to']) && $_GET['to']) {
            $to = sanitize_text_field($_GET['to']);
        }
        if ($from) {
            $where .= $wpdb->prepare(" AND created_at >= %s", $from . ' 00:00:00');
        }
        if ($to) {
            $where .= $wpdb->prepare(" AND created_at <= %s", $to . ' 23:59:59');
        }

        $data = $wpdb->get_results("SELECT created_at, event_type, seats, power, lounger, product_id, device, country, city, user_hash, referrer FROM $table WHERE $where ORDER BY created_at DESC", ARRAY_A);

        $site_title = preg_replace('/\s+/', '_', get_bloginfo('name'));
        $from_label = $from ? $from : 'beginning';
        $to_label = $to ? $to : date('Y-m-d');
        $filename = "{$site_title}_Analytics_{$from_label}_to_{$to_label}.csv";

        if ($output === 'php://output') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
        }
        $out = fopen($output, 'w');
        fputcsv($out, [
            'Date', 'Event', 'Seats', 'Power', 'Lounger', 'Product ID', 'Device', 'Country', 'City', 'User (Anon)', 'Referrer'
        ]);
        foreach ($data as $row) {
            fputcsv($out, [
                $row['created_at'],
                $row['event_type'],
                $row['seats'],
                $row['power'],
                $row['lounger'],
                $row['product_id'],
                $row['device'],
                $row['country'],
                $row['city'],
                $row['user_hash'],
                $row['referrer'],
            ]);
        }
        fclose($out);
        if ($output === 'php://output') exit;
        return $filename;
    }

    public static function ajax_get_analytics_data() {
        if (!current_user_can('manage_options')) wp_send_json_error(['error'=>'Unauthorized']);
        check_ajax_referer('htf_analytics_nonce');
        global $wpdb;
        $table = $wpdb->prefix . 'htf_analytics';
        $where = '1=1';
        if (isset($_POST['from']) && $_POST['from']) {
            $from = sanitize_text_field($_POST['from']) . ' 00:00:00';
            $where .= $wpdb->prepare(" AND created_at >= %s", $from);
        }
        if (isset($_POST['to']) && $_POST['to']) {
            $to = sanitize_text_field($_POST['to']) . ' 23:59:59';
            $where .= $wpdb->prepare(" AND created_at <= %s", $to);
        }
        $data = $wpdb->get_results("SELECT created_at, event_type, seats, power, lounger, product_id, device, country, city, user_hash, referrer FROM $table WHERE $where ORDER BY created_at DESC", ARRAY_A);
        wp_send_json_success($data);
    }

    public static function maybe_reschedule_report_cron() {
        $enabled = get_option('htf_analytics_report_enabled', false);
        $freq = get_option('htf_analytics_report_frequency', 'monthly');
        $hook = 'htf_analytics_send_report';
        if (wp_next_scheduled($hook)) {
            wp_clear_scheduled_hook($hook);
        }
        if ($enabled) {
            $interval = ($freq === 'weekly') ? WEEK_IN_SECONDS : MONTH_IN_SECONDS;
            wp_schedule_event(time() + $interval, $freq, $hook);
        }
    }

    public static function cron_send_report() {
        if (!get_option('htf_analytics_report_enabled', false)) return;
        $emails = array_filter(array_map('trim', preg_split('/[\s,]+/', get_option('htf_analytics_report_emails', ''))));
        if (empty($emails)) return;
        $freq = get_option('htf_analytics_report_frequency', 'monthly');
        $from = $freq === 'weekly' ? date('Y-m-d', strtotime('-1 week')) : date('Y-m-d', strtotime('-1 month'));
        $to = date('Y-m-d');

        $upload_dir = wp_upload_dir();
        $tmpfile = tempnam($upload_dir['basedir'], 'htf_analytics_');
        self::export_csv($from, $to, $tmpfile);
        $site_title = get_bloginfo('name');
        $site_email = self::get_site_email();
        $filename = preg_replace('/\s+/', '_', $site_title) . "_Analytics_{$from}_to_{$to}.csv";
        $subject = "{$site_title}'s Hot Tub Finder Analytics Report ($from to $to)";
        $body = "Attached is your analytics report for {$site_title} ($from to $to).";
        $headers = ['From: ' . $site_title . ' <' . $site_email . '>'];
        self::send_mail_with_csv($emails, $subject, $body, $tmpfile, $filename, $headers);
        @unlink($tmpfile);
    }

     public static function send_mail_with_csv($emails, $subject, $body, $csv_path, $csv_filename, $headers = []) {
        self::$htf_custom_csv_filename = $csv_filename;
        self::$htf_custom_csv_realpath = $csv_path;
        add_action('phpmailer_init', [__CLASS__, 'phpmailer_custom_filename']);
        wp_mail($emails, $subject, $body, $headers, [$csv_path]);
        remove_action('phpmailer_init', [__CLASS__, 'phpmailer_custom_filename']);
        self::$htf_custom_csv_filename = '';
        self::$htf_custom_csv_realpath = '';
    }
        


    public static function phpmailer_custom_filename($phpmailer) {
        if (empty(self::$htf_custom_csv_filename) || empty(self::$htf_custom_csv_realpath)) return;
        // Just add the attachment again with the custom filename
        $phpmailer->addAttachment(
            self::$htf_custom_csv_realpath,
            self::$htf_custom_csv_filename,
            'base64',
            'text/csv'
        );
        // Set From header
        $site_title = get_bloginfo('name');
        $site_email = self::get_site_email();
        
    }

    // Utility to get a good site email address
     public static function get_site_email() {
        $admin_email = get_option('admin_email');
        $domain = parse_url(home_url(), PHP_URL_HOST);
        if ($domain && filter_var('noreply@' . $domain, FILTER_VALIDATE_EMAIL)) {
            return 'noreply@' . $domain;
        }
        return $admin_email ?: 'wordpress@' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
    }
}


Hot_Tub_Finder_Analytics::init();