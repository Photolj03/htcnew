function alxDetectDeviceType() {
    const ua = navigator.userAgent;
    if (/mobile/i.test(ua)) return 'Mobile';
    if (/tablet|ipad|playbook|silk/i.test(ua)) return 'Tablet';
    return 'Desktop';
}

function getUserLocation(callback) {
    if (window._alxUserLocation) {
        callback(window._alxUserLocation);
        return;
    }
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function(position) {
            var lat = position.coords.latitude;
            var lng = position.coords.longitude;
            // Use OpenStreetMap Nominatim for reverse geocoding
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
    // --- MODAL LOGIC ---

    // Modal HTML (append once to body)
    if (!$('#alx-quick-view-modal').length) {
        $('body').append(`
            <div id="alx-quick-view-modal">
                <div class="alx-quick-view-content">
                    <span class="alx-quick-view-close">&times;</span>
                    <div class="alx-quick-view-body"></div>
                </div>
            </div>
        `);
    }

    // Open modal (called from Quick View button)
    window.alxShowQuickView = function(productId) {
        var $modal = $('#alx-quick-view-modal');
        var $body = $modal.find('.alx-quick-view-body');
        $body.html('Loading...');
        $modal.css('display', 'flex');

        $.ajax({
            url: alxShopperAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'alx_quick_view',
                product_id: productId
            },
            success: function(response) {
                $body.html(response);
            },
            error: function() {
                $body.html('Error loading product.');
            }
        });
    };

    // Close modal when clicking close button or overlay (not content)
    $(document).on('click', '.alx-quick-view-close, #alx-quick-view-modal', function(e) {
        if (
            e.target.id === 'alx-quick-view-modal' ||
            $(e.target).hasClass('alx-quick-view-close')
        ) {
            $('#alx-quick-view-modal').css('display', 'none');
        }
    });
    $(document).on('click', '.alx-quick-view-content', function(e) {
        e.stopPropagation();
    });

    // --- RESULTS CARD HANDLERS & ANALYTICS ---

    // Quick View button (results card)
    $(document).on('click', '.alx-quick-view-btn', function(e) {
        e.preventDefault();
        console.log('Quick View clicked'); // Add this line
        const $product = $(this).closest('.alx-shopper-product');
        const productId = $(this).data('id');
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

        // Open modal
        if (typeof window.alxShowQuickView === 'function') {
            console.log('Calling alxShowQuickView', productId);
            window.alxShowQuickView(productId);
        }
    });

    // Add to Cart button (results card)
   $(document).on('click', '.alx-add-to-cart-btn', function(e) {
    e.preventDefault();
    var $btn = $(this);
    var productId = $btn.data('id');
    const $product = $btn.closest('.alx-shopper-product');
    const productTitle = $product.find('h3').text();
    const productImage = $product.find('img').attr('src');
    const productPrice = $product.find('.alx-shopper-price').text();

    getUserLocation(function(location) {
        $.post(alxShopperAjax.ajaxurl, {
            action: 'alxshopper_log_event',
            event_type: 'add_to_cart',
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

    $btn.text('Adding...');
    $.post('/?wc-ajax=add_to_cart', {
        product_id: productId,
        quantity: 1
    }, function(response) {
        $btn.text('Added!');
        if (typeof wc_cart_fragments_params !== 'undefined') {
            $(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, $btn]);
        }
    }).fail(function() {
        $btn.text('Error');
    });
});

    // Track Product Card click (ignore button clicks)
    $(document).on('click', '.alx-shopper-product', function(e) {
        if (
            $(e.target).closest('.alx-quick-view-btn').length ||
            $(e.target).closest('.alx-view-product-btn').length ||
            $(e.target).closest('.alx-add-to-cart-btn').length
        ) return;
        const productId = $(this).find('.alx-quick-view-btn').data('id');
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

    // Modal: Add to Cart button
    $(document).on('click', '.alx-modal-add-to-cart', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var productId = $btn.data('id');
        const $modal = $('#alx-quick-view-modal');
        const productTitle = $modal.find('h2').text();
        const productImage = $modal.find('img').attr('src');
        const productPrice = $modal.find('.alx-quick-view-price').text();

        getUserLocation(function(location) {
            $.post(alxShopperAjax.ajaxurl, {
                action: 'alxshopper_log_event',
                event_type: 'modal_add_to_cart',
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

       $btn.text('Adding...');
$.post('/?wc-ajax=add_to_cart', {
    product_id: productId,
    quantity: 1
}, function(response) {
    $btn.text('Added!');
    if (typeof wc_cart_fragments_params !== 'undefined') {
        $(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, $btn]);
    }
}).fail(function() {
    $btn.text('Error');
});
    });

    // Modal: View Product button
    $(document).on('click', '.alx-modal-view-product', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const $modal = $('#alx-quick-view-modal');
        const productId = $modal.find('.alx-modal-add-to-cart').data('id');
        const productTitle = $modal.find('h2').text();
        const productImage = $modal.find('img').attr('src');
        const productPrice = $modal.find('.alx-quick-view-price').text();
        const href = $(this).attr('href');

        // Open the link in a new tab/window
        if (href && href !== '#') {
            window.open(href, '_blank');
        }

        getUserLocation(function(location) {
            $.post(alxShopperAjax.ajaxurl, {
                action: 'alxshopper_log_event',
                event_type: 'modal_view_product',
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
});

