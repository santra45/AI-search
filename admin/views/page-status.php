<?php if (!defined('ABSPATH')) exit; ?>

<div id="status-panel" class="ssw-tab-panel" style="display:none;">

    <!-- ── Connection Status ─────────────────────────────────────────────────── -->
    <div class="ssw-card">
        <div class="ssw-card-header">
            <h2>Connection Status</h2>
            <button id="ssw-run-diagnostic" class="ssw-btn ssw-btn-secondary"
                    style="font-size:12px;padding:5px 12px;">
                🔄 Run Diagnostic
            </button>
        </div>
        <div class="ssw-card-body">

            <!-- Loading -->
            <div id="ssw-status-loading"
                 style="text-align:center;padding:30px 0;">
                <span class="ssw-spinner dark"></span>
                <p style="color:var(--ssw-gray-400);margin-top:10px;font-size:13px;">
                    Checking connection...
                </p>
            </div>

            <!-- Status Rows (filled by JS) -->
            <div id="ssw-status-list" class="ssw-status-list"></div>

        </div>
    </div>

    <!-- ── Account Details ───────────────────────────────────────────────────── -->
    <div class="ssw-card">
        <div class="ssw-card-header">
            <h2>Account Details</h2>
        </div>
        <div class="ssw-card-body">
            <div id="ssw-account-details">
                <div style="text-align:center;padding:20px 0;color:var(--ssw-gray-400);font-size:13px;">
                    Loading account details...
                </div>
            </div>
        </div>
    </div>

    <!-- ── Plugin Info ────────────────────────────────────────────────────────── -->
    <div class="ssw-card">
        <div class="ssw-card-header">
            <h2>Plugin Info</h2>
        </div>
        <div class="ssw-card-body" style="padding:0;">
            <table class="ssw-table">
                <tbody>
                    <tr>
                        <td style="width:200px;">
                            <strong>Plugin Version</strong>
                        </td>
                        <td>
                            <span class="ssw-badge plan">
                                v<?= SSW_VERSION ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>API URL</strong></td>
                        <td style="font-family:monospace;font-size:12px;">
                            <?php
                            $api_url = get_option('ssw_api_url', '');
                            echo $api_url
                                ? esc_html($api_url)
                                : '<span style="color:var(--ssw-gray-400);">Not set</span>';
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>License Key</strong></td>
                        <td>
                            <?php
                            $key = get_option('ssw_license_key', '');
                            if ($key):
                                // Show first 20 chars then mask
                                $preview = substr($key, 0, 20) . '...';
                            ?>
                                <span class="ssw-badge success">✅ Set</span>
                                <span style="font-family:monospace;font-size:11px;
                                             color:var(--ssw-gray-400);margin-left:8px;">
                                    <?= esc_html($preview) ?>
                                </span>
                            <?php else: ?>
                                <span class="ssw-badge zero">❌ Not set</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>WordPress Version</strong></td>
                        <td><?= esc_html(get_bloginfo('version')) ?></td>
                    </tr>
                    <tr>
                        <td><strong>WooCommerce Version</strong></td>
                        <td>
                            <?php
                            if (defined('WC_VERSION')) {
                                echo esc_html(WC_VERSION);
                            } else {
                                echo '<span style="color:var(--ssw-danger);">Not active</span>';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>PHP Version</strong></td>
                        <td><?= esc_html(phpversion()) ?></td>
                    </tr>
                    <tr>
                        <td><strong>Webhooks Registered</strong></td>
                        <td>
                            <?php
                            $registered = get_option('ssw_webhooks_registered', []);
                            $count      = count($registered);
                            if ($count >= 3):
                            ?>
                                <span class="ssw-badge success">✅ <?= $count ?> active</span>
                            <?php else: ?>
                                <span class="ssw-badge zero">
                                    ❌ <?= $count ?> / 3 registered
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── Debug Info ─────────────────────────────────────────────────────────── -->
    <div class="ssw-card">
        <div class="ssw-card-header">
            <h2>Debug Info</h2>
            <span class="ssw-badge warning">For support use</span>
        </div>
        <div class="ssw-card-body">

            <?php
            $sync     = new SSW_Sync(get_option('ssw_license_key', ''));
            $progress = $sync->get_progress();
            ?>

            <table class="ssw-table">
                <tbody>
                    <tr>
                        <td style="width:200px;"><strong>Sync Status</strong></td>
                        <td><?= esc_html(ucfirst($progress['status'])) ?></td>
                    </tr>
                    <tr>
                        <td><strong>Last Sync Started</strong></td>
                        <td>
                            <?= $progress['started_at']
                                ? esc_html(date('Y-m-d H:i:s', $progress['started_at']))
                                : '—' ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Products Processed</strong></td>
                        <td>
                            <?= (int) $progress['processed'] ?>
                            / <?= (int) $progress['total'] ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Failed Products</strong></td>
                        <td>
                            <?php
                            $failed = (int) $progress['failed'];
                            if ($failed > 0):
                            ?>
                                <span class="ssw-badge zero"><?= $failed ?> failed</span>
                            <?php else: ?>
                                <span class="ssw-badge success">None</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Current Batch</strong></td>
                        <td>
                            <?= (int) $progress['current_batch'] ?>
                            / <?= (int) $progress['total_batches'] ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>WP Ajax URL</strong></td>
                        <td style="font-family:monospace;font-size:11px;">
                            <?= esc_html(admin_url('admin-ajax.php')) ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Reset Sync State -->
            <div style="margin-top:16px;padding-top:16px;
                        border-top:1px solid var(--ssw-gray-200);">
                <button
                    class="ssw-btn ssw-btn-danger"
                    style="font-size:12px;"
                    onclick="
                        if (!confirm('Reset sync state? This does not delete indexed products.'))
                            return;
                        jQuery.post(
                            ajaxurl,
                            {
                                action: 'ssw_reset_sync',
                                nonce:  SSW_Config.nonce
                            },
                            function() { location.reload(); }
                        );
                    "
                >
                    🗑️ Reset Sync State
                </button>
                <p style="font-size:12px;color:var(--ssw-gray-400);margin-top:8px;">
                    Clears the sync progress state if it got stuck.
                    Does not delete any indexed products.
                </p>
            </div>

        </div>
    </div>

</div><!-- /#status-panel -->