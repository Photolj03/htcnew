// Modern analytics dashboard JS for Hot Tub Finder Analytics Admin
// Chart.js v4+ required

function htfAnalyticsInit() {
    const from = document.getElementById('htf-analytics-from');
    const to = document.getElementById('htf-analytics-to');
    const applyBtn = document.getElementById('htf-analytics-apply');
    let eventChart, deviceChart, countryChart, productViewsChart;

    function fetchAnalyticsData(cb) {
        const data = {
            action: 'htf_get_analytics_data',
            from: from.value,
            to: to.value,
            _ajax_nonce: htfAnalyticsAjax.nonce
        };
        fetch(htfAnalyticsAjax.ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: Object.keys(data).map(k => encodeURIComponent(k) + '=' + encodeURIComponent(data[k])).join('&')
        })
        .then(res => res.json())
        .then(res => cb(res.success ? res.data : []));
    }

    function fmt(dt) {
        const d = new Date(dt.replace(' ', 'T'));
        return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')} ${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;
    }

    // Abbreviations for event types
    const eventAbbr = {
        'view_products_quickview': 'QProd',
        'view_products': 'Search',
        'quick_view': 'Quick',
        'product_view': 'Prod',
        // Add more as needed
    };
    function abbreviateEvent(label) {
        if (eventAbbr[label]) return eventAbbr[label];
        return label.split('_').map(w => w[0]?.toUpperCase() || '').join('');
    }

    function renderSummary(rows) {
        const total = rows.length;
        const events = {};
        const devices = {};
        const countries = {};
        for (const r of rows) {
            events[r.event_type] = (events[r.event_type]||0) + 1;
            devices[r.device||'Unknown'] = (devices[r.device||'Unknown']||0) + 1;
            countries[r.country||'Unknown'] = (countries[r.country||'Unknown']||0) + 1;
        }
        const topEvent = Object.entries(events).sort((a,b)=>b[1]-a[1])[0];
        const topDevice = Object.entries(devices).sort((a,b)=>b[1]-a[1])[0];
        const topCountry = Object.entries(countries).sort((a,b)=>b[1]-a[1])[0];
        document.getElementById('htf-analytics-summary').innerHTML = `
            <div><span style="font-weight:700;color:#4e88c7;">${total}</span> events recorded.</div>
            <div>Top Event: <span style="font-weight:600;">${topEvent ? abbreviateEvent(topEvent[0]) : '-'}</span></div>
            <div>Most Used Device: <span style="font-weight:600;">${topDevice ? topDevice[0] : '-'}</span></div>
            <div>Top Country: <span style="font-weight:600;">${topCountry ? topCountry[0] : '-'}</span></div>
        `;
    }

    function renderTable(rows) {
        const tbody = document.querySelector('#htf-analytics-table tbody');
        if (!rows.length) {
            tbody.innerHTML = `<tr><td colspan="10" style="text-align:center;">No data.</td></tr>`;
            return;
        }
        tbody.innerHTML = rows.map(r => `
    <tr>
        <td>${fmt(r.created_at)}</td>
        <td>${abbreviateEvent(r.event_type)}</td>
        <td>${r.seats||''}</td>
        <td>${r.power||''}</td>
        <td>${r.lounger||''}</td>
        <td>${r.product_id||''}</td>
        <td>${r.device||''}</td>
        <td>${r.country||''}</td>
        <td>${r.city||''}</td>
        <td>${r.user_hash ? r.user_hash.substring(0, 12) + '…' : ''}</td>
        <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
            ${r.referrer ? `<a href="${r.referrer}" target="_blank" style="color:#4e88c7;text-decoration:underline;">${r.referrer.split('?')[0]}</a>` : ''}
        </td>
    </tr>
        `).join('');
    }

    function renderCharts(rows) {
        const eventCounts = {};
        const deviceCounts = {};
        const countryCounts = {};
        for (const r of rows) {
            eventCounts[r.event_type] = (eventCounts[r.event_type]||0) + 1;
            deviceCounts[r.device||'Unknown'] = (deviceCounts[r.device||'Unknown']||0) + 1;
            countryCounts[r.country||'Unknown'] = (countryCounts[r.country||'Unknown']||0) + 1;
        }
        const sortEntries = obj => Object.entries(obj).sort((a,b)=>b[1]-a[1]);
        const topEvents = sortEntries(eventCounts);
        const topDevices = sortEntries(deviceCounts);
        const topCountries = sortEntries(countryCounts);

        // -- Event Bar Chart --
        if (eventChart) eventChart.destroy();
        const eventCtx = document.getElementById('htf-analytics-event-chart').getContext('2d');
        eventChart = new Chart(eventCtx, {
            type: 'bar',
            data: {
                labels: topEvents.map(e => abbreviateEvent(e[0])),
                datasets: [{
                    label: 'Count',
                    data: topEvents.map(e=>e[1]),
                    backgroundColor: 'rgba(78,136,199,0.87)',
                    borderRadius: 8,
                    maxBarThickness: 38,
                }]
            },
            options: {
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function(context) {
                                // Show the full label on hover
                                const idx = context[0].dataIndex;
                                return topEvents[idx] ? topEvents[idx][0] : '';
                            }
                        }
                    }
                },
                layout: { padding: { left: 8, right: 8, top: 12, bottom: 8 } },
                scales: {
                    x: {
                        title: { display: false },
                        ticks: { font: { size: 15, weight: 'bold' }, color: '#223057', maxRotation: 0, minRotation: 0 },
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { font: { size: 13 }, color: '#4e88c7' },
                        grid: { color: '#e9f1fb' }
                    }
                },
                responsive: true,
                maintainAspectRatio: false,
                aspectRatio: 1.25,
            }
        });

        // -- Device Pie Chart --
        if (deviceChart) deviceChart.destroy();
        const deviceCtx = document.getElementById('htf-analytics-device-chart').getContext('2d');
        deviceChart = new Chart(deviceCtx, {
            type: 'doughnut',
            data: {
                labels: topDevices.map(e=>e[0]),
                datasets: [{
                    data: topDevices.map(e=>e[1]),
                    backgroundColor: [
                        '#4e88c7', '#8fc3ff', '#ffc107', '#55bdaf', '#f28e1c', '#cc5cfa'
                    ],
                    borderWidth: 1.5
                }]
            },
            options: {
                plugins: {
                    legend: { display: true, position: 'bottom', labels: { font: { size: 13 }, color: '#223057' } },
                },
                responsive: true,
                maintainAspectRatio: false,
                aspectRatio: 1.05,
                cutout: "65%",
            }
        });

        // -- Country Pie Chart --
        if (countryChart) countryChart.destroy();
        const countryCtx = document.getElementById('htf-analytics-country-chart').getContext('2d');
        countryChart = new Chart(countryCtx, {
            type: 'doughnut',
            data: {
                labels: topCountries.slice(0,7).map(e=>e[0]),
                datasets: [{
                    data: topCountries.slice(0,7).map(e=>e[1]),
                    backgroundColor: [
                        '#4e88c7', '#8fc3ff', '#ffc107', '#55bdaf', '#f28e1c', '#cc5cfa', '#d4e157'
                    ],
                    borderWidth: 1.5
                }]
            },
            options: {
                plugins: {
                    legend: { display: true, position: 'bottom', labels: { font: { size: 13 }, color: '#223057' } },
                },
                responsive: true,
                maintainAspectRatio: false,
                aspectRatio: 1.05,
                cutout: "65%",
            }
        });

        // --- Product Views Chart (Combined Quick View + Product View) ---
        const productViews = {};
        const relevantEvents = ['quick_view', 'product_view', 'view_products_quickview'];
        for (const r of rows) {
            if (relevantEvents.includes(r.event_type) && r.product_id) {
                productViews[r.product_id] = (productViews[r.product_id] || 0) + 1;
            }
        }
        const sortedProductViews = Object.entries(productViews).sort((a, b) => b[1] - a[1]).slice(0, 10); // Top 10 products

        if (productViewsChart) productViewsChart.destroy();
        const productViewsCtx = document.getElementById('htf-analytics-product-views-chart').getContext('2d');
        productViewsChart = new Chart(productViewsCtx, {
            type: 'bar',
            data: {
                labels: sortedProductViews.map(([pid]) => pid.length > 10 ? pid.slice(0, 10) + '…' : pid),
                datasets: [{
                    label: 'Views',
                    data: sortedProductViews.map(([_, count]) => count),
                    backgroundColor: 'rgba(78,136,199,0.87)',
                    borderRadius: 8,
                    maxBarThickness: 38,
                }]
            },
            options: {
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function(context) {
                                // Show full product_id
                                const idx = context[0].dataIndex;
                                return sortedProductViews[idx] ? sortedProductViews[idx][0] : '';
                            }
                        }
                    }
                },
                layout: { padding: { left: 8, right: 8, top: 12, bottom: 8 } },
                scales: {
                    x: {
                        title: { display: false },
                        ticks: { font: { size: 13, weight: 'bold' }, color: '#223057', maxRotation: 0, minRotation: 0 },
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { font: { size: 13 }, color: '#4e88c7' },
                        grid: { color: '#e9f1fb' }
                    }
                },
                responsive: true,
                maintainAspectRatio: false,
                aspectRatio: 1.25,
            }
        });
    }

    function refreshAll() {
        document.querySelector('#htf-analytics-table tbody').innerHTML = `<tr><td colspan="10" style="text-align:center;">Loading...</td></tr>`;
        fetchAnalyticsData(function(rows){
            renderSummary(rows);
            renderCharts(rows);
            renderTable(rows);
        });
    }

    applyBtn.addEventListener('click', function(e){
        e.preventDefault();
        refreshAll();
    });
    document.getElementById('htf-analytics-filter-form').addEventListener('submit', function(e){
        e.preventDefault();
        refreshAll();
    });

    refreshAll();
}