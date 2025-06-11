<?php
global $alx_shopper_current_filter_config, $alx_shopper_current_filter_id;

if ($alx_shopper_current_filter_config && is_array($alx_shopper_current_filter_config)) {
    $num = intval($alx_shopper_current_filter_config['num']);
    $titles = $alx_shopper_current_filter_config['titles'];
    $mapping = $alx_shopper_current_filter_config['mapping'];
    $values = isset($alx_shopper_current_filter_config['values']) ? $alx_shopper_current_filter_config['values'] : [];
    $orders = isset($alx_shopper_current_filter_config['orders']) ? $alx_shopper_current_filter_config['orders'] : [];
} else {
    $num = intval(get_option('alx_shopper_num_dropdowns', 2));
    $titles = get_option('alx_shopper_dropdown_titles', []);
    $mapping = get_option('alx_shopper_dropdown_attributes', []);
    $values = get_option('alx_shopper_dropdown_values', []);
    $orders = get_option('alx_shopper_dropdown_value_order', []);
}
?>
<form class="alx-shopper-form" method="post" action="" data-enable-email-results="<?php echo !empty($alx_shopper_current_filter_config['enable_email_results']) ? '1' : '0'; ?>">
    <?php for ($i = 0; $i < $num; $i++): ?>
        <?php
        $title = isset($titles[$i]) ? esc_html($titles[$i]) : 'Dropdown '.($i+1);
        $attr = isset($mapping[$i]) ? $mapping[$i] : '';
        $allowed_values = isset($values[$i]) ? (array)$values[$i] : [];
        $order = isset($orders[$i]) ? (array)$orders[$i] : $allowed_values;

        // Get terms in the correct order
        $terms = [];
        if ($attr && !empty($allowed_values)) {
            $terms = get_terms([
                'taxonomy' => $attr,
                'include' => $allowed_values,
                'hide_empty' => false,
            ]);
            // Order terms as per $order
            $ordered_terms = [];
            foreach ($order as $term_id) {
                foreach ($terms as $term) {
                    if ($term->term_id == $term_id) {
                        $ordered_terms[] = $term;
                    }
                }
            }
            // Add any missing terms (in case of new selections)
            foreach ($terms as $term) {
                if (!in_array($term, $ordered_terms)) {
                    $ordered_terms[] = $term;
                }
            }
            $terms = $ordered_terms;
        }
        ?>
        <div class="alx-shopper-dropdown">
            <label for="alx_dropdown_<?php echo $i; ?>"><strong><?php echo $title; ?></strong></label>
            <select name="alx_dropdown_<?php echo $i; ?>" id="alx_dropdown_<?php echo $i; ?>" class="alx-attribute-dropdown" data-label="<?php echo $title; ?>">
                <?php
                // "Any" option
                if (isset($order[0]) && $order[0] === 'any') {
                    echo '<option value="any">Any</option>';
                }
                foreach ($terms as $term) {
                    echo '<option value="'.esc_attr($term->term_id).'">'.esc_html($term->name).'</option>';
                }
                ?>
            </select>
            <input type="hidden" name="alx_dropdown_<?php echo $i; ?>_attribute" value="<?php echo esc_attr($attr); ?>">
            <input type="hidden" name="alx_dropdown_<?php echo $i; ?>_label" value="<?php echo esc_attr($title); ?>">
        </div>
        <br>
    <?php endfor; ?>

    <input type="hidden" name="alx_filter_id" value="<?php echo esc_attr($alx_shopper_current_filter_id ?? 'default'); ?>">

    <button type="submit" class="alx-shopper-btn alx-shopper-search-btn">Search</button>
        <div id="alx-location-consent-info" style="margin-bottom:10px;font-size:0.55em;color:#003366;background:#eaf4ff;padding:10px 14px;border-radius:7px;">
        We may ask for your location in order to help direct you to your nearest retailer, therefore please accept consent to use your location services
    </div>
    <span class="alx-shopper-spinner" style="display:none;">Loading...</span>
    <div class="alx-shopper-message"></div>
</form>
