<?php
// Runs when plugin is deleted from WordPress admin
// Removes all plugin options from the database

if (!defined('WP_UNINSTALL_PLUGIN')) exit;

$options = [
    'ssw_api_url',
    'ssw_client_id',
    'ssw_license_key',
    'ssw_result_limit',
    'ssw_wc_key',
    'ssw_wc_secret',
    'ssw_webhook_secret',
    'ssw_webhooks_registered',
    'ssw_sync_status',
    'ssw_sync_total',
    'ssw_sync_total_batches',
    'ssw_sync_current_batch',
    'ssw_sync_processed',
    'ssw_sync_failed',
    'ssw_sync_started_at',
    'ssw_enable_intent',
    'ssw_llm_provider',
    'ssw_llm_model',
    'ssw_llm_api_key',
    'ssw_setup_completed',

];

foreach ($options as $option) {
    delete_option($option);
}