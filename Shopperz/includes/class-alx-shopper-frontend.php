<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class Alx_Shopper_Frontend {
    public function __construct() {
        // Remove the old [alx_shopper_form] shortcode registration
        // Use only the main [alx_shopper] shortcode in your plugin root file
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    public function enqueue_scripts() {
        wp_enqueue_style(
            'alx-shopper-style',
            plugins_url( 'assets/css/shopper-style.css', dirname(__DIR__) . '/alx-shopper.php' )
        );
        wp_enqueue_script(
            'alx-shopper-frontend',
            plugins_url( 'assets/js/alx-shopper-frontend.js', dirname(__DIR__) . '/alx-shopper.php' ),
            ['jquery'],
            ALX_SHOPPER_VERSION,
            true
        );
        global $alx_shopper_current_filter_config;
        wp_localize_script( 'alx-shopper-frontend', 'alxShopperAjax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'enable_email_results' => get_option('alxshopper_enable_email_results', false),
        ]);
        // Optionally enqueue analytics JS here if needed
    }
}
