window.alxQuickViews = window.alxQuickViews || [];
window.alxProductViews = window.alxProductViews || [];
jQuery(document).ready(function($) {
    // --- FILTER FORM HANDLING ---
    $('.alx-shopper-form').each(function() {
        const $form = $(this);
        const $resultsContainer = $form.parent().find('.alx-shopper-results');
        const $messageContainer = $form.find('.alx-shopper-message');

        $form.on('submit', function(e) {
            e.preventDefault();

            // Collect selected filters and categories from this form
            const formData = new FormData(this);

            // Collect all filter values
            let filters = {};
            $form.find('input, select, textarea').each(function() {
                const $el = $(this);
                const name = $el.attr('name');
                if (!name) return;
                if (($el.is(':checkbox') || $el.is(':radio')) && !$el.is(':checked')) return;
                filters[name] = $el.val();
            });

            // --- Track search query and filters ---
            const searchTerm = $form.find('input[name="alx_shopper_filter"], input[name="s"]').val() || '';
            getUserLocation(function(location) {
                $.post(alxShopperAjax.ajaxurl, {
                    action: 'alxshopper_log_event',
                    event_type: 'search',
                    event_data: {
                        query: searchTerm,
                        filters: filters
                    },
                    user_location: JSON.stringify(location),
                    referrer: document.referrer,
                    device: alxDetectDeviceType()
                });
            });

            $form.find('.alx-attribute-dropdown').each(function() {
                formData.append(this.name + '_label', $(this).data('label') || this.name);
            });
            formData.append('action', 'alx_shopper_filter');

            // Optional: Show spinner
            const $spinner = $form.find('.alx-shopper-spinner');
            if ($spinner.length) $spinner.show();

            $.ajax({
                url: alxShopperAjax.ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(res) {
                    if ($spinner.length) $spinner.hide();
                    if (res.success) {
                        $form[0].lastShopperResults = res.data.results;

                        if (res.data.results.length > 0) {
                            let html = '';
                            html += `<div class="alx-shopper-relax-msg">${res.data.message}</div>`;
                            // 1. Email form (above results)
                            if ($form.data('enable-email-results') == '1') {
                                html += `
                                    <form class="alx-email-results-form" style="margin-bottom:24px;">
                                        <label><strong>Email these results to yourself:</strong></label>
                                        <div class="alx-email-input-row">
                                            <input type="email" class="alx-email-results-input" required placeholder="Your email">
                                            <label style="margin-left:10px;">
                                                <input type="checkbox" name="marketing_consent" class="alx-marketing-consent-checkbox" value="1">
                                                I consent to receive marketing emails
                                            </label>
                                            <button type="submit">Send</button>
                                        </div>
                                        <span class="alx-email-results-status" style="margin-left:10px;"></span>
                                    </form>
                                `;
                            }
                            // 2. Results message
                            // 3. Product cards
                            res.data.results.forEach(function(product) {
                                html += `
                                    <div class="alx-shopper-product">
                                        <img src="${product.image}" alt="${product.title}">
                                        <h3>${product.title}</h3>
                                        <div class="alx-shopper-price">${product.price_html || ''}</div>
                                        <div class="alx-shopper-explanation" style="margin-top:8px;font-size:0.95em;color:#323232 !important;">${product.explanation || ''}</div>
                                        <div class="alx-shopper-actions">
                                            <a href="${product.permalink}" class="alx-shopper-btn alx-view-product-btn" target="_blank">View Product</a>
                                            <button class="alx-shopper-btn alx-quick-view-btn quick-view" data-id="${product.id}">Quick View</button>
                                        </div>
                                    </div>
                                `;
                            });
                            $resultsContainer.html(html);

                            // Show results area
                            $resultsContainer.closest('.alx-shopper-wrapper').addClass('alx-has-results');
                            // Re-bind email handler after rendering new results
                            setupEmailResultsHandler($form, $resultsContainer);
                        } else {
                            $resultsContainer.html('<p>No products found.</p>');
                            $resultsContainer.closest('.alx-shopper-wrapper').removeClass('alx-has-results');
                        }

                        // Scroll to results
                        $('html, body').animate({
                            scrollTop: $('.alx-shopper-results').offset().top - 10 // adjust offset as needed
                        }, 400);

                        // Flash effect on results
                        var $results = $('.alx-shopper-results');
                        $results.addClass('flash');
                        setTimeout(function(){ $results.removeClass('flash'); }, 1000);
                    }
                }
            });
        });
    });

    // Use browser geolocation first, fallback to IP-based, always return a place name if possible
    function getUserLocation(callback) {
        if (window._alxUserLocation) {
            callback(window._alxUserLocation);
            return;
        }
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function(position) {
                var lat = position.coords.latitude;
                var lng = position.coords.longitude;
                reverseGeocode(lat, lng, function(placeName) {
                    var loc = {
                        place: placeName, // e.g. "Sutton-in-Ashfield, UK"
                        lat: lat,
                        lng: lng,
                        accuracy: position.coords.accuracy,
                        method: 'geolocation'
                    };
                    window._alxUserLocation = loc;
                    callback(loc);
                });
            }, function(error) {
                getIpInfoLocation(callback);
            }, {timeout: 5000});
        } else {
            getIpInfoLocation(callback);
        }
    }

    function getIpInfoLocation(callback) {
        jQuery.get('https://ipinfo.io/json?token=5c9e2ed77f172b', function(resp) {
            var city = resp.city || '';
            var country = resp.country || '';
            var place = city && country ? (city + ', ' + country) : (city || country || 'Unknown');
            var loc = {
                place: place,
                city: city,
                country: country,
                region: resp.region,
                ip: resp.ip,
                method: 'ipinfo'
            };
            window._alxUserLocation = loc;
            callback(loc);
        }).fail(function() {
            callback({ place: 'Unknown', method: 'none' });
        });
    }

    function reverseGeocode(lat, lng, callback) {
        // Nominatim API (OpenStreetMap)
        var url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng);
        $.getJSON(url, function(data) {
            if (data && data.address) {
                // Try to build a nice place name
                var place = data.address.city || data.address.town || data.address.village || data.address.hamlet || data.address.county || '';
                var country = data.address.country_code ? data.address.country_code.toUpperCase() : '';
                if (place && country) {
                    callback(place + ', ' + country);
                } else if (place) {
                    callback(place);
                } else if (data.display_name) {
                    callback(data.display_name);
                } else {
                    callback('Unknown');
                }
            } else {
                callback('Unknown');
            }
        }).fail(function() {
            callback('Unknown');
        });
    }

    // --- ANALYTICS TRACKING ---

    // Track View Product button
    $(document).on('click', '.alx-view-product-btn', function(e) {
        e.stopPropagation();
        const $product = $(this).closest('.alx-shopper-product');
        const productId = $product.find('.alx-quick-view-btn').data('id');
        const productTitle = $product.find('h3').text();
        const productImage = $product.find('img').attr('src');
        const productPrice = $product.find('.alx-shopper-price').text();

        getUserLocation(function(location) {
            $.post(alxShopperAjax.ajaxurl, {
                action: 'alxshopper_log_event',
                event_type: 'view_product_btn',
                event_data: {
                    product_id: productId,
                    title: productTitle,
                    image: productImage,
                    price: productPrice
                },
                user_location: JSON.stringify(location),
                referrer: document.referrer,
                device: alxDetectDeviceType()
            });
        });
    });

    // Track Quick View button
    $(document).on('click', '.alx-quick-view-btn', function() {
        const productId = $(this).data('id');
        if (productId && !window.alxQuickViews.includes(productId)) {
            window.alxQuickViews.push(productId);
        }
    });

    // Track Product Card click
    $(document).on('click', '.alx-shopper-product', function(e) {
        if (
            $(e.target).closest('.alx-quick-view-btn').length ||
            $(e.target).closest('.alx-view-product-btn').length ||
            $(e.target).closest('.alx-add-to-cart-btn').length
        ) return;
        const productId = $(this).find('.alx-quick-view-btn').data('id');
        if (productId && !window.alxProductViews.includes(productId)) {
            window.alxProductViews.push(productId);
        }
    });

    // --- UTILITY: log event with extra info (not used in main flow) ---
    function alxLogEvent(event_type, event_data, user_location = '', referrer = '', device = '') {
        $.post(alxShopperAjax.ajaxurl, {
            action: 'alxshopper_log_event',
            event_type: event_type,
            event_data: event_data,
            user_location: JSON.stringify(location),
            referrer: referrer,
            device: device,
            device: alxDetectDeviceType()
        });
    }

    // --- DEBUGGING: show all logged events in console (for development) ---
    window.showLoggedEvents = function() {
        $.get(alxShopperAjax.ajaxurl + '?action=alxshopper_get_logged_events', function(data) {
            console.log('Logged Events:', data);
        });
    }

    // --- ENHANCED EMAIL RESULTS HANDLER ---
    function setupEmailResultsHandler($form, $resultsContainer) {
        $resultsContainer.off('submit', '.alx-email-results-form').on('submit', '.alx-email-results-form', function(e) {
            e.preventDefault();
            const email = $(this).find('.alx-email-results-input').val();
            const marketingConsent = $(this).find('.alx-marketing-consent-checkbox').is(':checked') ? 1 : 0;
            const $status = $(this).find('.alx-email-results-status');
            $status.text('Sending...').css('color', '#333');
            const results = $form[0].lastShopperResults || [];

            // Collect filters and query
            let filters = {};
            $form.find('input, select, textarea').each(function() {
                const $el = $(this);
                const name = $el.attr('name');
                if (!name) return;
                if (($el.is(':checkbox') || $el.is(':radio')) && !$el.is(':checked')) return;
                filters[name] = $el.val();
            });
            const searchQuery = $form.find('input[name="alx_shopper_filter"], input[name="s"]').val() || '';

            // Optionally collect quickviews/product_views if you track them

            // Get user location (async)
            getUserLocation(function(location) {
                $.post(alxShopperAjax.ajaxurl, {
                    action: 'alx_shopper_send_results_email',
                    email: email,
                    results: JSON.stringify(results),
                    filters: JSON.stringify(filters),
                    search_query: searchQuery,
                    marketing_consent: marketingConsent,
                    user_location: typeof location === 'object' ? JSON.stringify(location) : location,
                    referrer: document.referrer,
                    device: alxDetectDeviceType(),
                    alx_filter_id: $form.data('filter-id') || 'default',
                    quick_views: JSON.stringify(window.alxQuickViews || []),
                    product_views: JSON.stringify(window.alxProductViews || [])
                }, function(res) {
                    if (res.success) {
                        $status.text('Email sent!').css('color', 'green');
                        window.alxQuickViews = [];
                        window.alxProductViews = [];
                    } else {
                        $status.text('Failed to send email.').css('color', 'red');
                    }
                }, 'json');
            });
        });
    }

    // --- INIT: setup email results handler for each form ---
    $('.alx-shopper-form').each(function() {
        const $form = $(this);
        const $resultsContainer = $form.parent().find('.alx-shopper-results');
        setupEmailResultsHandler($form, $resultsContainer);
    });

    // --- DEVICE TYPE DETECTION ---
    function alxDetectDeviceType() {
        const ua = navigator.userAgent;
        if (/tablet|ipad|playbook|silk|android(?!.*mobi)/i.test(ua)) {
            return 'Tablet';
        }
        if (/Mobile|iPhone|Android.*Mobile|BlackBerry|IEMobile|Silk-Accelerated|(hpw|web)OS|Opera Mini/i.test(ua)) {
            return 'Mobile';
        }
        return 'Desktop';
    }
});