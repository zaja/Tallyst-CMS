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
    static values = { series: Array, currency: String };
    static targets = ['canvas', 'empty', 'periodBtn', 'from', 'to'];

    connect() {
        // series: [{ d: 'YYYY-MM-DD', total: <minor> }]
        this.byDay = new Map(this.seriesValue.map((r) => [r.d, r.total]));
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
        const { labels, data } = spanDays <= 60 ? this.aggregateDaily(start, end) : this.aggregateMonthly(start, end);
        this.draw(labels, data);
    }

    aggregateDaily(start, end) {
        const labels = [];
        const data = [];
        for (const d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
            labels.push(d.toLocaleDateString('hr-HR', { day: '2-digit', month: '2-digit' }));
            data.push((this.byDay.get(this.iso(d)) || 0) / 100);
        }
        return { labels, data };
    }

    aggregateMonthly(start, end) {
        const buckets = new Map();
        for (const [day, minor] of this.byDay) {
            const dt = new Date(day + 'T00:00:00');
            if (dt < start || dt > end) {
                continue;
            }
            const key = day.slice(0, 7);
            buckets.set(key, (buckets.get(key) || 0) + minor);
        }
        const labels = [];
        const data = [];
        const cur = new Date(start.getFullYear(), start.getMonth(), 1);
        const last = new Date(end.getFullYear(), end.getMonth(), 1);
        while (cur <= last) {
            const key = `${cur.getFullYear()}-${String(cur.getMonth() + 1).padStart(2, '0')}`;
            labels.push(cur.toLocaleDateString('hr-HR', { month: '2-digit', year: 'numeric' }));
            data.push((buckets.get(key) || 0) / 100);
            cur.setMonth(cur.getMonth() + 1);
        }
        return { labels, data };
    }

    draw(labels, data) {
        this.emptyTarget.hidden = true;
        this.canvasTarget.hidden = false;

        const styles = getComputedStyle(document.body);
        const text = styles.getPropertyValue('--bs-body-color').trim() || '#212529';
        const grid = styles.getPropertyValue('--bs-border-color').trim() || 'rgba(0,0,0,.1)';
        const brand = styles.getPropertyValue('--bs-primary').trim() || '#0d6efd';
        const cur = this.currencyValue;

        this.chart?.destroy();
        this.chart = new Chart(this.canvasTarget, {
            type: 'bar',
            data: { labels, datasets: [{ label: `Zarada (${cur})`, data, backgroundColor: brand, borderRadius: 4 }] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (c) => `${c.parsed.y.toLocaleString('hr-HR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${cur}`,
                        },
                    },
                },
                scales: {
                    x: { ticks: { color: text }, grid: { color: grid } },
                    y: { beginAtZero: true, ticks: { color: text }, grid: { color: grid } },
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
