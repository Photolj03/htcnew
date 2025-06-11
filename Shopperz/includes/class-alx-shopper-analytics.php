<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class Alx_Shopper_Analytics {
    private $log_file;
    private $max_log_size = 1048576; // 1MB

    public function __construct() {
        $this->log_file = plugin_dir_path(__DIR__) . 'analytics.log';

        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_alx_shopper_log_interaction', array($this, 'ajax_log_interaction'));
        add_action('wp_ajax_nopriv_alx_shopper_log_interaction', array($this, 'ajax_log_interaction'));
        add_action('wp_ajax_alxshopper_log_event', 'alxshopper_log_event', array($this, 'alxshopper_log_event'));
        add_action('wp_ajax_nopriv_alxshopper_get_analytics', array($this, 'get_analytics_data'));
        add_action('wp_ajax_nopriv_alxshopper_log_event', 'alxshopper_log_event', array($this, 'alxshopper_log_event'));
        add_action('alxshopper_send_analytics_csv', 'alxshopper_send_analytics_csv_func');
    }

    public function enqueue_scripts() {
        if (!wp_style_is('alx-shopper-style', 'enqueued')) {
            wp_enqueue_style(
                'alx-shopper-style',
                plugins_url('assets/css/shopper-style.css', dirname(__DIR__) . '/alx-shopper.php')
            );
        }
        if (!wp_script_is('alx-shopper-frontend', 'enqueued')) {
            wp_enqueue_script(
                'alx-shopper-frontend',
                plugins_url('assets/js/alx-shopper-frontend.js', dirname(__DIR__) . '/alx-shopper.php'),
                ['jquery'],
                ALX_SHOPPER_VERSION,
                true
            );
        }
        wp_localize_script('alx-shopper-frontend', 'alxShopperAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
        ));
    }

    public function ajax_log_interaction() {
        $data = $this->sanitize_array($_POST);
        $ip_address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '';
        $timestamp = date('c'); // ISO 8601

        $log_entry = json_encode([
            'timestamp' => $timestamp,
            'ip' => $ip_address,
            'data' => $data,
        ]) . PHP_EOL;

        if (file_exists($this->log_file) && filesize($this->log_file) > $this->max_log_size) {
            file_put_contents($this->log_file, "");
        }

        file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);

        wp_send_json_success(['logged' => true]);
    }

    private function sanitize_array($array) {
        $sanitized = [];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitize_array($value);
            } else {
                $sanitized[$key] = sanitize_text_field($value);
            }
        }
        return $sanitized;
    }

    public function get_analytics_data() {
        if (file_exists($this->log_file) && current_user_can('manage_options')) {
            return file_get_contents($this->log_file);
        }
        return '';
    }

    public function clear_log() {
        if (current_user_can('manage_options')) {
            file_put_contents($this->log_file, '');
            return true;
        }
        return false;
    }
}

function alxshopper_log_event() {
    global $wpdb;
    $table = $wpdb->prefix . 'alxshopper_analytics';
    $event_type = sanitize_text_field($_POST['event_type']);
    $event_data = isset($_POST['event_data']) ? $_POST['event_data'] : [];
    if (is_array($event_data)) {
        $event_data = wp_json_encode($event_data);
    }
    $user_ip = $_SERVER['REMOTE_ADDR'];
    $user_location = sanitize_text_field($_POST['user_location'] ?? '');
    $referrer = sanitize_text_field($_POST['referrer'] ?? '');
    $device = sanitize_text_field($_POST['device'] ?? '');
    $created_at = current_time('mysql');

    $wpdb->insert($table, compact('event_type', 'event_data', 'user_ip', 'user_location', 'referrer', 'device', 'created_at'));

    if ($wpdb->last_error) {
        file_put_contents(__DIR__.'/debug.log', "DB ERROR: " . $wpdb->last_error . "\n", FILE_APPEND);
    }

    wp_send_json_success();
}

add_action('wp_ajax_alxshopper_get_analytics', 'alxshopper_get_analytics');
function alxshopper_get_analytics() {
    global $wpdb;
    $table = $wpdb->prefix . 'alxshopper_analytics';
    $results = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 100", ARRAY_A);
    wp_send_json_success($results);
}

