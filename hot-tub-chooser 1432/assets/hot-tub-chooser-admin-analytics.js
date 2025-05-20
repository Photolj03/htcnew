function htcAnalyticsInit() {
    const $ = jQuery;
    let analyticsData = [];
    let eventChart, deviceChart, countryChart;

    function renderSummary(data) {
        const total = data.length;
        const eventTypes = {};
        const devices = {};
        const countries = {};
        data.forEach(row => {
            eventTypes[row.event_type] = (eventTypes[row.event_type] || 0) + 1;
            devices[row.device] = (devices[row.device] || 0) + 1;
            countries[row.country || 'Unknown'] = (countries[row.country || 'Unknown'] || 0) + 1;
        });
        let html = `<div style="margin-bottom:12px;">
            <strong>Total Events:</strong> ${total} &nbsp;|&nbsp;
            <strong>Event Types:</strong> ${Object.entries(eventTypes).map(([t,c])=>`${t}: ${c}`).join(', ')}<br>
            <strong>Device Split:</strong> ${Object.entries(devices).map(([d,c])=>`${d}: ${c}`).join(', ')}<br>
            <strong>Top Countries:</strong> ${Object.entries(countries).sort((a,b)=>b[1]-a[1]).slice(0,5).map(([c,n])=>`${c}: ${n}`).join(', ')}
        </div>`;
        $('#htc-analytics-summary').html(html);
    }

    function renderTable(data) {
        let rows = data.map(row => `<tr>
            <td>${row.created_at || ''}</td>
            <td>${row.event_type || ''}</td>
            <td>${row.seats || ''}</td>
            <td>${row.power || ''}</td>
            <td>${row.lounger || ''}</td>
            <td>${row.product_id || ''}</td>
            <td>${row.device || ''}</td>
            <td>${row.country || ''}</td>
            <td>${row.city || ''}</td>
            <td>${row.referrer || ''}</td>
        </tr>`);
        if (!rows.length) rows = [`<tr><td colspan="10" style="text-align:center;">No data found for this range.</td></tr>`];
        $('#htc-analytics-table tbody').html(rows.join(''));
    }

    function drawCharts(data) {
        const ctxEvent = document.getElementById('htc-analytics-event-chart').getContext('2d');
        const ctxDevice = document.getElementById('htc-analytics-device-chart').getContext('2d');
        const ctxCountry = document.getElementById('htc-analytics-country-chart').getContext('2d');
        const events = {}, devices = {}, countries = {};
        data.forEach(row => {
            events[row.event_type] = (events[row.event_type] || 0) + 1;
            devices[row.device] = (devices[row.device] || 0) + 1;
            countries[row.country || 'Unknown'] = (countries[row.country || 'Unknown'] || 0) + 1;
        });

        // Destroy existing charts if needed
        [eventChart, deviceChart, countryChart].forEach(c=>{ if(c) { c.destroy(); } });

        eventChart = new Chart(ctxEvent, {
            type: 'bar',
            data: {
                labels: Object.keys(events),
                datasets: [{
                    label: 'Event Types',
                    data: Object.values(events),
                    backgroundColor: '#4e88c7'
                }]
            },
            options: { plugins: { legend: { display: false } }, responsive: true }
        });
        deviceChart = new Chart(ctxDevice, {
            type: 'pie',
            data: {
                labels: Object.keys(devices),
                datasets: [{
                    label: 'Devices',
                    data: Object.values(devices),
                    backgroundColor: ['#4e88c7', '#6fa4d8', '#b7e1fa']
                }]
            },
            options: { plugins: { legend: { position: 'bottom' } }, responsive: true }
        });
        countryChart = new Chart(ctxCountry, {
            type: 'doughnut',
            data: {
                labels: Object.keys(countries).slice(0,10),
                datasets: [{
                    label: 'Top Countries',
                    data: Object.values(countries).slice(0,10),
                    backgroundColor: [
                        '#4e88c7','#6fa4d8','#b7e1fa','#98b8d6','#517dbb',
                        '#b7e1fa','#b7d2eb','#e9f1fb','#4e88c7','#223057'
                    ]
                }]
            },
            options: { plugins: { legend: { position: 'right' } }, responsive: true }
        });
    }

    function fetchData() {
        $('#htc-analytics-table tbody').html('<tr><td colspan="10" style="text-align:center;">Loading...</td></tr>');
        $.post(
            htcAnalyticsAjax.ajaxurl,
            {
                action: 'htc_get_analytics_data',
                from: $('#htc-analytics-from').val(),
                to: $('#htc-analytics-to').val(),
                _ajax_nonce: htcAnalyticsAjax.nonce
            },
            res => {
                if (res.success) {
                    analyticsData = res.data;
                    renderSummary(analyticsData);
                    renderTable(analyticsData);
                    drawCharts(analyticsData);
                } else {
                    $('#htc-analytics-table tbody').html('<tr><td colspan="10" style="text-align:center;">Error.</td></tr>');
                }
            }
        );
    }

    $('#htc-analytics-apply').on('click', fetchData);
    fetchData();
}