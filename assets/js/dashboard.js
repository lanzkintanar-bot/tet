/** Dashboard sales analytics charts. Data is rendered server-side in index.php. */
(function () {
    'use strict';

    const analytics = window.DASHBOARD_ANALYTICS || { trend: [], payments: [] };
    const accent = '#E07A2C';
    const paymentPalette = [accent, '#16233F', '#2F9E64', '#F2A65A', '#D64545', '#6B7280'];

    let revenueChart = null;
    let paymentChart = null;

    /** Chart.js renders to a canvas, so it never picks up CSS variables or [data-theme] automatically - resolve the actual colors to use for the current theme each time we (re)draw. */
    function themeColors() {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        return {
            muted: isDark ? '#97A0B8' : '#6B7280',
            text: isDark ? '#E7E9F0' : '#1F2430',
            grid: isDark ? 'rgba(231,233,240,.08)' : 'rgba(22,35,63,.08)',
            legendBg: isDark ? '#182238' : '#FFFFFF',
        };
    }

    function peso(value) {
        return '₱' + Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function shortDate(isoDate) {
        // 'YYYY-MM-DD' -> 'MM-DD', avoiding a Date() parse (and its
        // timezone-shift footguns) for a plain label slice.
        return isoDate.slice(5);
    }

    function renderRevenue() {
        const canvas = document.getElementById('dashboardRevenueChart');
        if (!canvas) return;
        if (!analytics.trend.length) {
            canvas.classList.add('d-none');
            document.getElementById('dashboardRevenueEmpty').classList.remove('d-none');
            return;
        }
        canvas.classList.remove('d-none');
        document.getElementById('dashboardRevenueEmpty').classList.add('d-none');

        const colors = themeColors();
        if (revenueChart) revenueChart.destroy();
        revenueChart = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: analytics.trend.map(row => shortDate(row.date)),
                datasets: [{ label: 'Revenue', data: analytics.trend.map(row => row.revenue), borderColor: accent, backgroundColor: 'rgba(224,122,44,.12)', fill: true, tension: .28, pointRadius: 3, pointBackgroundColor: accent }],
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: context => peso(context.raw) } } },
                scales: {
                    x: { grid: { display: false }, ticks: { color: colors.muted } },
                    y: { beginAtZero: true, ticks: { color: colors.muted, callback: value => '₱' + Number(value).toLocaleString() }, grid: { color: colors.grid } },
                },
            },
        });
    }

    function renderPayments() {
        const canvas = document.getElementById('dashboardPaymentChart');
        if (!canvas) return;
        if (!analytics.payments.length) {
            canvas.classList.add('d-none');
            document.getElementById('dashboardPaymentEmpty').classList.remove('d-none');
            return;
        }
        canvas.classList.remove('d-none');
        document.getElementById('dashboardPaymentEmpty').classList.add('d-none');

        const colors = themeColors();
        if (paymentChart) paymentChart.destroy();
        paymentChart = new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: { labels: analytics.payments.map(row => row.payment_method.toUpperCase()), datasets: [{ data: analytics.payments.map(row => row.revenue), backgroundColor: paymentPalette, borderColor: colors.legendBg, borderWidth: 2 }] },
            options: { responsive: true, cutout: '65%', plugins: { legend: { position: 'bottom', labels: { color: colors.muted, usePointStyle: true, boxWidth: 8 } }, tooltip: { callbacks: { label: context => context.label + ': ' + peso(context.raw) } } } },
        });
    }

    function renderAll() { renderRevenue(); renderPayments(); }

    document.addEventListener('DOMContentLoaded', renderAll);
    window.addEventListener('pos:theme-changed', renderAll);
})();
