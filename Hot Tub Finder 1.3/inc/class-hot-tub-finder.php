<?php

if (!defined('ABSPATH')) exit;

class Hot_Tub_Finder {
    public static function init() {
        add_shortcode('hot_tub_finder', [__CLASS__, 'shortcode_dispatcher']);

        if (self::is_woocommerce_active()) {
            add_action('rest_api_init', [ __CLASS__, 'register_rest_route' ]);
            add_action('rest_api_init', [ __CLASS__, 'register_email_results_route' ]);
            add_action('wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ]);
        } else {
            add_action('admin_notices', function() {
                $screen = function_exists('get_current_screen') ? get_current_screen() : null;
                if ($screen && $screen->id === "toplevel_page_hot-tub-finder") return;
                if (isset($_SERVER['PHP_SELF']) && basename($_SERVER['PHP_SELF']) === 'index.php') return;
                echo '<div class="notice notice-error"><p><strong>Hot Tub Finder:</strong> WooCommerce must be installed and activated.</p></div>';
            });
        }
    }

    protected static function is_woocommerce_active() {
        return class_exists('WooCommerce') && function_exists('wc_get_product');
    }

    public static function shortcode_dispatcher($atts = [], $content = null) {
        if (!self::is_woocommerce_active()) {
            return '<div class="htf-error" style="color: #b32d2e; background: #fff0f1; border: 1px solid #b32d2e; padding: 10px; border-radius: 4px; margin: 10px 0;"><strong>Hot Tub Finder requires WooCommerce to be activated.</strong></div>';
        }
        return self::shortcode($atts, $content);
    }

    public static function register_rest_route() {
        register_rest_route('custom-api/v1', '/hot-tubs', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_hot_tubs'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function register_email_results_route() {
        register_rest_route('custom-api/v1', '/hot-tub-email', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'email_results'],
            'permission_callback' => '__return_true',
            'args' => [
                'email' => [ 'required' => true ],
                'results_html' => [ 'required' => true ]
            ]
        ]);
    }

    public static function get_hot_tubs($request) {
        $args = [
            'post_type' => 'product',
            'posts_per_page' => -1,
        ];
        $query = new WP_Query($args);
        $products = [];

        while ($query->have_posts()) {
            $query->the_post();
            $product = wc_get_product(get_the_ID());
            if (!$product || !$product->is_type('simple')) continue;

            $attributes = $product->get_attributes();
            $product_data = [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'price' => $product->get_price(),
                'regular_price' => $product->get_regular_price(),
                'sale_price' => $product->get_sale_price(),
                'url' => get_permalink($product->get_id()),
                'attributes' => [],
                'image_url' => get_the_post_thumbnail_url(get_the_ID(), 'large'),
            ];

            foreach ($attributes as $key => $attribute) {
                $name = $attribute->get_name();
                $options = $attribute->get_options();
                $slug = strtolower(str_replace(' ', '_', $name));
                if ($slug === 'power_supply') {
                    $product_data['attributes'][$slug] = strtolower(implode(' | ', $options));
                } else {
                    $product_data['attributes'][$slug] = strtolower($options[0]);
                }
                if ($slug === 'lounger') {
                    $product_data['attributes']['lounger'] = (string) $options[0];
                }
            }

            $products[] = $product_data;
        }
        wp_reset_postdata();
        return rest_ensure_response($products);
    }

