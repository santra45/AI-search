<?php if (!defined('ABSPATH')) exit; ?>

<div id="analytics-panel" class="ssw-tab-panel" style="display:none;">

    <!-- Loading State -->
    <div class="ssw-loading">
        <div class="ssw-card">
            <div class="ssw-card-body" style="text-align:center;padding:40px;">
                <span class="ssw-spinner dark"></span>
                <p style="color:var(--ssw-gray-400);margin-top:12px;font-size:13px;">
                    Loading analytics...
                </p>
            </div>
        </div>
    </div>

    <!-- Content -->
    <div class="ssw-content" style="display:none;">

        <!-- ── Date Filter ───────────────────────────────────────────────────── -->
        <div style="display:flex;justify-content:flex-end;margin-bottom:16px;">
            <select id="ssw-days-filter"
                style="
                    padding: 7px 12px;
                    border: 1px solid var(--ssw-gray-200);
                    border-radius: var(--ssw-radius-sm);
                    font-size: 13px;
                    color: var(--ssw-gray-700);
                    background: white;
                    cursor: pointer;
                ">
                <option value="7"  selected>Last 7 days</option>
                <option value="14">Last 14 days</option>
                <option value="30">Last 30 days</option>
            </select>
        </div>

        <!-- ── Summary Stat Cards ────────────────────────────────────────────── -->
        <div class="ssw-stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));">

            <div class="ssw-stat-card">
                <div class="ssw-stat-icon">🔍</div>
                <div class="ssw-stat-label">Total Searches</div>
                <div class="ssw-stat-value" id="ssw-total-searches">—</div>
                <div class="ssw-stat-sub">in selected period</div>
            </div>

            <div class="ssw-stat-card success">
                <div class="ssw-stat-icon">⚡</div>
                <div class="ssw-stat-label">Cache Hit Rate</div>
                <div class="ssw-stat-value" id="ssw-cache-hit-rate">—</div>
                <div class="ssw-stat-sub">served from cache</div>
            </div>

            <div class="ssw-stat-card">
                <div class="ssw-stat-icon">⏱️</div>
                <div class="ssw-stat-label">Avg Response Time</div>
                <div class="ssw-stat-value" id="ssw-avg-response">—</div>
                <div class="ssw-stat-sub">including cache hits</div>
            </div>

            <div class="ssw-stat-card danger">
                <div class="ssw-stat-icon">❌</div>
                <div class="ssw-stat-label">Zero Result Rate</div>
                <div class="ssw-stat-value" id="ssw-zero-result-rate">—</div>
                <div class="ssw-stat-sub">searches with no results</div>
            </div>

        </div>

        <!-- ── Search Volume Chart ───────────────────────────────────────────── -->
        <div class="ssw-card">
            <div class="ssw-card-header">
                <h2>Search Volume</h2>
                <span class="ssw-badge plan" id="ssw-chart-period">Last 7 days</span>
            </div>
            <div class="ssw-card-body">
                <div class="ssw-chart-wrap">
                    <canvas id="ssw-volume-chart"></canvas>
                </div>
            </div>
        </div>

        <!-- ── Top Queries + Zero Results ───────────────────────────────────── -->
        <div class="ssw-two-col">

            <!-- Top Queries -->
            <div class="ssw-card">
                <div class="ssw-card-header">
                    <h2>Top Searches</h2>
                    <span class="ssw-badge plan">by volume</span>
                </div>
                <div class="ssw-card-body" style="padding:0;">
                    <div class="ssw-table-wrap">
                        <table class="ssw-table" id="ssw-top-queries">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Query</th>
                                    <th>Count</th>
                                    <th>Avg Results</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- filled by JS -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Zero Result Searches -->
            <div class="ssw-card">
                <div class="ssw-card-header">
                    <h2>Zero Result Searches</h2>
                    <span class="ssw-badge zero">gaps in catalog</span>
                </div>
                <div class="ssw-card-body" style="padding:0;">
                    <div class="ssw-table-wrap">
                        <table class="ssw-table" id="ssw-zero-results">
                            <thead>
                                <tr>
                                    <th>Query</th>
                                    <th>Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- filled by JS -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="ssw-card-body"
                     style="border-top:1px solid var(--ssw-gray-200);padding:12px 16px;">
                    <p style="font-size:12px;color:var(--ssw-gray-400);margin:0;">
                        💡 These are searches where no products matched.
                        Consider adding products or improving descriptions
                        for these terms.
                    </p>
                </div>
            </div>

        </div>

    </div><!-- /.ssw-content -->

</div><!-- /#analytics-panel -->