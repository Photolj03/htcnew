<?php
global $alx_shopper_current_filter_config, $alx_shopper_current_filter_id;

// Prefer CPT config if available
if ($alx_shopper_current_filter_config && is_array($alx_shopper_current_filter_config)) {
    $num = intval($alx_shopper_current_filter_config['num']);
    $mapping = $alx_shopper_current_filter_config['mapping'];
    $categories = isset($alx_shopper_current_filter_config['categories']) ? (array)$alx_shopper_current_filter_config['categories'] : [];
} else {
    $num = intval(get_option('alx_shopper_num_dropdowns', 2));
    $mapping = get_option('alx_shopper_dropdown_attributes', []);
    $categories = (array) get_option('alx_shopper_categories', []);
}

$tax_query = [];

// Filter by selected categories
if (!empty($categories)) {
    $tax_query[] = [
        'taxonomy' => 'product_cat',
        'field'    => 'term_id',
        'terms'    => $categories,
    ];
}

// Filter by each dropdown (attribute)
for ($i = 0; $i < $num; $i++) {
    $attr = isset($mapping[$i]) ? $mapping[$i] : '';
    $val = isset($_POST["alx_dropdown_$i"]) ? sanitize_text_field($_POST["alx_dropdown_$i"]) : '';
    if ($attr !== '' && (string)$val !== '' && (string)$val !== 'any') {
        // Convert term name to term ID
        $term = get_term_by('name', $val, $attr);
        if ($term && !is_wp_error($term)) {
            $tax_query[] = [
                'taxonomy' => $attr,
                'field'    => 'term_id',
                'terms'    => [$term->term_id],
            ];
        }
    }
}

$args = [
    'post_type'      => 'product',
    'posts_per_page' => 12,
    'tax_query'      => $tax_query,
];

$products = new WP_Query($args);
?>

<?php if ($products->have_posts()) : ?>
  <?php while ($products->have_posts()) : $products->the_post(); global $product; $product = wc_get_product(get_the_ID()); ?>
    <div class="alx-shopper-product">
      <a href="<?php the_permalink(); ?>">
        <?php echo $product->get_image(); ?>
        <h3><?php the_title(); ?></h3>
      </a>
      <div class="alx-shopper-price"><?php echo $product->get_price_html(); ?></div>
      <div class="alx-shopper-actions">
          <a href="<?php the_permalink(); ?>" class="alx-shopper-btn alx-view-product-btn" target="_blank">View Product</a>
          
          <button class="alx-shopper-btn alx-add-to-cart-btn" data-id="<?php echo get_the_ID(); ?>">Add to Cart</button>
      </div>
    </div>
  <?php endwhile; ?>
  <?php wp_reset_postdata(); ?>
<?php else : ?>
  <p>No products found.</p>
<?php endif; ?>
