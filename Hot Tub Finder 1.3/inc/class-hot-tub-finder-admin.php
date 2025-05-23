<?php
if (!defined('ABSPATH')) exit;

class Hot_Tub_Finder_Admin {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function add_menu() {
        $svg_icon = 'data:image/svg+xml;base64,' . base64_encode('
<svg width="20" height="20" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
<ellipse cx="32" cy="38" rx="28" ry="12" fill="#4e88c7" stroke="#21759b" stroke-width="2"/>
<ellipse cx="32" cy="34" rx="28" ry="10" fill="#b7e1fa" stroke="#21759b" stroke-width="2"/>
<ellipse cx="32" cy="34" rx="23" ry="8" fill="#4e88c7" opacity="0.5"/>
<rect x="10" y="34" width="44" height="16" rx="8" fill="#96d1ef" stroke="#21759b" stroke-width="2"/>
<rect x="14" y="44" width="8" height="8" rx="2" fill="#21759b"/>
<rect x="42" y="44" width="8" height="8" rx="2" fill="#21759b"/>
<path d="M20,25 Q22,15 30,20 Q38,25 36,15" stroke="#21759b" stroke-width="2" fill="none"/>
<path d="M28,28 Q32,18 40,23 Q48,28 45,18" stroke="#21759b" stroke-width="2" fill="none"/>
<circle cx="20" cy="52" r="2" fill="#4e88c7"/>
<circle cx="44" cy="52" r="2" fill="#4e88c7"/>
</svg>
        ');

        add_menu_page(
            'Hot Tub Finder',
            'Hot Tub Finder',
            'manage_options',
            'hot-tub-finder',
            [__CLASS__, 'settings_page'],
            $svg_icon,
            56
        );
        add_submenu_page(
            'hot-tub-finder',
            'Settings',
            'Settings',
            'manage_options',
            'hot-tub-finder',
            [__CLASS__, 'settings_page']
        );
    }

    public static function register_settings() {
        register_setting('htf_settings', 'htf_button_gradient_start');
        register_setting('htf_settings', 'htf_button_gradient_end');
        register_setting('htf_settings', 'htf_button_text_size');
        register_setting('htf_settings', 'htf_button_font');
        register_setting('htf_settings', 'htf_seat_options');
        register_setting('htf_settings', 'htf_power_options');
        // NEW: Email Input Toggle
        register_setting('htf_settings', 'htf_show_email_input');
    }

    public static function settings_page() { ?>
        <div class="wrap">
        <h1>Hot Tub Finder Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('htf_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Button Gradient Start Color</th>
                    <td><input type="color" name="htf_button_gradient_start" value="<?php echo esc_attr(get_option('htf_button_gradient_start', '#4e88c7')); ?>"></td>
                </tr>
                <tr>
                    <th scope="row">Button Gradient End Color</th>
                    <td><input type="color" name="htf_button_gradient_end" value="<?php echo esc_attr(get_option('htf_button_gradient_end', '#1e3a5c')); ?>"></td>
                </tr>
                <tr>
                    <th scope="row">Button Text Size (px)</th>
                    <td><input type="number" name="htf_button_text_size" value="<?php echo esc_attr(get_option('htf_button_text_size', '16')); ?>" min="10" max="40"></td>
                </tr>
                <tr>
                    <th scope="row">Button Font Family</th>
                    <td>
                        <input type="text" name="htf_button_font" value="<?php echo esc_attr(get_option('htf_button_font', 'inherit')); ?>" placeholder="Arial, Helvetica, sans-serif">
                        <p class="description">Any valid CSS font-family string. Example: Arial, Helvetica, sans-serif</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Seat Options</th>
                    <td>
                        <input type="text" name="htf_seat_options" value="<?php echo esc_attr(get_option('htf_seat_options', '2,3,4,5,6,7,12')); ?>">
                        <p class="description">Comma separated. E.g. 1,2,3,4,5,6,...,15</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Power Options (Amp)</th>
                    <td>
                        <input type="text" name="htf_power_options" value="<?php echo esc_attr(get_option('htf_power_options', '13,20,32')); ?>">
                        <p class="description">Comma separated. E.g. 13,20,32,45,64</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Show Email Input in Results Section</th>
                    <td>
                        <input type="checkbox" name="htf_show_email_input" value="1" <?php checked(get_option('htf_show_email_input', 1)); ?> />
                        <span class="description">Enable or disable the email input in the Hot Tub Finder results section.</span>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        </div>
    <?php }
}

Hot_Tub_Finder_Admin::init();