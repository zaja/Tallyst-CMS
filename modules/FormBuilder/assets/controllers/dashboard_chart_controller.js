import { Controller } from '@hotwired/stimulus';
// Import from the mapped 'chart.js' (tree-shakeable core) and register all components ourselves —
// the 'chart.js/auto' convenience subpath is NOT a resolvable importmap specifier under AssetMapper.
import { Chart, registerables } from 'chart.js';

Chart.register(...registerables);

/*
 * Revenue chart for the admin dashboard. The server sends ~13 months of DAILY revenue (minor units,
 * primary currency) once; period switching (7d / 30d / 12mj + a date range) is client-side — no reload.
 * Aggregation by readability: a window ≤ 60 days renders per DAY, otherwise per MONTH.
 *
 * Dark-mode safe: axis/text colours are read from the live Bootstrap theme variables at draw time
 * (EA uses data-bs-theme). Empty series → a "no data" message, never a crash.
 */
export default class extends Controller {
    static values = { series: Array, currency: String, revenueLabel: String, ordersLabel: String };
    static targets = ['canvas', 'empty', 'periodBtn', 'from', 'to'];

    connect() {
        // series: [{ d: 'YYYY-MM-DD', total: <minor>, orders: <count> }]
        this.byDay = new Map(this.seriesValue.map((r) => [r.d, r.total]));
        this.byDayOrders = new Map(this.seriesValue.map((r) => [r.d, r.orders || 0]));
        if (0 === this.seriesValue.length) {
            this.showEmpty();
            return;
        }
        this.renderPeriod(7);
    }

    disconnect() {
        this.chart?.destroy();
        this.chart = null;
    }

    setPeriod(event) {
        const days = parseInt(event.currentTarget.dataset.period, 10);
        this.periodBtnTargets.forEach((b) => b.classList.toggle('active', b === event.currentTarget));
        if (this.hasFromTarget) { this.fromTarget.value = ''; }
        if (this.hasToTarget) { this.toTarget.value = ''; }
        this.renderPeriod(days);
    }

    setRange() {
        const from = this.fromTarget.value;
        const to = this.toTarget.value;
        if (!from || !to) {
            return;
        }
        const start = new Date(from + 'T00:00:00');
        const end = new Date(to + 'T00:00:00');
        if (end < start) {
            return;
        }
        this.periodBtnTargets.forEach((b) => b.classList.remove('active'));
        this.render(start, end);
    }

    renderPeriod(days) {
        const end = this.today();
        const start = new Date(end);
        start.setDate(start.getDate() - (days - 1));
        this.render(start, end);
    }

    render(start, end) {
        const spanDays = Math.round((end - start) / 86400000) + 1;
        const { labels, data, orders } = spanDays <= 60 ? this.aggregateDaily(start, end) : this.aggregateMonthly(start, end);
        this.draw(labels, data, orders);
    }

    aggregateDaily(start, end) {
        const labels = [];
        const data = [];
        const orders = [];
        for (const d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
            labels.push(d.toLocaleDateString('hr-HR', { day: '2-digit', month: '2-digit' }));
            data.push((this.byDay.get(this.iso(d)) || 0) / 100);
            orders.push(this.byDayOrders.get(this.iso(d)) || 0);
        }
        return { labels, data, orders };
    }

    aggregateMonthly(start, end) {
        const buckets = new Map();
        const orderBuckets = new Map();
        for (const [day, minor] of this.byDay) {
            const dt = new Date(day + 'T00:00:00');
            if (dt < start || dt > end) {
                continue;
            }
            const key = day.slice(0, 7);
            buckets.set(key, (buckets.get(key) || 0) + minor);
            orderBuckets.set(key, (orderBuckets.get(key) || 0) + (this.byDayOrders.get(day) || 0));
        }
        const labels = [];
        const data = [];
        const orders = [];
        const cur = new Date(start.getFullYear(), start.getMonth(), 1);
        const last = new Date(end.getFullYear(), end.getMonth(), 1);
        while (cur <= last) {
            const key = `${cur.getFullYear()}-${String(cur.getMonth() + 1).padStart(2, '0')}`;
            labels.push(cur.toLocaleDateString('hr-HR', { month: '2-digit', year: 'numeric' }));
            data.push((buckets.get(key) || 0) / 100);
            orders.push(orderBuckets.get(key) || 0);
            cur.setMonth(cur.getMonth() + 1);
        }
        return { labels, data, orders };
    }

    draw(labels, data, orders) {
        this.emptyTarget.hidden = true;
        this.canvasTarget.hidden = false;

        const styles = getComputedStyle(document.body);
        const text = styles.getPropertyValue('--bs-body-color').trim() || '#212529';
        const grid = styles.getPropertyValue('--bs-border-color').trim() || 'rgba(0,0,0,.1)';
        const brand = styles.getPropertyValue('--bs-primary').trim() || '#0d6efd';
        const ordersColor = styles.getPropertyValue('--bs-success').trim() || '#198754';
        const cur = this.currencyValue;
        const revenueLabel = `${this.revenueLabelValue || 'Revenue'} (${cur})`;
        const ordersLabel = this.ordersLabelValue || 'Orders';

        this.chart?.destroy();
        this.chart = new Chart(this.canvasTarget, {
            // Mixed chart: revenue as bars (left axis, money) + orders as a line (right axis, count).
            // Two y-axes because the scales are unrelated (EUR vs an integer count).
            type: 'bar',
            data: {
                labels,
                datasets: [
                    { label: revenueLabel, data, backgroundColor: brand, borderRadius: 4, yAxisID: 'y' },
                    { type: 'line', label: ordersLabel, data: orders, borderColor: ordersColor, backgroundColor: ordersColor, borderWidth: 2, tension: 0.3, pointRadius: 2, yAxisID: 'y1' },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: true, labels: { color: text } },
                    tooltip: {
                        callbacks: {
                            label: (c) => (c.dataset.yAxisID === 'y1'
                                ? `${ordersLabel}: ${c.parsed.y}`
                                : `${revenueLabel}: ${c.parsed.y.toLocaleString('hr-HR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`),
                        },
                    },
                },
                scales: {
                    x: { ticks: { color: text }, grid: { color: grid } },
                    y: { type: 'linear', position: 'left', beginAtZero: true, ticks: { color: text }, grid: { color: grid } },
                    // Right axis for the order count: integer ticks, no gridlines (avoid double grid).
                    y1: { type: 'linear', position: 'right', beginAtZero: true, ticks: { color: text, precision: 0 }, grid: { drawOnChartArea: false } },
                },
            },
        });
    }

    showEmpty() {
        this.canvasTarget.hidden = true;
        this.emptyTarget.hidden = false;
    }

    today() {
        const d = new Date();
        d.setHours(0, 0, 0, 0);
        return d;
    }

    iso(d) {
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    }
}