    public static function email_results($request) {
        $email = sanitize_text_field($request->get_param('email'));
        $results_html = wp_kses_post($request->get_param('results_html'));

        // Accept multiple emails separated by comma or semicolon
        $emails = array_filter(array_map('trim', preg_split('/[,;]/', $email)));
        foreach ($emails as $address) {
            if (!is_email($address)) {
                return new WP_Error('invalid', 'Invalid email: ' . esc_html($address), array('status' => 400));
            }
        }

        $subject = 'Hot Tub Finder Results';

        $html = $results_html;
        $html = preg_replace('/<button[^>]*htf-quick-view-btn[^>]*>.*?<\/button>/is', '', $html);
        $html = preg_replace('/<div class="finder-modal".*?<\/div>/is', '', $html);
        $html = preg_replace('/<(script|style)[^>]*>[\s\S]*?<\/\1>/i', '', $html);

        $html = preg_replace('/class="htf-product"/', 'style="background:#e9f1fb;border-radius:20px;border:1.5px solid #b9d2eb;margin:32px 0;padding:18px 20px;display:flex;align-items:flex-start;gap:24px;"', $html);
        $html = preg_replace('/class="htf-product-image-outer"/', '', $html);
        $html = preg_replace('/class="htf-product-image"/', '', $html);
        $html = preg_replace('/<img /', '<img style="max-width:130px;max-height:130px;border-radius:13px;" ', $html);
        $html = preg_replace('/class="htf-product-details"/', '', $html);
        $html = preg_replace('/<h3>/', '<div style="font-size:1.18rem;color:#223057;font-weight:800;margin:0 0 10px 0;">', $html);
        $html = preg_replace('/<\/h3>/', '</div>', $html);
        $html = preg_replace('/class="htf-product-attrs"/', 'style="display:flex;gap:10px;margin-bottom:10px;flex-wrap:wrap;"', $html);
        $html = preg_replace('/class="htf-product-price"/', 'style="font-size:1.08rem;font-weight:800;color:#223057;margin-bottom:12px;letter-spacing:.7px;text-align:left;"', $html);
        $html = preg_replace('/class="htf-suggestion"/', 'style="background:#fffbe6;color:#bb9700;border:1.5px solid #e8d06c;border-radius:11px;padding:18px 18px 12px 18px;font-size:1.12rem;font-weight:700;margin-bottom:18px;text-align:center;"', $html);
        $html = preg_replace('/class="htf-empty"/', 'style="background:#f5f6fb;color:#4E88C7;border-radius:11px;padding:32px 0;font-size:1.18rem;font-weight:700;text-align:center;margin-top:18px;box-shadow:0 2px 8px rgba(79,136,199,0.07);"', $html);
        $html = preg_replace('/class="htf-btn htf-btn-main"/', 'style="background:linear-gradient(90deg,#4e88c7 0%,#1e3a5c 100%);color:#fff !important;border-radius:11px;padding:8px 22px;font-weight:800;text-decoration:none;margin-right:6px;display:inline-block;"', $html);
        $html = preg_replace('/class="htf-btn htf-btn-alt"/', 'style="background:#e6f0fa;color:#4E88C7 !important;border-radius:11px;padding:8px 22px;font-weight:800;text-decoration:none;border:2px solid #4E88C7;"', $html);
        $html = preg_replace('/ class="[^"]*"/', '', $html);

        $body = '<html><head>';
        $body .= '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />';
        $body .= '</head><body>';
        $body .= '<div style="background:#f9fbfd;border-radius:24px;padding:30px;margin:0 auto;max-width:800px;border:1px solid #e6eaf1;font-family:\'Segoe UI\',\'Roboto\',Arial,sans-serif;color:#223057;">';
        $body .= '<h2 style="text-align:center;">Hot Tub Finder Results</h2>';
        $body .= '<div style="margin-bottom:24px;">Thank you for using the Hot Tub Finder. Here are your results:</div>';
        $body .= $html;
        $body .= '<div style="margin-top:30px;font-size:13px;color:#888;">This message was sent from the Hot Tub Finder on <a href="' . esc_url(home_url()) . '">' . esc_html(home_url()) . '</a></div>';
        $body .= '</div></body></html>';

        $headers = array('Content-Type: text/html; charset=UTF-8');

        add_filter('wp_mail_from_name', function($name) { return 'Hot Tub Finder Results'; });
        add_filter('wp_mail_from', function($email) {
            $domain = parse_url(home_url(), PHP_URL_HOST);
            $domain = preg_replace('/^www\./', '', $domain);
            return 'noreply@' . $domain;
        });

        $all_sent = true;
        foreach ($emails as $address) {
            $sent = wp_mail($address, $subject, $body, $headers);
            if (!$sent) $all_sent = false;
        }

        remove_all_filters('wp_mail_from_name');
        remove_all_filters('wp_mail_from');

        if ($all_sent) {
            return rest_ensure_response(['success' => true]);
        } else {
            return new WP_Error('email_failed', 'Failed to send email to one or more recipients', array('status' => 500));
        }
    }

