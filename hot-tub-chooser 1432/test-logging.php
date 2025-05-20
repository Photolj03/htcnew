<?php
// Only run in WordPress context
require_once('../../../wp-load.php');

if (isset($_GET['run'])) {
    $_POST['event_type'] = 'test_event';
    $_POST['seats'] = '2';
    $_POST['power'] = '13';
    $_POST['lounger'] = 'no';
    $_POST['product_id'] = 123;
    $_POST['device'] = 'Desktop';
    $_POST['referrer'] = 'https://example.com';

    // Manually call the logging function
    require_once __DIR__ . '/inc/class-hot-tub-chooser-analytics.php';
    Hot_Tub_Chooser_Analytics::log_event();
    exit;
}
echo 'Add ?run=1 to the URL to test logging.';