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
    function initSortables() {
        $('.alx-sortable').each(function() {
            if (!$(this).data('ui-sortable')) {
                $(this).sortable({
                    items: '> .alx-sortable-item, > .alx-sortable-any',
                    update: function(event, ui) {
                        const $ul = $(this);
                        // Always move .alx-sortable-any to the top if present
                        const $any = $ul.find('.alx-sortable-any');
                        if ($any.length) {
                            $any.prependTo($ul);
                        }
                        // Remove all hidden inputs
                        $ul.find('input[type="hidden"]').remove();
                        // Re-add hidden inputs in new order
                        const index = $ul.data('index');
                        $ul.children('li').each(function() {
                            const $li = $(this);
                            if ($li.hasClass('alx-sortable-any')) {
                                const $checkbox = $li.find('input[type="checkbox"]');
                                if ($checkbox.is(':checked')) {
                                    $ul.append('<input type="hidden" name="alx_orders['+index+'][]" value="any">');
                                }
                            } else if ($li.hasClass('alx-sortable-item')) {
                                const val = $li.data('term');
                                $ul.append('<input type="hidden" name="alx_orders['+index+'][]" value="'+val+'">');
                            }
                        });
                    }
                });
            }
        });
    }

    // Initial call
    initSortables();

    // Live update for number of dropdowns
    $('input[name="alx_shopper_num_dropdowns"]').on('input change', function() {
        const num = parseInt($(this).val(), 10) || 2;
        $('.alx-dynamic-row').each(function(i) {
            if (i < num) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
        setTimeout(initSortables, 100);
    }).trigger('change');

    // Live update: when attribute changes, reload values via AJAX
    $(document).on('change', '.alx-dropdown-attribute', function() {
        const $row = $(this).closest('.alx-dynamic-row');
        const attr = $(this).val();
        const index = $row.data('index');
        const data = {
            action: 'alx_get_attribute_terms',
            taxonomy: attr,
            index: index
        };
        if(attr) {
            $.post(ajaxurl, data, function(response) {
                $row.find('.alx-sortable').parent().html(response);
                initSortables();
            });
        } else {
            $row.find('.alx-sortable').parent().html('<em>No attribute selected.</em>');
        }
    });

    // Reusable analytics logging for admin actions
    window.alxAdminLogAnalytics = function(event_type, event_data = {}) {
        fetch(alxShopperAjax.ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                action: 'alxshopper_log_event',
                event_type,
                event_data: JSON.stringify(event_data),
                user_location: JSON.stringify(location), // Fill with geolocation if available
                referrer: document.referrer,
                device: navigator.userAgent
            })
        });
    };

    // Example: log when date filter is used on analytics page
    if (document.getElementById('alxshopper-date-filter')) {
        document.getElementById('alxshopper-date-filter').onclick = function() {
            const from = document.getElementById('alxshopper-date-from').value;
            const to = document.getElementById('alxshopper-date-to').value;
            // Log the filter event
            window.alxAdminLogAnalytics('analytics_date_filter', {from, to});
            fetch(ajaxurl + '?action=alxshopper_get_analytics&from=' + from + '&to=' + to)
                .then(res => res.json())
                .then(data => {
                    // ...update chart as before...
                });
        };
    }
});