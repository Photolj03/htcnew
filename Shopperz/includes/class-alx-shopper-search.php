<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class Alx_Shopper_Search {
    public function __construct() {
        add_action('wp_ajax_alx_shopper_filter', [$this, 'ajax_find_products_with_relaxation']);
        add_action('wp_ajax_nopriv_alx_shopper_filter', [$this, 'ajax_find_products_with_relaxation']);
    }

    public function ajax_find_products_with_relaxation() {
        // Allow filtering of max suggestions and posts per query
        $max_suggestions = apply_filters('alx_shopper_max_suggestions', 3);

        // Get filter config from CPT
        $filter_id = isset($_POST['alx_filter_id']) ? sanitize_key($_POST['alx_filter_id']) : 'default';
        $config = function_exists('alx_shopper_get_filter_config') ? alx_shopper_get_filter_config($filter_id) : false;
        if (!$config) {
            wp_send_json_error(['message' => 'Invalid filter configuration.']);
        }

        // 1. Collect filters in order
        $filter_keys = [];
        $filter_vals = [];
        $filter_labels = [];
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'alx_dropdown_') === 0 && strpos($key, '_attribute') === false && strpos($key, '_label') === false) {
                $attr_key = isset($_POST[$key . '_attribute']) ? sanitize_text_field($_POST[$key . '_attribute']) : '';
                $attr_val = sanitize_text_field($value);
                $label = isset($_POST[$key . '_label']) && !empty($_POST[$key . '_label'])
                    ? sanitize_text_field($_POST[$key . '_label'])
                    : ucwords(str_replace(['pa_', '_'], ['', ' '], $attr_key));
                // Use term ID directly
                if ($attr_key && $attr_val !== '' && $attr_val !== 'any') {
                    $filter_keys[] = $attr_key;
                    $filter_vals[] = intval($attr_val);
                    $filter_labels[] = $label;
                }
            }
        }

        $categories = isset($config['categories']) ? $config['categories'] : [];
        if (!is_array($categories)) {
            $categories = [$categories];
        }

        $suggestions = [];
        $used_ids = [];
        $explanations = [];
        $relaxation_used = false;

        // 2. Strict match
        $filters = [];
        foreach ($filter_keys as $i => $key) {
            if ($key && $filter_vals[$i]) $filters[$key] = $filter_vals[$i];
        }
        $strict_products = $this->find_products_with_relaxation($filters, $categories);

        // Add all exact matches
        foreach ($strict_products as $pid) {
            $suggestions[] = $pid;
            $used_ids[$pid] = true;
            $explanations[$pid] = 'Exact match for your requirements.';
        }

        $num_exact = count($strict_products);

        // 3. Relax one filter at a time (from last to first), each filter up to two times
        $num_filters = count($filter_keys);
        $relaxed_filters_count = array_fill_keys($filter_keys, 0);

        for ($relax = $num_filters - 1; $relax >= 0 && count($suggestions) < $max_suggestions + $num_exact; $relax--) {
            if (!$filter_keys[$relax] || !$filter_vals[$relax]) continue;
            $filter_key = $filter_keys[$relax];
            $original_val = $filter_vals[$relax];

            // Get all term objects, sorted by name as number
            $terms = get_terms(['taxonomy' => $filter_key, 'hide_empty' => false]);
            if (is_wp_error($terms) || empty($terms)) continue;
            usort($terms, function($a, $b) {
                if (is_numeric($a->name) && is_numeric($b->name)) {
                    return floatval($a->name) - floatval($b->name);
                }
                return strnatcasecmp($a->name, $b->name);
            });

            $current_term = get_term($original_val, $filter_key);
            $current_val = $current_term && is_numeric($current_term->name) ? floatval($current_term->name) : $current_term->name;

            // Sort all terms by closeness to the current value (skip the current value itself)
            $other_terms = array_filter($terms, function($t) use ($current_term) {
                return $t->term_id != $current_term->term_id;
            });
            usort($other_terms, function($a, $b) use ($current_val) {
                $a_val = is_numeric($a->name) ? floatval($a->name) : $a->name;
                $b_val = is_numeric($b->name) ? floatval($b->name) : $b->name;
                if (is_numeric($current_val) && is_numeric($a_val) && is_numeric($b_val)) {
                    // Prioritize increases, then decreases, both sorted by smallest difference
                    $a_diff = $a_val - $current_val;
                    $b_diff = $b_val - $current_val;
                    if ($a_diff >= 0 && $b_diff < 0) return -1; // a is increase, b is decrease
                    if ($a_diff < 0 && $b_diff >= 0) return 1;  // b is increase, a is decrease
                    // Both increase or both decrease: sort by absolute difference
                    return abs($a_diff) - abs($b_diff);
                }
                return strnatcasecmp($a->name, $b->name);
            });

            $relaxed_count = 0;
            foreach ($other_terms as $term) {
                if ($relaxed_count >= 2) break; // Only relax this filter twice
                $term_id = $term->term_id;
                $relaxed = $filters;
                $relaxed[$filter_key] = $term_id;
                $products = $this->find_products_with_relaxation($relaxed, $categories);
                foreach ($products as $pid) {
                    if (!isset($used_ids[$pid])) {
                        $from_term = $current_term;
                        $to_term = $term;
                        $explanations[$pid] = 'Changed "' . $filter_labels[$relax] . '" from "' . ($from_term ? $from_term->name : '') . '" to "' . ($to_term ? $to_term->name : '') . '".';
                        $suggestions[] = $pid;
                        $used_ids[$pid] = true;
                        $relaxation_used = true;
                        $relaxed_count++;
                        if (count($suggestions) >= $max_suggestions + $num_exact) break 3;
                        break; // Only one suggestion per term change
                    }
                }
            }
        }

        // 4. If still not enough, relax filters by dropping more at a time (partial matches)
        $drop_level = 1;
        while (
            count($suggestions) < max($max_suggestions + $num_exact, $num_exact + 3)
            && $num_filters - 1 >= $drop_level // Only drop from filters after the first
            && $drop_level <= $num_filters - 1
        ) {
            // Only use indices 1 and above for dropping
            $drop_combinations = $this->get_combinations(range(1, $num_filters - 1), $drop_level);
            if (empty($drop_combinations)) break; // Prevent infinite loop if no combinations
            foreach ($drop_combinations as $drop_keys) {
                $partial_filters = $filters;
                foreach ($drop_keys as $drop_key) {
                    unset($partial_filters[$filter_keys[$drop_key]]);
                }
                $products = $this->find_products_with_relaxation($partial_filters, $categories);
                foreach ($products as $pid) {
                    if (!isset($used_ids[$pid])) {
                        $dropped_labels = implode(', ', array_map(function($k) use ($filter_labels, $filter_keys) {
                            $idx = array_search($k, $filter_keys);
                            return $filter_labels[$idx];
                        }, array_map(function($i) use ($filter_keys) { return $filter_keys[$i]; }, $drop_keys)));
                        $explanations[$pid] = 'Partial match (ignored "' . $dropped_labels . '").';
                        $suggestions[] = $pid;
                        $used_ids[$pid] = true;
                        $relaxation_used = true;
                        if (count($suggestions) >= max($max_suggestions + $num_exact, $num_exact + 3)) break 3;
                    }
                }
                if (count($suggestions) >= max($max_suggestions + $num_exact, $num_exact + 3)) break 2;
            }
            $drop_level++;
        }



        // 6. Build results
        $results = [];
        $num_suggested = 0;
        $num_exact = 0;

        // Separate exact and suggested/partial matches
        $exact_results = [];
        $suggested_results = [];

        foreach ($suggestions as $product_id) {
            $product = wc_get_product($product_id);
            $result = [
                'id'        => $product_id,
                'title'     => get_the_title($product_id),
                'permalink' => get_permalink($product_id),
                'image'     => get_the_post_thumbnail_url($product_id, 'medium'),
                'price_html'=> $product ? $product->get_price_html() : '',
                'explanation' => isset($explanations[$product_id]) ? $explanations[$product_id] : '',
            ];
            if (isset($explanations[$product_id]) && strpos($explanations[$product_id], 'Exact match') !== false) {
                $exact_results[] = $result;
            } else {
                $suggested_results[] = $result;
            }
        }

        // Always show all exact matches first
        $results = $exact_results;

        // If less than 3 exact, fill with suggested/partial up to 4 total
        if (count($exact_results) < 3) {
            $needed = 4 - count($exact_results);
            $results = array_merge($exact_results, array_slice($suggested_results, 0, $needed));
        } elseif (count($exact_results) >= 3 && count($suggested_results) > 0) {
            // If 3 or more exact, append suggested/partial up to 4 total
            $needed = 4 - count($exact_results);
            if ($needed > 0) {
                $results = array_merge($exact_results, array_slice($suggested_results, 0, $needed));
            }
            // If more than 4 exact, just show all exact (no suggested)
            // $results is already $exact_results
        } else if (count($results) > 4) {
            $results = array_slice($results, 0, 4);
        }

        // Update counts for message
        $num_exact = count($exact_results);
        $num_suggested = count($results) - $num_exact;

        $message = '';
        if ($num_exact > 0) {
            $message = "We found {$num_exact} exact match" . ($num_exact > 1 ? 'es' : '') .
                ($num_suggested > 0 ? " and {$num_suggested} suggested match" . ($num_suggested > 1 ? 'es' : '') : '') . '.';
        } elseif ($num_suggested > 0) {
            $message = "We found {$num_suggested} suggested match" . ($num_suggested > 1 ? 'es' : '') . '.';
        } else {
            $message = "No products found.";
        }

        wp_send_json_success([
            'results' => $results,
            'relaxation_used' => $relaxation_used,
            'message' => $message,
            'num_exact' => $num_exact,
            'num_suggested' => $num_suggested,
        ]);
    }

    // Helper to get all term IDs for a taxonomy
    private function get_all_terms_for_tax($taxonomy, $sort_by_closeness = false, $current_val = null) {
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
        if (is_wp_error($terms) || empty($terms)) return [];
        if ($sort_by_closeness && $current_val !== null) {
            usort($terms, function($a, $b) use ($current_val) {
                $a_val = is_numeric($a->name) ? floatval($a->name) : $a->name;
                $b_val = is_numeric($b->name) ? floatval($b->name) : $b->name;
                if (is_numeric($current_val) && is_numeric($a_val) && is_numeric($b_val)) {
                    return abs($a_val - $current_val) - abs($b_val - $current_val);
                }
                return strnatcasecmp($a->name, $b->name);
            });
        }
        return $terms;
    }

    public function find_products_with_relaxation($filters = [], $categories = []) {
        $tax_query = [];

        foreach ($filters as $taxonomy => $term_id) {
            if (empty($taxonomy) || empty($term_id)) continue;
            $tax_query[] = [
                'taxonomy' => $taxonomy,
                'field'    => 'term_id',
                'terms'    => [$term_id],
            ];
        }

        if (!empty($categories)) {
            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => $categories,
            ];
        }

        if (count($tax_query) > 1) {
            $tax_query['relation'] = 'AND';
        }

        $posts_per_page = apply_filters('alx_shopper_posts_per_page', 12);

        $args = [
            'post_type'      => 'product',
            'posts_per_page' => $posts_per_page,
            'post_status'    => 'publish',
            'tax_query'      => $tax_query,
            'fields'         => 'ids',
        ];

        $query = new WP_Query($args);
        return $query->posts;
    }

    // Helper function to generate combinations
    private function get_combinations($array, $k) {
        $results = [];
        $n = count($array);
        if ($k > $n || $k <= 0) return $results;
        $indices = range(0, $k - 1);
        while (true) {
            $results[] = array_map(function($i) use ($array) { return $array[$i]; }, $indices);
            // Find the rightmost index to increment
            for ($i = $k - 1; $i >= 0; $i--) {
                if ($indices[$i] != $i + $n - $k) break;
            }
            if ($i < 0) break;
            $indices[$i]++;
            for ($j = $i + 1; $j < $k; $j++) {
                $indices[$j] = $indices[$j - 1] + 1;
            }
        }
        return $results;
    }
}
