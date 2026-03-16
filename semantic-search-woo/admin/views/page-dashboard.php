<?php if (!defined('ABSPATH')) exit; ?>

<div id="dashboard-panel" class="ssw-tab-panel">

    <!-- Loading State -->
    <div class="ssw-loading">
        <div class="ssw-card">
            <div class="ssw-card-body" style="text-align:center;padding:40px;">
                <span class="ssw-spinner dark"></span>
                <p style="color:var(--ssw-gray-400);margin-top:12px;font-size:13px;">
                    Loading dashboard...
                </p>
            </div>
        </div>
    </div>

    <!-- Content (hidden until data loads) -->
    <div class="ssw-content" style="display:none;">

        <!-- ── Stat Cards ───────────────────────────────────────────────────── -->
        <div class="ssw-stats-grid">

            <div class="ssw-stat-card">
                <div class="ssw-stat-icon">🔍</div>
                <div class="ssw-stat-label">Searches This Month</div>
                <div class="ssw-stat-value" id="ssw-stat-searches">—</div>
                <div class="ssw-stat-sub">semantic search queries</div>
            </div>

            <div class="ssw-stat-card success">
                <div class="ssw-stat-icon">📦</div>
                <div class="ssw-stat-label">Products Indexed</div>
                <div class="ssw-stat-value" id="ssw-stat-products">—</div>
                <div class="ssw-stat-sub">in search index</div>
            </div>

            <div class="ssw-stat-card">
                <div class="ssw-stat-icon">⚙️</div>
                <div class="ssw-stat-label">Ingestions This Month</div>
                <div class="ssw-stat-value" id="ssw-stat-ingestions">—</div>
                <div class="ssw-stat-sub">products embedded</div>
            </div>

            <div class="ssw-stat-card warning">
                <div class="ssw-stat-icon">🏷️</div>
                <div class="ssw-stat-label">Current Plan</div>
                <div class="ssw-stat-value"
                     id="ssw-stat-plan"
                     style="font-size:20px;padding-top:4px;">—</div>
                <div class="ssw-stat-sub">active subscription</div>
            </div>

        </div>

        <!-- ── Quota Bars ────────────────────────────────────────────────────── -->
        <div class="ssw-card">
            <div class="ssw-card-header">
                <h2>Monthly Quota</h2>
            </div>
            <div class="ssw-card-body">
                <div class="ssw-quota-section">
                    <div id="ssw-quota-searches"></div>
                    <div id="ssw-quota-products"></div>
                </div>
            </div>
        </div>

        <!-- ── Recent Searches ───────────────────────────────────────────────── -->
        <div class="ssw-card" id="ssw-recent-searches">
            <div class="ssw-card-header">
                <h2>Recent Searches</h2>
                <span class="ssw-badge plan">Last 10</span>
            </div>
            <div class="ssw-card-body" style="padding:0;">
                <div class="ssw-table-wrap">
                    <table class="ssw-table">
                        <thead>
                            <tr>
                                <th>Query</th>
                                <th>Results</th>
                                <th>Response Time</th>
                                <th>Source</th>
                                <th>When</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- filled by JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div><!-- /.ssw-content -->

</div><!-- /#dashboard-panel -->