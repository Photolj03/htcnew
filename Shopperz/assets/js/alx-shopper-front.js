function getUserLocation(callback) {
    if (window._alxUserLocation) {
        callback(window._alxUserLocation);
        return;
    }
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function(position) {
            var lat = position.coords.latitude;
            var lng = position.coords.longitude;
            var url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng);
            jQuery.getJSON(url, function(data) {
                var place = '';
                if (data && data.address) {
                    place = data.address.city || data.address.town || data.address.village || data.address.hamlet || data.address.county || '';
                    var country = data.address.country_code ? data.address.country_code.toUpperCase() : '';
                    if (place && country) {
                        place = place + ', ' + country;
                    } else if (data.display_name) {
                        place = data.display_name;
                    }
                }
                if (!place) place = 'Unknown';
                window._alxUserLocation = place;
                callback(place);
            }).fail(function() {
                getIpInfoLocation(callback);
            });
        }, function() {
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
        window._alxUserLocation = place;
        callback(place);
    }).fail(function() {
        callback('Unknown');
    });
}

jQuery(document).ready(function($) {
    // TRACK: Store quickviews and product views for analytics
    window.alxQuickViews = [];
    window.alxProductViews = [];

    // Quick View tracking
    $(document).on('click', '.alx-quick-view-btn', function(e) {
        e.preventDefault();
        const $product = $(this).closest('.alx-shopper-product');
        const productId = $(this).data('id');
        if (window.alxQuickViews.indexOf(productId) === -1) window.alxQuickViews.push(productId);
        const productTitle = $product.find('h3').text();
        const productImage = $product.find('img').attr('src');
        const productPrice = $product.find('.alx-shopper-price').text();
        getUserLocation(function(location) {
            $.post(alxShopperAjax.ajaxurl, {
                action: 'alxshopper_log_event',
                event_type: 'quick_view',
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

    // Full Product View tracking
    $(document).on('click', '.alx-view-product-btn', function(e) {
        e.stopPropagation();
        const $product = $(this).closest('.alx-shopper-product');
        const productId = $product.find('.alx-quick-view-btn').data('id');
        if (window.alxProductViews.indexOf(productId) === -1) window.alxProductViews.push(productId);
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

    // Product Card click tracking
    $(document).on('click', '.alx-shopper-product', function(e) {
        if (
            $(e.target).closest('.alx-quick-view-btn').length ||
            $(e.target).closest('.alx-view-product-btn').length ||
            $(e.target).closest('.alx-add-to-cart-btn').length
        ) return;
        const productId = $(this).find('.alx-quick-view-btn').data('id');
        if (window.alxProductViews.indexOf(productId) === -1) window.alxProductViews.push(productId);
        const productTitle = $(this).find('h3').text();
        const productImage = $(this).find('img').attr('src');
        const productPrice = $(this).find('.alx-shopper-price').text();
        getUserLocation(function(location) {
            $.post(alxShopperAjax.ajaxurl, {
                action: 'alxshopper_log_event',
                event_type: 'product_view',
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

    if ($('.alx-shopper-results').length) {
        $('.alx-shopper-form').on('submit', function(e) {
            e.preventDefault();
            var $form = $(this);
            var data = $form.serialize();
            data += '&action=alx_shopper_filter';

            // --- ANALYTICS: Track search/filter event ---
            // Collect all filter values
            var filters = {};
            $form.find('input, select, textarea').each(function() {
                var $el = $(this);
                var name = $el.attr('name');
                if (!name) return;
                if (($el.is(':checkbox') || $el.is(':radio')) && !$el.is(':checked')) return;
                filters[name] = $el.val();
            });
            // Try to get a search term (adjust selector if needed)
            var searchTerm = $form.find('input[name="alx_shopper_filter"], input[name="s"]').val() || '';
            // Log the event
            $.post(alxShopperAjax.ajaxurl, {
                action: 'alxshopper_log_event',
                event_type: 'search',
                event_data: {
                    query: searchTerm,
                    filters: filters
                }
            });

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

            $form.find('.alx-shopper-spinner').show();
            $form.find('.alx-shopper-results').html('');
            $form.find('.alx-shopper-message').html('');
            $form.find('.alx-shopper-email-box').remove();

            $.post(alxShopperAjax.ajaxurl, data)
                .done(function(response) {
                    let html = '';
                    if (response.success && response.data.results.length) {
                        $.each(response.data.results, function(i, product) {
                            html += `
    <div class="alx-shopper-product">
        <a href="${product.permalink}">
            <img src="${product.image}" alt="${product.title}">
            <h3>${product.title}</h3>
        </a>
        <div class="alx-shopper-price">${product.price_html}</div>
        <div class="alx-shopper-explanation" style="margin-top:8px;font-size:0.95em;color:#323232;">${product.explanation}</div>
        <div class="alx-shopper-actions" style="margin-top:10px;">
            <a href="${product.permalink}" class="alx-shopper-btn alx-view-product-btn" target="_blank">View Product</a>
            <button class="alx-shopper-btn alx-quick-view-btn" data-id="${product.id}">Quick View</button>
            <button class="alx-shopper-btn alx-add-to-cart-btn" data-id="${product.id}">Add to Cart</button>
        </div>
    </div>
`;
                        });
                        $form.find('.alx-shopper-results').html(html);
                    } else {
                        $form.find('.alx-shopper-results').html('<p>No products found.</p>');
                    }
                    // Show the message above results
                    $form.find('.alx-shopper-message').html(`<div class="alx-shopper-relax-msg" >${response.data.message}</div>`);

                    // Trigger event to insert email box above results, pass the form as data
                    $form.trigger('alxShopperResultsLoaded');

                    // Store results globally after AJAX filter
                    window.lastShopperResults = response.data.results;
                })
                .fail(function() {
                    $form.find('.alx-shopper-results').html('<p class="alx-shopper-error">Sorry, something went wrong. Please try again.</p>');
                })
                .always(function() {
                    $form.find('.alx-shopper-spinner').hide();
                });
        });

        function insertEmailBoxIfNeeded($form) {
            if (!window.alxShopperAjax || !alxShopperAjax.enable_email_results) return;
            if ($form.find('#alx-shopper-email').length) return; // Already exists

            var messageDiv = $form.find('.alx-shopper-message')[0];
            if (messageDiv) {
                var emailBox = document.createElement('div');
                emailBox.className = 'alx-shopper-email-box';
                emailBox.style.marginBottom = '20px';
                emailBox.innerHTML = `
    <label for="alx-shopper-email"><strong>Email results to:</strong></label>
    <input type="email" id="alx-shopper-email" name="alx-shopper-email" placeholder="your@email.com" style="margin-left:10px;" />
    <label style="margin-left:10px;">
        <input type="checkbox" id="alx-shopper-marketing-consent" name="alx-shopper-marketing-consent" value="1" />
        I consent to receive marketing emails
    </label>
    <button type="button" id="alx-shopper-send-email">Send</button>
    <span id="alx-shopper-email-status" style="margin-left:10px;"></span>
`;
                messageDiv.parentNode.insertBefore(emailBox, messageDiv.nextSibling);
            }
        }

        // Insert email box after results are loaded, scoped to the form
        $(document).on('alxShopperResultsLoaded', '.alx-shopper-form', function() {
            insertEmailBoxIfNeeded($(this));
        });
        console.log('Logging analytics event')

        // Email send handler, scoped to the form
        $(document).on('click', '#alx-shopper-send-email', function() {
            var $form = $(this).closest('.alx-shopper-form');
            var email = $form.find('#alx-shopper-email').val();
            var $status = $form.find('#alx-shopper-email-status');
            $status.text('');
            if (!email || !/^[^@]+@[^@]+\.[^@]+$/.test(email)) {
                $status.css('color', 'red').text('Please enter a valid email address.');
                return;
            }

            // Collect filter title, search query, quickviews, product views
            var filterTitle = "";
            var searchQuery = "";
            if ($form.find('input[name="alx_filter_id"]').length) {
                filterTitle = $form.find('input[name="alx_filter_id"]').val();
            }
            if ($form.find('input[name="alx_shopper_filter"]').length) {
                searchQuery = $form.find('input[name="alx_shopper_filter"]').val();
            } else if ($form.find('input[name="s"]').length) {
                searchQuery = $form.find('input[name="s"]').val();
            }
            var quickviews = JSON.stringify(window.alxQuickViews || []);
            var productViews = JSON.stringify(window.alxProductViews || []);

            var data = $form.serialize();
            data += '&action=alx_shopper_send_results_email';
            data += '&email=' + encodeURIComponent(email);
            var marketingConsent = $form.find('#alx-shopper-marketing-consent').is(':checked') ? 1 : 0;
            data += '&marketing_consent=' + marketingConsent;
            data += '&filter_title=' + encodeURIComponent(filterTitle);
            data += '&search_query=' + encodeURIComponent(searchQuery);
            data += '&quickviews=' + encodeURIComponent(quickviews);
            data += '&product_views=' + encodeURIComponent(productViews);

            $form.find('#alx-shopper-send-email').prop('disabled', true);
            $status.css('color', '#333').text('Sending...');
            $.post(alxShopperAjax.ajaxurl, data, function(response) {
                if (response.success) {
                    $status.css('color', 'green').text('Email sent!');
                    window.alxQuickViews = [];
                    window.alxProductViews = [];
                } else {
                    $status.css('color', 'red').text(response.data && response.data.message ? response.data.message : 'Failed to send email.');
                }
                $form.find('#alx-shopper-send-email').prop('disabled', false);
            }).fail(function() {
                $status.css('color', 'red').text('Failed to send email.');
                $form.find('#alx-shopper-send-email').prop('disabled', false);
            });
        });
    }
});
