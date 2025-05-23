// Hot Tub Finder Analytics Logging

function htfLogAnalytics(eventType, data) {
    var payload = {
        action: 'htf_log_event',
        event_type: eventType,
        seats: data.seats || '',
        power: data.power || '',
        lounger: data.lounger || '',
        product_id: data.product_id || '',
        device: data.device || '',
        referrer: data.referrer || document.referrer || ''
    };

    fetch(htf_ajax_object.ajaxurl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(payload).toString()
    })
    .then(response => response.json())
    .then(result => {
        // Optionally handle success
        // console.log('Analytics logged:', result);
    })
    .catch(error => {
        // Optionally handle error
        // console.error('Logging error:', error);
    });
}

// Example usage:
// htfLogAnalytics('form_submitted', {seats: '4', power: '13', lounger: 'yes', product_id: 123, device: 'Desktop'});