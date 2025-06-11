# A[LEE]X Shopper Plugin

## Description
A[LEE]X Shopper is a powerful WordPress plugin designed to enhance the shopping experience on WooCommerce sites. It allows users to search for products based on customizable attributes through a user-friendly interface featuring dropdowns, product images, and quick view options.

## Features
- **Customizable Product Search**: Users can search for products based on various WooCommerce attributes.
- **User-Friendly Interface**: The plugin provides an intuitive front-end experience with dropdowns and product images.
- **Quick View Option**: Users can quickly view product details without leaving the search page.
- **Analytics Capabilities**: Track user interactions and generate analytics data for better insights.

## Installation
1. Upload the `A[LEE]X-Shopper` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure the plugin settings as needed in the WooCommerce settings panel.

## Usage
- Navigate to the product search form provided by the plugin.
- Select the desired attributes from the dropdowns to filter products.
- Click on the product images or the quick view button to see more details.

## Requirements
- WordPress 5.0 or higher
- WooCommerce 3.0 or higher

## Support
For support, please contact the plugin developer at [support@example.com].

## Changelog
- **Version 1.0**: Initial release with product search functionality and analytics tracking.

add meta to product pages <?php if (is_product()) : global $product; ?>
<meta property="og:title" content="<?php echo esc_attr(get_the_title()); ?>" />
<meta property="og:description" content="<?php echo esc_attr(wp_strip_all_tags($product->get_short_description())); ?>" />
<meta property="og:image" content="<?php echo esc_url(get_the_post_thumbnail_url(get_the_ID(), 'large')); ?>" />
<meta property="og:url" content="<?php the_permalink(); ?>" />
<meta property="og:type" content="product" />

<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content="<?php echo esc_attr(get_the_title()); ?>" />
<meta name="twitter:description" content="<?php echo esc_attr(wp_strip_all_tags($product->get_short_description())); ?>" />
<meta name="twitter:image" content="<?php echo esc_url(get_the_post_thumbnail_url(get_the_ID(), 'large')); ?>" />
<?php endif; ?><?php if (is_product()) : global $product; ?>
<meta property="og:title" content="<?php echo esc_attr(get_the_title()); ?>" />
<meta property="og:description" content="<?php echo esc_attr(wp_strip_all_tags($product->get_short_description())); ?>" />
<meta property="og:image" content="<?php echo esc_url(get_the_post_thumbnail_url(get_the_ID(), 'large')); ?>" />
<meta property="og:url" content="<?php the_permalink(); ?>" />
<meta property="og:type" content="product" />

<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content="<?php echo esc_attr(get_the_title()); ?>" />
<meta name="twitter:description" content="<?php echo esc_attr(wp_strip_all_tags($product->get_short_description())); ?>" />
<meta name="twitter:image" content="<?php echo esc_url(get_the_post_thumbnail_url(get_the_ID(), 'large')); ?>" />
<?php endif; ?>