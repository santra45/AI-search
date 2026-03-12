<?php
if (!defined('ABSPATH')) exit;
?>

<div class="ssw-search-container" data-limit="<?php echo esc_attr($atts['limit']); ?>" data-columns="<?php echo esc_attr($atts['columns']); ?>" data-layout="<?php echo esc_attr($atts['layout']); ?>">
    
    <!-- Search Bar -->
    <div class="ssw-search-bar">
        <div class="ssw-search-input-wrapper">
            <input 
                type="text" 
                class="ssw-search-input" 
                placeholder="<?php echo esc_attr($atts['placeholder']); ?>"
                autocomplete="off"
                data-min-chars="2"
            >
            <button type="button" class="ssw-search-button">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"></circle>
                    <path d="m21 21-4.35-4.35"></path>
                </svg>
            </button>
            <button type="button" class="ssw-clear-button" style="display: none;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>
        
        <!-- Loading Indicator -->
        <div class="ssw-search-loading" style="display: none;">
            <div class="ssw-spinner"></div>
            <span class="ssw-loading-text"><?php _e('Searching...', 'semantic-search-woo'); ?></span>
        </div>
    </div>

    <!-- Suggestions Dropdown -->
    <div class="ssw-suggestions-dropdown" style="display: none;">
        <div class="ssw-suggestions-content">
            <!-- Suggestions will be populated here -->
        </div>
    </div>

    <?php if ($atts['show_history']): ?>
    <!-- Search History -->
    <div class="ssw-search-history" style="display: none;">
        <div class="ssw-history-header">
            <h4><?php _e('Recent Searches', 'semantic-search-woo'); ?></h4>
            <button type="button" class="ssw-clear-history"><?php _e('Clear', 'semantic-search-woo'); ?></button>
        </div>
        <div class="ssw-history-items">
            <!-- History items will be populated here -->
        </div>
    </div>
    <?php endif; ?>

    <!-- Results Container -->
    <div class="ssw-results-container" style="display: none;">
        <!-- Results Header -->
        <div class="ssw-results-header" style="display: none;">
            <h3 class="ssw-results-title"></h3>
            <div class="ssw-results-meta">
                <span class="ssw-results-count"></span>
                <span class="ssw-results-time"></span>
            </div>
        </div>

        <!-- Products Grid -->
        <div class="ssw-products-grid">
            <!-- Loading Skeletons -->
            <div class="ssw-skeleton-loader" style="display: none;">
                <?php for ($i = 0; $i < min($atts['limit'], 8); $i++): ?>
                <div class="ssw-skeleton-card">
                    <div class="ssw-skeleton-image"></div>
                    <div class="ssw-skeleton-content">
                        <div class="ssw-skeleton-title"></div>
                        <div class="ssw-skeleton-price"></div>
                        <div class="ssw-skeleton-button"></div>
                    </div>
                </div>
                <?php endfor; ?>
            </div>

            <!-- Products will be populated here -->
        </div>

        <!-- Load More Button -->
        <div class="ssw-load-more-container" style="display: none;">
            <button type="button" class="ssw-load-more">
                <?php _e('Load more', 'semantic-search-woo'); ?>
            </button>
        </div>
    </div>

    <!-- No Results Message -->
    <div class="ssw-no-results" style="display: none;">
        <div class="ssw-no-results-icon">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1">
                <circle cx="11" cy="11" r="8"></circle>
                <path d="m21 21-4.35-4.35"></path>
                <line x1="8" y1="11" x2="14" y2="11"></line>
            </svg>
        </div>
        <h3><?php _e('No products found', 'semantic-search-woo'); ?></h3>
        <p><?php _e('Try adjusting your search terms', 'semantic-search-woo'); ?></p>
    </div>
</div>
