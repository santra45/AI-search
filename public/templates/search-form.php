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

    <?php if ($atts['show_filters']): ?>
    <!-- Filters Panel -->
    <div class="ssw-filters-panel" style="display: none;">
        <div class="ssw-filters-header">
            <h3><?php _e('Filters', 'semantic-search-woo'); ?></h3>
            <button type="button" class="ssw-filters-toggle">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 12 15 18 9"></polyline>
                </svg>
            </button>
        </div>
        
        <div class="ssw-filters-content">
            <!-- Categories Filter -->
            <?php if (!empty($categories) && !is_wp_error($categories)): ?>
            <div class="ssw-filter-group">
                <h4><?php _e('Categories', 'semantic-search-woo'); ?></h4>
                <div class="ssw-filter-options">
                    <?php foreach ($categories as $category): ?>
                    <label class="ssw-filter-label">
                        <input type="checkbox" name="category[]" value="<?php echo esc_attr($category->term_id); ?>">
                        <span class="ssw-filter-checkbox"></span>
                        <span class="ssw-filter-name"><?php echo esc_html($category->name); ?></span>
                        <span class="ssw-filter-count">(<?php echo $category->count; ?>)</span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Price Range Filter -->
            <div class="ssw-filter-group">
                <h4><?php _e('Price Range', 'semantic-search-woo'); ?></h4>
                <div class="ssw-price-range">
                    <input type="number" name="min_price" placeholder="<?php _e('Min', 'semantic-search-woo'); ?>" min="0">
                    <span>-</span>
                    <input type="number" name="max_price" placeholder="<?php _e('Max', 'semantic-search-woo'); ?>" min="0">
                </div>
            </div>

            <!-- Clear Filters Button -->
            <button type="button" class="ssw-clear-filters">
                <?php _e('Clear filters', 'semantic-search-woo'); ?>
            </button>
        </div>
    </div>

    <!-- Filters Toggle Button -->
    <button type="button" class="ssw-filters-toggle-btn" style="display: none;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="4" y1="21" x2="4" y2="14"></line>
            <line x1="4" y1="10" x2="4" y2="3"></line>
            <line x1="12" y1="21" x2="12" y2="12"></line>
            <line x1="12" y1="8" x2="12" y2="3"></line>
            <line x1="20" y1="21" x2="20" y2="16"></line>
            <line x1="20" y1="12" x2="20" y2="3"></line>
            <line x1="1" y1="14" x2="7" y2="14"></line>
            <line x1="9" y1="8" x2="15" y2="8"></line>
            <line x1="17" y1="16" x2="23" y2="16"></line>
        </svg>
        <?php _e('Filters', 'semantic-search-woo'); ?>
    </button>
    <?php endif; ?>

    <!-- Search History -->
    <?php if ($atts['show_history']): ?>
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
        <p><?php _e('Try adjusting your search or filters', 'semantic-search-woo'); ?></p>
    </div>
</div>
