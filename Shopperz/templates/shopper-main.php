<?php
global $alx_shopper_current_filter_config, $alx_shopper_current_filter_id;
?>
<?php include plugin_dir_path(__FILE__) . 'shopper-form.php'; ?>

<div class="alx-shopper-wrapper">
    <!-- Optional: Quick view modal (if used globally) -->
    <div id="alx-quick-view-modal" style="display:none;">
        <div class="alx-quick-view-content">
            <span class="alx-quick-view-close">&times;</span>
            <div class="alx-quick-view-body"></div>
        </div>
    </div>

    <div class="alx-shopper-results"></div>
</div>

<!-- Add this to your email results form -->