    public static function shortcode($atts = [], $content = null) {
        $seat_opts = get_option('htf_seat_options', '2,3,4,5,6,7,12');
        $power_opts = get_option('htf_power_options', '13,20,32');
        $btn_grad_start = get_option('htf_button_gradient_start', '#4e88c7');
        $btn_grad_end = get_option('htf_button_gradient_end', '#1e3a5c');
        $btn_text_size = get_option('htf_button_text_size', '16');
        $btn_font = get_option('htf_button_font', 'inherit');
        $show_email_input = get_option('htf_show_email_input', 1) ? 'true' : 'false';
        ob_start();
        ?>
        <style>
        .htf-btn, .htf-btn-main, .htf-btn-alt {
            background: linear-gradient(90deg, <?php echo esc_attr($btn_grad_start); ?> 0%, <?php echo esc_attr($btn_grad_end); ?> 100%);
            font-size: <?php echo intval($btn_text_size); ?>px;
            font-family: <?php echo esc_attr($btn_font); ?>;
        }
        .htf-suggestion {
            background: #fffbe6;
            color: #bb9700;
            border: 1.5px solid #e8d06c;
            border-radius: 11px;
            padding: 18px 18px 12px 18px;
            font-size: 1.12rem;
            font-weight: 700;
            margin-bottom: 18px;
            text-align: center;
        }
        .htf-email-capture-row {
            display: flex;
            gap: 8px;
            align-items: center;
            margin: 18px 0 24px 0;
            justify-content: center;
            width: 100%;
        }
        .htf-email-capture-inner {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
        }
        .htf-email-capture-inputbar {
            display: flex;
            gap: 8px;
            width: 100%;
            justify-content: center;
        }
        .htf-email-capture-row input[type="text"].htf-email-yellow {
            padding: 8px 11px;
            border-radius: 7px;
            border: 1.5px solid #b7cbe0;
            font-size: 1rem;
            font-family: inherit;
            outline: none;
            min-width: 170px;
            width: 320px;
            background: #fffbe6;
            color: #223057;
        }
        .htf-email-capture-row input[type="text"].htf-email-yellow:focus {
            border-color: #4E88C7;
        }
        .htf-email-capture-row button {
            padding: 8px 16px;
            border-radius: 7px;
            font-size: 1rem;
            border: none;
            background: #4E88C7;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
        }
        .htf-email-capture-row button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .htf-email-instruction {
            margin-top: 10px;
            text-align: center;
            color: #444;
            font-size: 1.05rem;
            font-weight: 500;
            max-width: 410px;
            line-height: 1.4;
        }
        .htf-email-popup {
            position: fixed;
            z-index: 999999;
            right: 30px;
            bottom: 30px;
            background: #4E88C7;
            color: #fff;
            padding: 15px 28px;
            border-radius: 16px;
            font-size: 1.11rem;
            font-weight: 600;
            box-shadow: 0 2px 16px rgba(30,60,90,0.09);
            display: none;
            animation: htfPopIn 0.25s;
        }
        @keyframes htfPopIn {
            0% { transform: translateY(40px) scale(0.96); opacity: 0.3;}
            100% { transform: translateY(0) scale(1); opacity: 1;}
        }
        </style>
        <script>
        var htf_SEAT_OPTIONS = "<?php echo esc_attr($seat_opts); ?>".split(",").map(x=>x.trim());
        var htf_POWER_OPTIONS = "<?php echo esc_attr($power_opts); ?>".split(",").map(x=>x.trim());
        window.htf_SHOW_EMAIL_INPUT = <?php echo $show_email_input; ?>;

        function htfPopulateSelects() {
            var seatSel = document.getElementById('htf-seats');
            seatSel.innerHTML = '<option value="">Please select</option>';
            htf_SEAT_OPTIONS.forEach(function(seat) {
                seatSel.innerHTML += '<option value="'+seat+'">'+seat+'</option>';
            });
            var powerSel = document.getElementById('htf-power');
            powerSel.innerHTML = '<option value="">Please select</option>';
            htf_POWER_OPTIONS.forEach(function(p) {
                powerSel.innerHTML += '<option value="'+p+'">'+p+' Amp</option>';
            });
        }
        document.addEventListener('DOMContentLoaded', htfPopulateSelects);

        function htfValidatefinderForm() {
            const seats = document.getElementById('htf-seats').value;
            const power = document.getElementById('htf-power').value;
            const lounger = document.getElementById('htf-lounger').value;
            const btn = document.getElementById('htf-search-btn');
            if(seats && power && lounger) {
                btn.disabled = false;
                btn.classList.remove('finder-btn-disabled');
            } else {
                btn.disabled = true;
                btn.classList.add('finder-btn-disabled');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            htfValidatefinderForm();
        });

        document.addEventListener('click', function(event) {
            if (event.target.classList.contains('finder-modal-close')) {
                let modal = event.target.closest('.finder-modal');
                if (modal) modal.style.display = 'none';
            }
            if (event.target.classList.contains('finder-modal')) {
                event.target.style.display = 'none';
            }
        });

        async function htfFetchProducts() {
            document.getElementById('htf-loading').style.display = 'block';
            const response = await fetch('<?php echo esc_url( home_url( '/wp-json/custom-api/v1/hot-tubs' ) ); ?>');
            const data = await response.json();
            document.getElementById('htf-loading').style.display = 'none';
            return data;
        }

        function htfFilterProducts(products, seatFilter, powerFilter, loungerFilter) {
            return products.filter(product => {
                const attrs = product.attributes;
                const seatMatch = !seatFilter || attrs['number_of_seats'] == seatFilter;
                let powerMatch = true;
                if (powerFilter) {
                    if (attrs['power_supply']) {
                        const powers = attrs['power_supply'].split('|').map(v => v.trim());
                        powerMatch = powers.includes(powerFilter);
                    } else {
                        powerMatch = false;
                    }
                }
                const loungerMatch = !loungerFilter || attrs['lounger'] == loungerFilter;
                return seatMatch && powerMatch && loungerMatch;
            });
        }

        function htfGetSuggestedProducts(products, seatFilter, powerFilter, loungerFilter) {
            let suggestions = [];
            const seatOptsNum = htf_SEAT_OPTIONS.map(Number).sort((a, b) => a - b);
            const originalSeat = parseInt(seatFilter, 10);

            for (let i = seatOptsNum.indexOf(originalSeat) + 1; i < seatOptsNum.length; i++) {
                let nextSeat = seatOptsNum[i].toString();
                suggestions = htfFilterProducts(products, nextSeat, powerFilter, loungerFilter);
                if (suggestions.length > 0) {
                    return { type: "seat", value: nextSeat, direction: "up", products: suggestions };
                }
            }
            for (let i = seatOptsNum.indexOf(originalSeat) - 1; i >= 0; i--) {
                let prevSeat = seatOptsNum[i].toString();
                suggestions = htfFilterProducts(products, prevSeat, powerFilter, loungerFilter);
                if (suggestions.length > 0) {
                    return { type: "seat", value: prevSeat, direction: "down", products: suggestions };
                }
            }
            if (powerFilter && htf_POWER_OPTIONS && htf_POWER_OPTIONS.length > 1) {
                for (let altPower of htf_POWER_OPTIONS) {
                    if (altPower === powerFilter) continue;
                    suggestions = htfFilterProducts(products, seatFilter, altPower, loungerFilter);
                    if (suggestions.length > 0) {
                        return { type: "power", value: altPower, products: suggestions };
                    }
                }
            }
            for (let i = seatOptsNum.indexOf(originalSeat) + 1; i < seatOptsNum.length; i++) {
                let nextSeat = seatOptsNum[i].toString();
                for (let altPower of htf_POWER_OPTIONS) {
                    if (altPower === powerFilter) continue;
                    suggestions = htfFilterProducts(products, nextSeat, altPower, loungerFilter);
                    if (suggestions.length > 0) {
                        return { type: "seat_power", value: { seat: nextSeat, power: altPower, direction: "up" }, products: suggestions };
                    }
                }
            }
            for (let i = seatOptsNum.indexOf(originalSeat) - 1; i >= 0; i--) {
                let prevSeat = seatOptsNum[i].toString();
                for (let altPower of htf_POWER_OPTIONS) {
                    if (altPower === powerFilter) continue;
                    suggestions = htfFilterProducts(products, prevSeat, altPower, loungerFilter);
                    if (suggestions.length > 0) {
                        return { type: "seat_power", value: { seat: prevSeat, power: altPower, direction: "down" }, products: suggestions };
                    }
                }
            }
            return null;
        }

        function htfBuildEmailHTML(resultsDiv) {
            let html = resultsDiv.innerHTML;
            html = html.replace(/<div class="htf-email-capture-row"[\s\S]*?<\/div>/, '');
            html = html.replace(/<div class="finder-modal"[\s\S]*?<\/div>/, '');
            html = html.replace(/<button[^>]*class="[^"]*htf-quick-view-btn[^"]*"[^>]*>[\s\S]*?<\/button>/g, '');
            html = html.replace(/class="htf-product"/g, 'style="background:#e9f1fb;border-radius:20px;border:1.5px solid #b9d2eb;margin:32px 0;padding:18px 20px;display:flex;align-items:flex-start;gap:24px;"');
            html = html.replace(/class="htf-product-image-outer"/g, '');
            html = html.replace(/class="htf-product-image"/g, '');
            html = html.replace(/<img /g, '<img style="max-width:130px;max-height:130px;border-radius:13px;" ');
            html = html.replace(/class="htf-product-details"/g, '');
            html = html.replace(/<h3>/g, '<div style="font-size:1.18rem;color:#223057;font-weight:800;margin:0 0 10px 0;">').replace(/<\/h3>/g, '</div>');
            html = html.replace(/class="htf-product-attrs"/g, 'style="display:flex;gap:10px;margin-bottom:10px;flex-wrap:wrap;"');
            html = html.replace(/class="htf-product-attrs-modal"/g, 'style="display:flex;gap:10px;margin-bottom:10px;flex-wrap:wrap;"');
            html = html.replace(/class="htf-product-price"/g, 'style="font-size:1.08rem;font-weight:800;color:#223057;margin-bottom:12px;letter-spacing:.7px;text-align:left;"');
            html = html.replace(/class="htf-suggestion"/g, 'style="background:#fffbe6;color:#bb9700;border:1.5px solid #e8d06c;border-radius:11px;padding:18px 18px 12px 18px;font-size:1.12rem;font-weight:700;margin-bottom:18px;text-align:center;"');
            html = html.replace(/class="htf-empty"/g, 'style="background:#f5f6fb;color:#4E88C7;border-radius:11px;padding:32px 0;font-size:1.18rem;font-weight:700;text-align:center;margin-top:18px;box-shadow:0 2px 8px rgba(79,136,199,0.07);"');
            html = html.replace(/class="htf-btn htf-btn-main"/g, 'style="background:linear-gradient(90deg,#4e88c7 0%,#1e3a5c 100%);color:#fff !important;border-radius:11px;padding:8px 22px;font-weight:800;text-decoration:none;margin-right:6px;display:inline-block;"');
            html = html.replace(/class="htf-btn htf-btn-alt"/g, 'style="background:#e6f0fa;color:#4E88C7 !important;border-radius:11px;padding:8px 22px;font-weight:800;text-decoration:none;border:2px solid #4E88C7;"');
            html = html.replace(/ class="[^"]*"/g, '');
            return html;
        }

        async function htfEmailResults(email, resultsDiv) {
            const resultsHtml = htfBuildEmailHTML(resultsDiv);
            if (!resultsHtml.trim()) return false;
            const res = await fetch('<?php echo esc_url( home_url( '/wp-json/custom-api/v1/hot-tub-email' ) ); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: email, results_html: resultsHtml })
            });
            return res.ok;
        }

