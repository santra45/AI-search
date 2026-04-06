<!-- Usage Panel -->
<div id="usage-panel" class="ssw-tab-panel" style="display:none;">
    <div class="ssw-loading">Loading usage data...</div>
    <div class="ssw-content" style="display:none;">
        
        <!-- Summary Cards -->
        <div class="ssw-cards-grid">
            <div class="ssw-card">
                <div class="ssw-card-header">
                    <h3>Total Requests</h3>
                </div>
                <div class="ssw-card-body">
                    <div id="ssw-usage-total-requests" class="ssw-stat-number">-</div>
                </div>
            </div>
            <div class="ssw-card">
                <div class="ssw-card-header">
                    <h3>Total Tokens</h3>
                </div>
                <div class="ssw-card-body">
                    <div id="ssw-usage-total-tokens" class="ssw-stat-number">-</div>
                </div>
            </div>
            <div class="ssw-card">
                <div class="ssw-card-header">
                    <h3>Total Cost</h3>
                </div>
                <div class="ssw-card-body">
                    <div id="ssw-usage-total-cost" class="ssw-stat-number">-</div>
                </div>
            </div>
        </div>

        <!-- Estimated Monthly Cost -->
        <div class="ssw-card">
            <div class="ssw-card-header">
                <h2>Estimated Monthly Cost</h2>
                <div class="ssw-card-subtitle">Based on recent daily usage patterns</div>
            </div>
            <div class="ssw-card-body">
                <div class="ssw-table-wrap">
                    <table class="ssw-table">
                        <thead>
                            <tr>
                                <th>Period</th>
                                <th>Daily Requests</th>
                                <th>Daily Cost</th>
                                <th>Est. Monthly Cost</th>
                                <th>Trend</th>
                            </tr>
                        </thead>
                        <tbody id="ssw-usage-estimated-cost-tbody">
                            <!-- Filled by JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Usage by Model -->
        <div class="ssw-card">
            <div class="ssw-card-header">
                <h2>Usage by Model</h2>
            </div>
            <div class="ssw-card-body">
                <div class="ssw-table-wrap">
                    <table class="ssw-table">
                        <thead>
                            <tr>
                                <th>Provider</th>
                                <th>Model</th>
                                <th>Type</th>
                                <th>Requests</th>
                                <th>Tokens</th>
                                <th>Cost</th>
                            </tr>
                        </thead>
                        <tbody id="ssw-usage-models-tbody">
                            <!-- Filled by JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Hourly Usage Chart -->
        <div class="ssw-card">
            <div class="ssw-card-header">
                <h2>Hourly Usage (Last 24 Hours)</h2>
            </div>
            <div class="ssw-card-body">
                <canvas id="ssw-usage-hourly-chart" style="max-height:300px;"></canvas>
            </div>
        </div>

        <!-- Query Type Breakdown -->
        <div class="ssw-card">
            <div class="ssw-card-header">
                <h2>Query Type Breakdown</h2>
            </div>
            <div class="ssw-card-body">
                <canvas id="ssw-usage-query-types-chart" style="max-height:300px;"></canvas>
            </div>
        </div>

    </div>
</div>