function alxshopper_analytics_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'alxshopper_analytics';

    $search_events = $wpdb->get_results(
        "SELECT * FROM $table ORDER BY id DESC LIMIT 100",
        ARRAY_A
    );

    $product_names = [];
    $quickview_counts = [];
    $productview_counts = [];
    $device_counts = [];

    foreach ($search_events as $row) {
        $data = maybe_unserialize($row['event_data']);
        if (is_string($data)) $data = @json_decode($data, true);

        $product_id = $data['product_id'] ?? $data['query'] ?? null;
        $device = $row['device'] ?? 'Unknown';

        if ($product_id && !isset($product_names[$product_id]) && function_exists('get_the_title')) {
            $product_names[$product_id] = get_the_title($product_id);
        }
        $product_name = $product_names[$product_id] ?? $product_id;

        if ($row['event_type'] === 'quick_view' && $product_name) {
            $quickview_counts[$product_name] = ($quickview_counts[$product_name] ?? 0) + 1;
        }
        if ($row['event_type'] === 'view_product_btn' && $product_name) {
            $productview_counts[$product_name] = ($productview_counts[$product_name] ?? 0) + 1;
        }
        $device_counts[$device] = ($device_counts[$device] ?? 0) + 1;
    }

    echo '<script>
        window.alxQuickViewCounts = ' . json_encode($quickview_counts) . ';
        window.alxProductViewCounts = ' . json_encode($productview_counts) . ';
        window.alxDeviceCounts = ' . json_encode($device_counts) . ';
    </script>';

    echo '<div id="alxshopper-analytics-graphs">';
    echo '<div class="alxshopper-graph-card">';
    echo '<h3>Quick Views by Product</h3>';
    echo '<canvas id="alx-quickview-chart"></canvas>';
    echo '</div>';
    echo '<div class="alxshopper-graph-card">';
    echo '<h3>Product Views by Product</h3>';
    echo '<canvas id="alx-productview-chart"></canvas>';
    echo '</div>';
    echo '<div class="alxshopper-graph-card">';
    echo '<h3>Device Type Distribution</h3>';
    echo '<canvas id="alx-device-chart"></canvas>';
    echo '</div>';
    echo '</div>';

    echo '<div class="wrap" style="max-width:1100px;">';
    echo '<h1 style="margin-bottom:24px;">Analytics Dashboard</h1>';
    echo '<h2 style="margin-top:40px;">Recent Searches</h2>';
    echo '<button id="alxshopper-download-csv" style="margin-bottom:18px;">Download CSV</button>';
    echo '<div id="alxshopper-analytics-table">';
    echo '<table id="alxshopper-recent-searches" class="widefat fixed">';
    echo '<thead><tr>
        <th>Date/Time</th>
        <th>Action</th>
        <th>Product</th>
        <th>Filters</th>
        <th>IP Address</th>
        <th>Location</th>
        <th>Referrer</th>
        <th>Device</th>
    </tr></thead><tbody>';

    foreach ($search_events as $row) {
        $data = maybe_unserialize($row['event_data']);
        if (is_string($data)) $data = @json_decode($data, true);
        $action = esc_html($row['event_type']);
        $product_id = $data['product_id'] ?? $data['query'] ?? '';
        $product_name = $product_names[$product_id] ?? $product_id;

        $filters = [];
        if (!empty($data['filters']) && is_array($data['filters'])) {
            if (!empty($data['filters']['alx_filter_id'])) {
                $fid = $data['filters']['alx_filter_id'];
                $filters[] = '<strong>Filter:</strong> ' . esc_html($fid);
            }
            foreach ($data['filters'] as $k => $v) {
                if (preg_match('/^alx_dropdown_(\d+)$/', $k, $m)) {
                    $idx = $m[1];
                    $label = isset($data['filters']["alx_dropdown_{$idx}_label"]) ? $data['filters']["alx_dropdown_{$idx}_label"] : '';
                    $val = $v;
                    if ($val !== 'any' && $val !== '') {
                        if (is_numeric($val) && function_exists('get_term')) {
                            $term = get_term($val);
                            if ($term && !is_wp_error($term)) {
                                $val = $term->name;
                            }
                        }
                        $filters[] = '<strong>' . ucwords(esc_html($label)) . ':</strong> ' . ucwords(esc_html($val));
                    }
                }
            }
        }
        $filters_html = $filters ? implode('<br>', $filters) : '<em>No filters selected</em>';
        echo '<tr>';
        echo '<td>' . esc_html($row['created_at'] ?? $row['timestamp'] ?? '') . '</td>';
        echo '<td>' . $action . '</td>';
        echo '<td>' . esc_html($product_name) . '</td>';
        echo '<td>' . $filters_html . '</td>';
        echo '<td>' . esc_html($row['user_ip']) . '</td>';

        // Decode location JSON if present
        $location = $row['user_location'];
        $location_display = '';
        if ($location) {
            $loc = json_decode($location, true);
            if (is_array($loc)) {
                if (!empty($loc['place'])) {
                    $location_display = $loc['place'];
                } elseif (isset($loc['city'], $loc['country'])) {
                    $location_display = $loc['city'] . ', ' . $loc['country'];
                } elseif (isset($loc['country'])) {
                    $location_display = $loc['country'];
                }
            } else {
                $location_display = esc_html($location);
            }
        }
        echo '<td>' . esc_html($location_display) . '</td>';
        echo '<td>' . esc_html($row['referrer'] ?? '') . '</td>';
        echo '<td>' . esc_html($row['device'] ?? '') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div></div>';
    ?>

    <!-- Chart.js rendering -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Quick Views Chart
        const quickViewCounts = window.alxQuickViewCounts || {};
        const quickViewLabels = Object.keys(quickViewCounts);
        const quickViewData = quickViewLabels.map(name => quickViewCounts[name]);
        if (window.alxQuickViewChart) window.alxQuickViewChart.destroy?.();
        window.alxQuickViewChart = new Chart(document.getElementById('alx-quickview-chart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: quickViewLabels,
                datasets: [{
                    label: 'Quick Views',
                    data: quickViewData,
                    backgroundColor: 'rgba(33,150,243,0.7)'
                }]
            },
            options: {
                plugins: { title: { display: true, text: 'Quick Views by Product' }, legend: { display: false } },
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { ticks: { autoSkip: false, maxRotation: 45, minRotation: 0, font: { size: 13, weight: '500' } } },
                    y: { beginAtZero: true, ticks: { font: { size: 13, weight: '500' } } }
                }
            }
        });

        // Product Views Chart
        const productViewCounts = window.alxProductViewCounts || {};
        const productViewLabels = Object.keys(productViewCounts);
        const productViewData = productViewLabels.map(name => productViewCounts[name]);
        if (window.alxProductViewChart) window.alxProductViewChart.destroy?.();
        window.alxProductViewChart = new Chart(document.getElementById('alx-productview-chart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: productViewLabels,
                datasets: [{
                    label: 'Product Views',
                    data: productViewData,
                    backgroundColor: 'rgba(0,51,102,0.7)'
                }]
            },
            options: {
                plugins: { title: { display: true, text: 'Product Views by Product' }, legend: { display: false } },
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { ticks: { autoSkip: false, maxRotation: 45, minRotation: 0, font: { size: 13, weight: '500' } } },
                    y: { beginAtZero: true, ticks: { font: { size: 13, weight: '500' } } }
                }
            }
        });

        // Device Chart
        const deviceCounts = window.alxDeviceCounts || {};
        const deviceLabels = Object.keys(deviceCounts);
        const deviceData = deviceLabels.map(label => deviceCounts[label]);
        if (window.alxDeviceChart) window.alxDeviceChart.destroy?.();
        window.alxDeviceChart = new Chart(document.getElementById('alx-device-chart').getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: deviceLabels,
                datasets: [{
                    data: deviceData,
                    backgroundColor: ['#2196f3','#003366','#43e97b','#f44336','#ff9800']
                }]
            },
            options: {
                plugins: { title: { display: true, text: 'Device Type Distribution' }, legend: { display: true } },
                responsive: true,
                maintainAspectRatio: false
            }
        });
    });
    </script>

    <!-- CSV Download -->
    <script>
    document.getElementById('alxshopper-download-csv').addEventListener('click', function() {
        function escapeCSV(val) {
            if (typeof val !== 'string') val = String(val ?? '');
            if (val.match(/[",\n]/)) {
                return '"' + val.replace(/"/g, '""') + '"';
            }
            return val;
        }
        const table = document.getElementById('alxshopper-recent-searches');
        const rows = Array.from(table.querySelectorAll('tr'));
        const csv = rows.map(function(row) {
            return Array.from(row.children).map(function(cell) {
                return escapeCSV(cell.innerText);
            }).join(',');
        }).join('\n');
        const blob = new Blob([csv], {type: 'text/csv'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'recent-searches.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    });
    </script>
    <?php
}


