function phpUnserialize(str) {
    const regex = /s:(\d+):"([^"]*)";|i:(\d+);/g;
    let match, result = {}, lastKey = null;
    while ((match = regex.exec(str))) {
        if (match[2] !== undefined) {
            if (lastKey === null) {
                lastKey = match[2];
            } else {
                result[lastKey] = match[2];
                lastKey = null;
            }
        } else if (match[3] !== undefined) {
            if (lastKey === null) {
                lastKey = match[3];
            } else {
                result[lastKey] = match[3];
                lastKey = null;
            }
        }
    }
    return result;
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

jQuery(document).ready(function() {
    let analyticsData = [];

    jQuery('#alxshopper-download-csv').on('click', function() {
        if (!analyticsData.length) return alert('No data to export.');
        // CSV header
        const header = [
            'Date','Action','Product','Price','Query','Filters','IP','Location','Referrer','Device'
        ];
        // CSV rows
        const rows = analyticsData.map(row => header.map(h => {
            let val = row[h] || '';
            // Escape quotes
            return `"${String(val).replace(/"/g, '""')}"`;
        }).join(','));
        const csv = [header.join(','), ...rows].join('\r\n');
        // Download
        const blob = new Blob([csv], {type: 'text/csv'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'alxshopper-analytics.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    });

    jQuery.post(ajaxurl, { action: 'alxshopper_get_analytics' }, function(response) {
        if (!response.success || !response.data || !response.data.length) {
            jQuery('#alxshopper-analytics-table').html('<p>No analytics data found.</p>');
            return;
        }

        analyticsData = []; // Reset

        // Prepare structures for each filter and event type
        let productTitles = {};
        let allProductIds = new Set();
        let pvHotTub = {}, pvSwimSpa = {};
        let qvHotTub = {}, qvSwimSpa = {};

        response.data.forEach(row => {
            let eventData = row.event_data;
            if (typeof eventData === 'string' && eventData.startsWith('a:')) {
                eventData = phpUnserialize(eventData);
            } else {
                try { eventData = JSON.parse(eventData); } catch (e) {}
            }
            let productId = eventData.product_id || row.product_id;
            let productTitle = eventData.title || row.title || 'Unknown';
            if (productId) {
                productTitles[productId] = productTitle;
                allProductIds.add(productId);
            }

            let filterId = (eventData.filters && eventData.filters.alx_filter_id) ? eventData.filters.alx_filter_id.toLowerCase() : '';

            // Product Views
            if (
                row.event_type === 'product_view' ||
                row.event_type === 'view_product_btn' ||
                row.event_type === 'modal_view_product'
            ) {
                if (filterId === 'hot-tub-finder') {
                    pvHotTub[productId] = (pvHotTub[productId] || 0) + 1;
                }
                if (filterId === 'swim-spa-finder') {
                    pvSwimSpa[productId] = (pvSwimSpa[productId] || 0) + 1;
                }
            }
            // Quick Views
            if (row.event_type === 'quick_view') {
                if (filterId === 'hot-tub-finder') {
                    qvHotTub[productId] = (qvHotTub[productId] || 0) + 1;
                }
                if (filterId === 'swim-spa-finder') {
                    qvSwimSpa[productId] = (qvSwimSpa[productId] || 0) + 1;
                }
            }
        });

        // Always show all products, even if count is 0
        allProductIds = Array.from(allProductIds);

        function renderBarChart(canvasId, dataObj, chartLabel, color) {
            if (window[canvasId + 'Chart']) window[canvasId + 'Chart'].destroy();
            const ctx = document.getElementById(canvasId).getContext('2d');
            window[canvasId + 'Chart'] = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: allProductIds.map(pid => productTitles[pid] || pid),
                    datasets: [{
                        label: chartLabel,
                        data: allProductIds.map(pid => dataObj[pid] || 0),
                        backgroundColor: color,
                        borderRadius: 10,
                        borderSkipped: false
                    }]
                },
                options: {
                    plugins: {
                        legend: { display: false },
                        title: {
                            display: true,
                            text: chartLabel,
                            font: { size: 20, weight: 'bold', family: 'Inter, Arial, sans-serif' },
                            color: '#222',
                            padding: { top: 10, bottom: 20 }
                        },
                        tooltip: {
                            backgroundColor: '#fff',
                            titleColor: '#222',
                            bodyColor: '#222',
                            borderColor: color,
                            borderWidth: 1,
                            padding: 12,
                            cornerRadius: 8,
                            boxPadding: 6,
                            bodyFont: { size: 15, weight: '500' }
                        }
                    },
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: { padding: 20 },
                    scales: {
                        x: {
                            grid: { color: '#f0f0f0', borderColor: '#e0e0e0', borderWidth: 2 },
                            ticks: { color: '#222', font: { size: 14, weight: 'bold' } }
                        },
                        y: {
                            grid: { color: '#f0f0f0', borderColor: '#e0e0e0', borderWidth: 2 },
                            beginAtZero: true,
                            ticks: { color: '#222', font: { size: 14, weight: 'bold' } }
                        }
                    }
                }
            });
        }

        function renderDoughnutChart(canvasId, dataObj, chartLabel, colors) {
            if (window[canvasId + 'Chart']) window[canvasId + 'Chart'].destroy();
            const ctx = document.getElementById(canvasId).getContext('2d');
            window[canvasId + 'Chart'] = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: Object.keys(dataObj),
                    datasets: [{
                        data: Object.values(dataObj),
                        backgroundColor: colors
                    }]
                },
                options: {
                    plugins: {
                        title: {
                            display: true,
                            text: chartLabel,
                            font: { size: 20, weight: 'bold', family: 'Inter, Arial, sans-serif' },
                            color: '#222',
                            padding: { top: 10, bottom: 20 }
                        },
                        legend: {
                            display: true,
                            position: 'bottom',
                            labels: {
                                color: '#222',
                                font: { size: 15, weight: 'bold' },
                                padding: 20,
                                boxWidth: 18,
                                boxHeight: 18,
                                borderRadius: 9
                            }
                        },
                        tooltip: {
                            backgroundColor: '#fff',
                            titleColor: '#222',
                            bodyColor: '#222',
                            borderColor: '#003366',
                            borderWidth: 1,
                            padding: 12,
                            cornerRadius: 8,
                            boxPadding: 6,
                            bodyFont: { size: 15, weight: '500' }
                        }
                    },
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%'
                }
            });
        }

        renderBarChart('alxshopper-product-views-hot-tub', pvHotTub, 'Hot Tub Product Views', 'rgba(33,150,243,0.7)');
        renderBarChart('alxshopper-product-views-swim-spa', pvSwimSpa, 'Swim Spa Product Views', 'rgba(0,51,102,0.7)');
        renderBarChart('alxshopper-quick-views-hot-tub', qvHotTub, 'Hot Tub Quick Views', 'rgba(76,175,80,0.7)');
        renderBarChart('alxshopper-quick-views-swim-spa', qvSwimSpa, 'Swim Spa Quick Views', 'rgba(255,152,0,0.7)');

        // Device Chart
        const deviceCounts = {};
        response.data.forEach(row => {
            const device = row.device || 'Unknown';
            deviceCounts[device] = (deviceCounts[device] || 0) + 1;
        });
        renderDoughnutChart(
            'alx-device-chart',
            deviceCounts,
            'Device Type Distribution',
            ['#2196f3','#003366','#43e97b','#f44336','#ff9800']
        );

        // --- Table rendering ---
        let html = '<table class="widefat striped"><thead><tr>' +
            '<th>Date</th>' +
            '<th>Action</th>' +
            '<th>Product</th>' +
            '<th>Price</th>' +
            '<th>Query</th>' +
            '<th>Filters</th>' +
            '<th>IP</th>' +
            '<th>Location</th>' +
            '<th>Referrer</th>' +
            '<th>Device</th>' +
            '</tr></thead><tbody>';

        response.data.forEach(row => {
            let eventData = row.event_data;
            if (typeof eventData === 'string' && eventData.startsWith('a:')) {
                eventData = phpUnserialize(eventData);
            } else {
                try { eventData = JSON.parse(eventData); } catch (e) {}
            }

            let product = eventData.title || eventData.product_id || '-';
            let price = eventData.price || '-';

            // --- Add for search events ---
            let query = eventData.query || '';
            let filters = [];
            if (eventData.filters && typeof eventData.filters === 'object') {
                // Show Filter ID first if present
                if (eventData.filters.alx_filter_id) {
                    filters.push('<strong>Filter id:</strong> ' + eventData.filters.alx_filter_id.replace(/[-_]/g, ' ').replace(/\b\w/g, l => l.toUpperCase()));
                }
                // Show all dropdowns with a value (not "any")
                Object.keys(eventData.filters).forEach(k => {
                    let match = k.match(/^alx_dropdown_(\d+)$/);
                    if (match) {
                        let idx = match[1];
                        let label = eventData.filters[`alx_dropdown_${idx}_label`] || '';
                        let val = eventData.filters[k];
                        if (val !== 'any' && val !== '') {
                            // Prettify value: if string, capitalize and replace underscores/dashes; if number, show as-is
                            let valDisplay = (typeof val === 'string' && isNaN(val))
                                ? val.replace(/[-_]/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
                                : val;
                            filters.push('<strong>' + (label.charAt(0).toUpperCase() + label.slice(1)) + ':</strong> ' + valDisplay);
                        }
                    }
                });
            }
            let filtersHtml = filters.length ? filters.join('<br>') : '<em>No filters selected</em>';

            // Friendly action label
            let actionLabel = {
                'product_view': 'Product Card Click',
                'quick_view': 'Quick View Button',
                'modal_view_product': 'Modal View Product',
                'add_to_cart': 'Add to Cart',
                'search': 'Search'
            }[row.event_type] || row.event_type;

            // Collect for CSV
            analyticsData.push({
                'Date': row.created_at || '',
                'Action': actionLabel,
                'Product': product,
                'Price': price,
                'Query': query,
                'Filters': filtersHtml.replace(/<br\s*\/?>/gi, ' | ').replace(/<[^>]+>/g, ''), // flatten HTML
                'IP': row.user_ip,
                'Location': row.user_location || '-',
                'Referrer': row.referrer || '-',
                'Device': row.device || '-'
            });

            html += `<tr>
                <td>${row.created_at || ''}</td>
                <td>${actionLabel}</td>
                <td>${product}</td>
                <td>${price}</td>
                <td>${query}</td>
                <td>${filtersHtml}</td>
                <td>${row.user_ip}</td>
                <td>${row.user_location || '-'}</td>
                <td>${row.referrer || '-'}</td>
                <td>${row.device || '-'}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        jQuery('#alxshopper-analytics-table').html(html);
    });

    // Quick View button click
    jQuery(document).on('click', '.alx-quick-view-btn', function(e) {
        console.log('Quick View Button Clicked');
        e.stopPropagation();
        const productElement = jQuery(this).closest('.alx-shopper-product');
        const productId = jQuery(this).data('id');
        const productTitle = productElement.find('h3').text();
        const productImage = productElement.find('img').attr('src');
        const productPrice = productElement.find('.alx-shopper-price').text();

        getUserLocation(function(location) {
            jQuery.post(alxShopperAjax.ajaxurl, {
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

    // Product card click (not quick view)
    jQuery(document).on('click', '.alx-shopper-product', function(e) {
        if (jQuery(e.target).closest('.alx-quick-view-btn').length) return;
        console.log('Product Card Clicked');
        const productId = jQuery(this).find('.alx-quick-view-btn').data('id');
        const productTitle = jQuery(this).find('h3').text();
        const productImage = jQuery(this).find('img').attr('src');
        const productPrice = jQuery(this).find('.alx-shopper-price').text();

        getUserLocation(function(location) {
            jQuery.post(alxShopperAjax.ajaxurl, {
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

    // Modal View Product button
    jQuery(document).on('click', '.alx-modal-view-product', function() {
        console.log('Modal View Product Button Clicked');
        const $modal = jQuery(this).closest('#alx-quick-view-modal');
        const productId = $modal.find('.alx-quick-view-btn').data('id');
        const productTitle = $modal.find('h3').text();
        const productImage = $modal.find('img').attr('src');
        const productPrice = $modal.find('.alx-shopper-price').text();

        getUserLocation(function(location) {
            jQuery.post(alxShopperAjax.ajaxurl, {
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

    // Add to Cart button
    jQuery(document).on('click', '.alx-add-to-cart-btn', function() {
        console.log('Add to Cart Button Clicked');
        const $modal = jQuery(this).closest('#alx-quick-view-modal');
        const productId = $modal.find('.alx-quick-view-btn').data('id') || jQuery(this).data('id');
        const productTitle = $modal.find('h3').text() || jQuery(this).closest('.alx-shopper-product').find('h3').text();
        const productImage = $modal.find('img').attr('src') || jQuery(this).closest('.alx-shopper-product').find('img').attr('src');
        const productPrice = $modal.find('.alx-shopper-price').text() || jQuery(this).closest('.alx-shopper-product').find('.alx-shopper-price').text();

        getUserLocation(function(location) {
            jQuery.post(alxShopperAjax.ajaxurl, {
                action: 'alxshopper_log_event',
                event_type: 'add_to_cart',
                event_data: {
                    product_id: productId,
                    title: productTitle,
                    image: productImage,
                    price: productPrice
                },
                user_location: JSON.stringify(location),
                device: alxDetectDeviceType()
            });
        });
    });
});