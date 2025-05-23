// Custom analytics tracking for Hot Tub Finder
// Tracks:
// - "View Products" (search) button
// - Product View (external) buttons
// - Quick View buttons
// - Modal View Product button (with all filter data, no double-counting)

document.addEventListener('DOMContentLoaded', function () {
    function getDeviceType() {
        if (/Mobi|Android/i.test(navigator.userAgent)) return 'Mobile';
        if (/Tablet|iPad/i.test(navigator.userAgent)) return 'Tablet';
        return 'Desktop';
    }
    function getReferrer() {
        return document.referrer || '';
    }

    // Only track "View Products" (Search) button click
    var seatSel = document.getElementById('htf-seats');
    var powerSel = document.getElementById('htf-power');
    var loungerSel = document.getElementById('htf-lounger');
    var viewBtn = document.getElementById('htf-search-btn');
    if (viewBtn) {
        viewBtn.addEventListener('click', function () {
            if (typeof htfLogAnalytics === 'function') {
                htfLogAnalytics('view_products', {
                    seats: (seatSel || {}).value || '',
                    power: (powerSel || {}).value || '',
                    lounger: (loungerSel || {}).value || '',
                    device: getDeviceType(),
                    referrer: getReferrer()
                });
            }
        });
    }

    // Delegate tracking for product view, quick view, and modal view actions
    document.addEventListener('click', function(e) {
        // Modal View Product button
        var modalViewBtn = e.target.closest('#htf-modal-body .htf-btn-main');
        if (modalViewBtn) {
            if (typeof htfLogAnalytics === 'function') {
                htfLogAnalytics('view_products_quickview', {
                    product_id: document.querySelector('#htf-modal-body h3')?.textContent.trim() || '',
                    device: getDeviceType(),
                    referrer: getReferrer()
                });
            }
            return; // Don't double-fire for other handlers
        }

        // Product view (external link in results list, not modal)
        var viewBtnMain = e.target.closest('.htf-btn-main');
        if (viewBtnMain && !e.target.closest('#htf-modal-body')) {
            var productEl = e.target.closest('.htf-product');
            if (productEl && typeof htfLogAnalytics === 'function') {
                var name = productEl.querySelector('h3') ? productEl.querySelector('h3').textContent : '';
                htfLogAnalytics('product_view', {
                    product_id: name,
                    device: getDeviceType(),
                    referrer: getReferrer()
                });
            }
        }

        // Quick view (from results list)
        var quickViewBtn = e.target.closest('.htf-quick-view-btn');
        if (quickViewBtn) {
            var productEl = e.target.closest('.htf-product');
            if (productEl && typeof htfLogAnalytics === 'function') {
                var name = productEl.querySelector('h3') ? productEl.querySelector('h3').textContent : '';
                htfLogAnalytics('quick_view', {
                    product_id: name,
                    device: getDeviceType(),
                    referrer: getReferrer()
                });
            }
        }
    }, true);
});
