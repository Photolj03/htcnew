<?php
/**
 * Quick View Modal Template
 * Expects $product (WC_Product) and $product_id (int) to be set.
 */
if ( ! isset( $product ) || ! isset( $product_id ) ) {
    return;
}
?>
<div class="alx-quick-view-modal-inner">
    <div class="alx-quick-view-modal-content">
        <h2><?php echo esc_html( $product->get_name() ); ?></h2>
        <?php echo get_the_post_thumbnail( $product_id, 'large' ); ?>
        <div class="alx-quick-view-price"><?php echo $product->get_price_html(); ?></div>
    </div>
    <div class="alx-shopper-actions">
        <a href="<?php echo esc_url( get_permalink( $product_id ) ); ?>" class="alx-modal-view-product" target="_blank">View Full Product</a>
        <button class="alx-shopper-btn alx-modal-add-to-cart" data-id="<?php echo esc_attr( $product_id ); ?>">Add to Cart</button>
    </div>
</div>