        async function htfLoadAndFilter() {
            const btn = document.getElementById('htf-search-btn');
            btn.classList.add('loading');
            btn.querySelector('.btn-label').style.display = 'none';
            btn.querySelector('.btn-spinner').style.display = 'inline-block';

            const seats = document.getElementById('htf-seats').value;
            const power = document.getElementById('htf-power').value;
            const lounger = document.getElementById('htf-lounger').value;

            const products = await htfFetchProducts();
            const filtered = htfFilterProducts(products, seats, power, lounger);
            const resultsDiv = document.getElementById('htf-results');
            resultsDiv.innerHTML = '';

            let hasResults = false;

            if (filtered.length === 0) {
                const suggestion = htfGetSuggestedProducts(products, seats, power, lounger);
                if (suggestion && suggestion.products.length > 0) {
                    hasResults = true;
                    let suggestionText = 'No exact match found, here is a close match to your criteria:';
                    if (suggestion.type === "seat") {
                        suggestionText += `<br><small>(We ${suggestion.direction === "up" ? "increased" : "decreased"} the seats to ${suggestion.value})</small>`;
                    } else if (suggestion.type === "power") {
                        suggestionText += `<br><small>(We changed the power to ${suggestion.value} Amp)</small>`;
                    } else if (suggestion.type === "seat_power") {
                        suggestionText += `<br><small>(We ${suggestion.value.direction === "up" ? "increased" : "decreased"} the seats to ${suggestion.value.seat} and changed the power to ${suggestion.value.power} Amp)</small>`;
                    }
                    resultsDiv.innerHTML = `<div class="htf-suggestion">${suggestionText}</div>`;
                    suggestion.products.forEach(p => {
                        resultsDiv.appendChild(htfMakeProductEl(p));
                    });
                } else {
                    resultsDiv.innerHTML = '<div class="htf-empty"><i class="fas fa-box-open"></i> No products match your criteria.</div>';
                }
            } else {
                hasResults = true;
                filtered.forEach(p => {
                    resultsDiv.appendChild(htfMakeProductEl(p));
                });
            }

            // EMAIL TOGGLE: Only show the email input if enabled in settings
            if (hasResults && window.htf_SHOW_EMAIL_INPUT) {
                const emailRow = document.createElement('div');
                emailRow.className = 'htf-email-capture-row';
                emailRow.innerHTML = `
                    <div class="htf-email-capture-inner">
                        <div class="htf-email-capture-inputbar">
                            <input type="text"
                                placeholder="Enter one or more emails"
                                id="htf-email-input"
                                class="htf-email-yellow" />
                            <button id="htf-email-btn" disabled>Email Results</button>
                        </div>
                        <div class="htf-email-instruction">
                            Send these results of your perfectly chosen hot tubs to yourself, your friends and/or your family.<br>
                            Separate emails with a comma (,)
                        </div>
                    </div>
                `;
                resultsDiv.prepend(emailRow);

                const emailInput = emailRow.querySelector('#htf-email-input');
                const emailBtn = emailRow.querySelector('#htf-email-btn');

                function validateEmails(input) {
                    return input.split(/[,;]/).map(e => e.trim()).filter(Boolean)
                        .every(e => /^[^@]+@[^@]+\.[^@]+$/.test(e));
                }

                emailInput.addEventListener('input', function() {
                    emailBtn.disabled = !validateEmails(emailInput.value);
                });

                emailBtn.addEventListener('click', async function(e) {
                    e.preventDefault();
                    emailBtn.disabled = true;
                    emailBtn.innerHTML = 'Sending...';
                    const sent = await htfEmailResults(emailInput.value, resultsDiv);
                    emailBtn.innerHTML = 'Email Results';
                    if (sent) {
                        htfShowEmailPopup("Results have been emailed!");
                        emailInput.value = '';
                        emailBtn.disabled = true;
                    } else {
                        htfShowEmailPopup("Failed to send email. Please try again.", true);
                    }
                });
            }

            btn.classList.remove('loading');
            btn.querySelector('.btn-label').style.display = 'inline-block';
            btn.querySelector('.btn-spinner').style.display = 'none';
        }

