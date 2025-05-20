<?php
if (!defined('ABSPATH')) exit;

class Hot_Tub_Chooser_Analytics {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_analytics_menu']);
        add_action('wp_ajax_htc_log_event', [__CLASS__, 'log_event']);
        add_action('wp_ajax_nopriv_htc_log_event', [__CLASS__, 'log_event']);
        add_action('plugins_loaded', [__CLASS__, 'maybe_create_table']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
    }

    public static function maybe_create_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'htc_analytics';
        if($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            self::create_table();
        }
    }

    public static function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'htc_analytics';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(32) NOT NULL,
            seats VARCHAR(8),
            power VARCHAR(8),
            lounger VARCHAR(8),
            product_id BIGINT,
            device VARCHAR(16),
            referrer TEXT,
            ip VARCHAR(64),
            country VARCHAR(64),
            city VARCHAR(64),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public static function log_event() {
        $event_type = sanitize_text_field($_POST['event_type']);
        $seats = isset($_POST['seats']) ? sanitize_text_field($_POST['seats']) : '';
        $power = isset($_POST['power']) ? sanitize_text_field($_POST['power']) : '';
        $lounger = isset($_POST['lounger']) ? sanitize_text_field($_POST['lounger']) : '';
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : null;
        $device = isset($_POST['device']) ? sanitize_text_field($_POST['device']) : '';
        $referrer = isset($_POST['referrer']) ? esc_url_raw($_POST['referrer']) : '';
        $ip = $_SERVER['REMOTE_ADDR'];
        $country = $city = '';
        $geo = @json_decode(@file_get_contents("http://ip-api.com/json/$ip"));
        if($geo && $geo->status == 'success') {
            $country = $geo->country;
            $city = $geo->city;
        }

        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'htc_analytics', [
            'event_type' => $event_type,
            'seats' => $seats,
            'power' => $power,
            'lounger' => $lounger,
            'product_id' => $product_id,
            'device' => $device,
            'referrer' => $referrer,
            'ip' => $ip,
            'country' => $country,
            'city' => $city,
            'created_at' => current_time('mysql')
        ]);
        wp_send_json_success();
    }

    public static function add_analytics_menu() {
        add_submenu_page(
            'hot-tub-chooser',
            'Analytics',
            'Analytics',
            'manage_options',
            'htc-analytics',
            [__CLASS__, 'analytics_page']
        );
    }

    public static function enqueue_admin_assets($hook) {
        if ($hook !== 'hot-tub-chooser_page_htc-analytics') return;
        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', [], '4.3.0', true);
        wp_enqueue_script('htc-admin-analytics', plugins_url('../assets/hot-tub-chooser-admin-analytics.js', __FILE__), ['chartjs'], '1.0', true);
        wp_localize_script('htc-admin-analytics', 'htcAnalyticsAjax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('htc_analytics_nonce'),
        ]);
        wp_enqueue_style('htc-analytics-admin', plugins_url('../assets/hot-tub-chooser-admin-analytics.css', __FILE__), [], '1.0');
    }

    public static function analytics_page() {
        if (isset($_GET['export']) && $_GET['export'] == 'csv') {
            self::export_csv();
            exit;
        }
        ?>
        <div class="wrap" id="htc-analytics-admin-app">
            <h1>Hot Tub Chooser Analytics</h1>
            <form id="htc-analytics-filter-form" method="get" style="margin-bottom:18px;">
                <input type="hidden" name="page" value="htc-analytics">
                <label>
                    From: <input type="date" name="from" id="htc-analytics-from">
                </label>
                <label style="margin-left:10px;">
                    To: <input type="date" name="to" id="htc-analytics-to">
                </label>
                <button type="button" id="htc-analytics-apply" class="button">Apply</button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=htc-analytics&export=csv')); ?>" class="button button-primary" style="margin-left:18px;">Download CSV</a>
            </form>
            <div id="htc-analytics-summary"></div>
            <div style="display:flex;flex-wrap:wrap;gap:24px;margin-bottom:28px;">
                <canvas id="htc-analytics-event-chart" style="max-width:420px;min-width:320px;background:#fff;border-radius:12px;box-shadow:0 1px 6px #ccc2;"></canvas>
                <canvas id="htc-analytics-device-chart" style="max-width:320px;min-width:200px;background:#fff;border-radius:12px;box-shadow:0 1px 6px #ccc2;"></canvas>
                <canvas id="htc-analytics-country-chart" style="max-width:320px;min-width:200px;background:#fff;border-radius:12px;box-shadow:0 1px 6px #ccc2;"></canvas>
            </div>
            <div id="htc-analytics-table-wrap">
                <table id="htc-analytics-table" class="widefat">
                    <thead>
                        <tr>
                            <th>Date</th><th>Event</th><th>Seats</th><th>Power</th><th>Lounger</th>
                            <th>Product ID</th><th>Device</th><th>Country</th><th>City</th><th>Referrer</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="10" style="text-align:center;">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        add_action('admin_footer', function() {
            ?>
            <script>
            document.addEventListener('DOMContentLoaded', function(){
                if(typeof htcAnalyticsInit === 'function') htcAnalyticsInit();
            });
            </script>
            <?php
        });
    }

    public static function export_csv() {
        global $wpdb;
        $table = $wpdb->prefix . 'htc_analytics';
        $where = '1=1';
        if (isset($_GET['from']) && $_GET['from']) {
            $from = sanitize_text_field($_GET['from']) . ' 00:00:00';
            $where .= $wpdb->prepare(" AND created_at >= %s", $from);
        }
        if (isset($_GET['to']) && $_GET['to']) {
            $to = sanitize_text_field($_GET['to']) . ' 23:59:59';
            $where .= $wpdb->prepare(" AND created_at <= %s", $to);
        }
        $data = $wpdb->get_results("SELECT * FROM $table WHERE $where ORDER BY created_at DESC", ARRAY_A);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="htc-analytics.csv"');
        $out = fopen('php://output', 'w');
        if (!empty($data)) {
            fputcsv($out, array_keys($data[0]));
            foreach ($data as $row) fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }
}

// AJAX endpoint for admin page data
add_action('wp_ajax_htc_get_analytics_data', function() {
    if (!current_user_can('manage_options')) wp_send_json_error(['error'=>'Unauthorized']);
    check_ajax_referer('htc_analytics_nonce');
    global $wpdb;
    $table = $wpdb->prefix . 'htc_analytics';
    $where = '1=1';
    if (isset($_POST['from']) && $_POST['from']) {
        $from = sanitize_text_field($_POST['from']) . ' 00:00:00';
        $where .= $wpdb->prepare(" AND created_at >= %s", $from);
    }
    if (isset($_POST['to']) && $_POST['to']) {
        $to = sanitize_text_field($_POST['to']) . ' 23:59:59';
        $where .= $wpdb->prepare(" AND created_at <= %s", $to);
    }
    $data = $wpdb->get_results("SELECT * FROM $table WHERE $where ORDER BY created_at DESC LIMIT 1000", ARRAY_A);
    // PATCH: Return the array directly, not wrapped in ['data' => $data]
    wp_send_json_success($data);
});

Hot_Tub_Chooser_Analytics::init();