        function htfShowEmailPopup(msg, isError) {
            let popup = document.getElementById('htf-email-popup');
            if (!popup) {
                popup = document.createElement('div');
                popup.id = 'htf-email-popup';
                popup.className = 'htf-email-popup';
                document.body.appendChild(popup);
            }
            popup.textContent = msg;
            popup.style.background = isError ? '#b32d2e' : '#4E88C7';
            popup.style.display = 'block';
            setTimeout(() => { popup.style.display = 'none'; }, 3500);
        }

        function htfMakeProductEl(p) {
            const visibleImg = p.image_url ? p.image_url : 'https://via.placeholder.com/320x220?text=No+Image';
            const name = p.name ? p.name : '';
            let priceHtml = '';
            if (p.sale_price && p.sale_price != "0" && p.sale_price != p.regular_price) {
                priceHtml = `<span class="htf-product-price-sale">£${p.sale_price}</span> <span class="htf-product-price-regular"><s>£${p.regular_price}</s></span>`;
            } else {
                priceHtml = `<span class="htf-product-price-normal">£${p.regular_price}</span>`;
            }
            const el = document.createElement('div');
            el.className = 'htf-product';
            el.innerHTML = `
                <div class="htf-product-image-outer">
                    <div class="htf-product-image">
                        <img src="${visibleImg}" alt="${name}" loading="lazy" />
                    </div>
                </div>
                <div class="htf-product-details">
                    <h3>${name}</h3>
                    <div class="htf-product-attrs">
                        <span title="Seats"><i class="fas fa-users"></i> ${p.attributes.number_of_seats}</span>
                        <span title="Power"><i class="fas fa-bolt"></i> ${p.attributes.power_supply}A</span>
                        <span title="Lounger"><i class="fas fa-couch"></i> ${p.attributes.lounger}</span>
                    </div>
                    <div class="htf-product-price">${priceHtml}</div>
                    <div class="htf-product-actions">
                        <a href="${p.url}" target="_blank" class="htf-btn htf-btn-main"><i class="fas fa-store"></i> View Product</a>
                        <button type="button" class="htf-btn htf-btn-alt htf-quick-view-btn"><i class="fas fa-eye"></i> Quick View</button>
                    </div>
                </div>
            `;
            el.querySelector('.htf-quick-view-btn').onclick = () => {
                let modalPriceHtml = '';
                if (p.sale_price && p.sale_price != "0" && p.sale_price != p.regular_price) {
                    modalPriceHtml = `<span class="htf-product-price-sale">£${p.sale_price}</span> <span class="htf-product-price-regular"><s>£${p.regular_price}</s></span>`;
                } else {
                    modalPriceHtml = `<span class="htf-product-price-normal">£${p.regular_price}</span>`;
                }
                const modal = document.getElementById('htf-modal');
                document.getElementById('htf-modal-body').innerHTML = `
                    <h3>${name}</h3>
                    <img src="${visibleImg}" style="max-width:100%;margin:14px 0 22px 0;border-radius:13px;">
                    <div class="htf-product-attrs-modal">
                        <span><i class="fas fa-users"></i> ${p.attributes.number_of_seats} Seats</span>
                        <span><i class="fas fa-bolt"></i> ${p.attributes.power_supply}A</span>
                        <span><i class="fas fa-couch"></i> ${p.attributes.lounger} Lounger</span>
                    </div>
                    <div class="htf-product-price-modal">${modalPriceHtml}</div>
                    <a href="${p.url}" target="_blank" class="htf-btn htf-btn-main" style="margin-top:18px;"><i class="fas fa-store"></i> View Product</a>
                `;
                modal.style.display = 'flex';
            };
            return el;
        }
        </script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer" />
        <div class="htf-root" id="hot-tub-finder">
            <h1><span class="htf-highlight">Find Your Perfect Hot Tub</span></h1>
            <form class="finder-form-row" onsubmit="htfLoadAndFilter(); return false;">
                <div class="finder-field">
                    <label for="htf-seats"><i class="fas fa-users"></i> Seats</label>
                    <select id="htf-seats" onchange="htfValidatefinderForm()"></select>
                </div>
                <div class="finder-field">
                    <label for="htf-power"><i class="fas fa-bolt"></i> Power</label>
                    <select id="htf-power" onchange="htfValidatefinderForm()"></select>
                </div>
                <div class="finder-field">
                    <label for="htf-lounger"><i class="fas fa-couch"></i> Lounger</label>
                    <select id="htf-lounger" onchange="htfValidatefinderForm()">
                        <option value="">Please select</option>
                        <option value="0">No Lounger</option>
                        <option value="1">1 Lounger</option>
                        <option value="2">2 Loungers</option>
                    </select>
                </div>
                <div class="finder-field finder-search-btnfield">
                    <button id="htf-search-btn" type="submit" disabled>
                        <span class="btn-label"><i class="fas fa-search"></i> Search</span>
                        <span class="btn-spinner" style="display:none;"><i class="fas fa-spinner fa-spin"></i></span>
                    </button>
                </div>
            </form>
            <div id="htf-loading" style="display:none;text-align:center;margin:24px 0;">
                <i class="fas fa-spinner fa-spin" style="font-size:2rem;color:#4E88C7;"></i>
            </div>
            <div id="htf-results"></div>
        </div>
        <div class="finder-modal" id="htf-modal" style="display:none;">
            <div class="finder-modal-content">
                <span class="finder-modal-close" tabindex="0" role="button" aria-label="Close">&times;</span>
                <div id="htf-modal-body"></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function enqueue_assets() {
        wp_enqueue_style(
            'hot-tub-finder',
            plugins_url('assets/hot-tub-finder.css', dirname(__DIR__) . '/hot-tub-finder.php'),
            [],
            '2.4'
        );
    }
}