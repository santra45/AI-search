/**
 * Semantic Search Frontend JavaScript
 * Handles search functionality, suggestions, filters, and product display
 */

(function($) {
    'use strict';

    class SemanticSearch {
        constructor(container) {
            this.container = $(container);
            this.searchInput = this.container.find('.ssw-search-input');
            this.searchButton = this.container.find('.ssw-search-button');
            this.clearButton = this.container.find('.ssw-clear-button');
            this.suggestionsDropdown = this.container.find('.ssw-suggestions-dropdown');
            this.resultsContainer = this.container.find('.ssw-results-container');
            this.productsGrid = this.container.find('.ssw-products-grid');
            this.noResults = this.container.find('.ssw-no-results');
            this.loadingIndicator = this.container.find('.ssw-search-loading');
            this.skeletonLoader = this.container.find('.ssw-skeleton-loader');
            
            // Configuration
            this.config = {
                apiUrl: semanticSearchConfig.apiUrl,
                nonce: semanticSearchConfig.nonce,
                addToCartNonce: semanticSearchConfig.addToCartNonce,
                ajaxUrl: semanticSearchConfig.ajaxUrl,
                texts: semanticSearchConfig.texts,
                limit: parseInt(this.container.data('limit')) || 12,
                columns: parseInt(this.container.data('columns')) || 4,
                layout: this.container.data('layout') || 'default',
                minChars: 2,
                debounceDelay: 300,
                maxHistory: 5,
                locale: semanticSearchConfig.locale,
                currency: semanticSearchConfig.currency,
                currencySymbol: semanticSearchConfig.currencySymbol,
                currencyPosition: semanticSearchConfig.currencyPosition,
                decimals: semanticSearchConfig.decimals
            };
            
            // State
            this.currentQuery = '';
            this.currentPage = 1;
            this.totalPages = 1;
            this.isLoading = false;
            this.searchHistory = this.getSearchHistory();
            this.abortController = null;
            
            this.init();
        }

        init() {
            this.bindEvents();
        }

        bindEvents() {
            // Search input events
            this.searchInput.on('input', $.debounce(this.handleSearchInput.bind(this), this.config.debounceDelay));
            this.searchInput.on('focus', this.handleInputFocus.bind(this));
            this.searchInput.on('keydown', this.handleKeydown.bind(this));
            
            // Button events
            this.searchButton.on('click', this.performSearch.bind(this));
            this.clearButton.on('click', this.clearSearch.bind(this));
            
            // Click outside to close suggestions
            $(document).on('click', (e) => {
                if (!this.container.has(e.target).length) {
                    this.hideSuggestions();
                }
            });
            
            // Search history
            this.container.find('.ssw-clear-history').on('click', this.clearSearchHistory.bind(this));
            
            // Load more
            this.container.find('.ssw-load-more').on('click', this.loadMoreProducts.bind(this));
        }

        handleSearchInput(e) {
            const query = e.target.value.trim();
            
            // Show/hide clear button
            this.clearButton.toggle(query.length > 0);
            
            if (query.length < this.config.minChars) {
                this.hideSuggestions();
                return;
            }
            
            this.currentQuery = query;
            this.getSuggestions(query);
        }

        handleInputFocus() {
            if (this.searchInput.val().trim().length >= this.config.minChars) {
                this.getSuggestions(this.searchInput.val().trim());
            } else {
                this.showSearchHistory();
            }
        }

        handleKeydown(e) {
            const suggestions = this.suggestionsDropdown.find('.ssw-suggestion-item');
            const currentIndex = suggestions.index(suggestions.filter('.active'));
            
            switch (e.keyCode) {
                case 40: // Down arrow
                    e.preventDefault();
                    if (currentIndex < suggestions.length - 1) {
                        suggestions.removeClass('active').eq(currentIndex + 1).addClass('active');
                    }
                    break;
                    
                case 38: // Up arrow
                    e.preventDefault();
                    if (currentIndex > 0) {
                        suggestions.removeClass('active').eq(currentIndex - 1).addClass('active');
                    }
                    break;
                    
                case 13: // Enter
                    e.preventDefault();
                    const activeSuggestion = suggestions.filter('.active');
                    if (activeSuggestion.length) {
                        this.selectSuggestion(activeSuggestion);
                    } else {
                        this.performSearch();
                    }
                    break;
                    
                case 27: // Escape
                    this.hideSuggestions();
                    this.searchInput.blur();
                    break;
            }
        }

        async getSuggestions(query) {
            try {
                const response = await $.ajax({
                    url: this.config.apiUrl + 'suggestions',
                    method: 'GET',
                    data: { query, limit: 5 },
                    beforeSend: (xhr) => {
                        xhr.setRequestHeader('X-WP-Nonce', this.config.nonce);
                    }
                });

                this.displaySuggestions(response.data || []);
            } catch (error) {
                console.warn('Suggestions fetch failed:', error);
                this.hideSuggestions();
            }
        }

        displaySuggestions(suggestions) {
            if (!suggestions.length) {
                this.hideSuggestions();
                return;
            }

            let html = '';
            
            // Did you mean suggestions
            const didYouMean = suggestions.filter(s => s.type === 'did_you_mean');
            if (didYouMean.length) {
                html += '<div class="ssw-suggestion-group">';
                html += `<div class="ssw-suggestion-type">${this.config.texts.didYouMean}</div>`;
                didYouMean.forEach(suggestion => {
                    html += this.createSuggestionHTML(suggestion);
                });
                html += '</div>';
            }

            // Auto-complete suggestions
            const autoComplete = suggestions.filter(s => s.type === 'auto_complete');
            if (autoComplete.length) {
                html += '<div class="ssw-suggestion-group">';
                html += `<div class="ssw-suggestion-type">${this.config.texts.suggestions}</div>`;
                autoComplete.forEach(suggestion => {
                    html += this.createSuggestionHTML(suggestion);
                });
                html += '</div>';
            }

            this.suggestionsDropdown.find('.ssw-suggestions-content').html(html);
            this.suggestionsDropdown.show();
            
            // Bind click events to suggestions
            this.suggestionsDropdown.find('.ssw-suggestion-item')
                .on('click', (e) => this.selectSuggestion($(e.currentTarget)));
        }

        createSuggestionHTML(suggestion) {
            const highlightedText = suggestion.text.replace(
                new RegExp(this.currentQuery, 'gi'),
                match => `<span class="ssw-suggestion-highlight">${match}</span>`
            );
            
            return `
                <div class="ssw-suggestion-item" data-query="${suggestion.text}">
                    <div class="ssw-suggestion-text">${highlightedText}</div>
                </div>
            `;
        }

        selectSuggestion(suggestionElement) {
            const query = suggestionElement.data('query');
            this.searchInput.val(query);
            this.currentQuery = query;
            this.hideSuggestions();
            this.performSearch();
        }

        hideSuggestions() {
            this.suggestionsDropdown.hide();
        }

        showSearchHistory() {
            if (!this.searchHistory.length) {
                return;
            }

            let html = '';
            this.searchHistory.forEach(term => {
                html += `<div class="ssw-history-item" data-query="${term}">${term}</div>`;
            });

            this.container.find('.ssw-history-items').html(html);
            this.container.find('.ssw-search-history').show();
            
            // Bind click events
            this.container.find('.ssw-history-item').on('click', (e) => {
                const query = $(e.currentTarget).data('query');
                this.searchInput.val(query);
                this.currentQuery = query;
                this.performSearch();
            });
        }

        hideSearchHistory() {
            this.container.find('.ssw-search-history').hide();
        }

        async performSearch() {
            if (!this.currentQuery || this.isLoading) {
                return;
            }

            // Cancel previous request
            if (this.abortController) {
                this.abortController.abort();
            }

            this.abortController = new AbortController();
            this.isLoading = true;
            this.currentPage = 1;

            // Add to search history
            this.addToSearchHistory(this.currentQuery);
            this.hideSuggestions();
            this.hideSearchHistory();

            // Show loading state
            this.showLoading();

            try {
                const response = await $.ajax({
                    url: this.config.apiUrl + 'search',
                    method: 'POST',
                    data: {
                        query: this.currentQuery,
                        limit: this.config.limit,
                        page: this.currentPage,
                        filters: {}
                    },
                    beforeSend: (xhr) => {
                        xhr.setRequestHeader('X-WP-Nonce', this.config.nonce);
                    },
                    signal: this.abortController.signal
                });

                this.displayResults(response.data || {});
            } catch (error) {
                if (error.name !== 'AbortError') {
                    console.error('Search failed:', error);
                    this.showError();
                }
            } finally {
                this.isLoading = false;
                this.hideLoading();
            }
        }

        showLoading() {
            this.loadingIndicator.show();
            this.skeletonLoader.show();
            this.resultsContainer.hide();
            this.noResults.hide();
        }

        hideLoading() {
            this.loadingIndicator.hide();
            this.skeletonLoader.hide();
        }

        displayResults(data) {
            if (!data.products || !data.products.length) {
                this.showNoResults();
                return;
            }

            this.totalPages = data.total_pages || 1;
            this.renderProducts(data.products);
            this.updateResultsHeader(data);
            
            this.resultsContainer.show();
            this.noResults.hide();
            
            // Show/hide load more button
            const loadMoreContainer = this.container.find('.ssw-load-more-container');
            if (this.currentPage < this.totalPages) {
                loadMoreContainer.show();
            } else {
                loadMoreContainer.hide();
            }
        }

        renderProducts(products) {
            let html = '';
            products.forEach(product => {
                html += this.createProductCardHTML(product);
            });
            
            if (this.currentPage === 1) {
                this.productsGrid.html(html);
            } else {
                this.productsGrid.append(html);
            }

            // Bind product card events
            this.bindProductCardEvents();
        }

        createProductCardHTML(product) {
            const imageUrl = product.image || '';
            const isOnSale = product.on_sale;
            const isInStock = product.stock_status === 'instock';
            const isVariable = product.type === 'variable';
            const productUrl = product.permalink;

            // Price display
            let priceHtml = '';
            if (isVariable) {
                // For variable products, show price range
                const minPrice = parseFloat(product.min_price || product.price || 0);
                const maxPrice = parseFloat(product.max_price || product.price || 0);
                
                if (minPrice === maxPrice) {
                    priceHtml = `<div class="ssw-product-price">${this.formatPrice(minPrice)}</div>`;
                } else {
                    priceHtml = `<div class="ssw-product-price">${this.formatPrice(minPrice)} - ${this.formatPrice(maxPrice)}</div>`;
                }
            } else {
                // For simple products
                if (isOnSale && product.sale_price && product.regular_price) {
                    priceHtml = `
                        <div class="ssw-product-price">
                            <span class="sale-price">${this.formatPrice(product.sale_price)}</span>
                            <span class="regular-price">${this.formatPrice(product.regular_price)}</span>
                        </div>
                    `;
                } else {
                    priceHtml = `<div class="ssw-product-price">${this.formatPrice(product.price)}</div>`;
                }
            }

            return `
                <div class="ssw-product-card" data-product-id="${product.id}" data-product-url="${productUrl}">
                    <div class="ssw-product-image">
                        ${imageUrl ? `<img src="${imageUrl}" alt="${product.name}" loading="lazy">` : ''}
                        ${isOnSale ? '<div class="ssw-product-badge">Sale</div>' : ''}
                    </div>
                    <div class="ssw-product-content">
                        <h3 class="ssw-product-title">${product.name}</h3>
                        ${priceHtml}
                        <div class="ssw-product-actions">
                            ${isInStock ? 
                                (isVariable ? 
                                    `<button class="ssw-select-options" data-product-id="${product.id}">${this.config.texts.selectOptions}</button>` :
                                    `<button class="ssw-add-to-cart" data-product-id="${product.id}">${this.config.texts.addToCart}</button>`
                                ) :
                                `<div class="ssw-out-of-stock">${this.config.texts.outOfStock}</div>`
                            }
                        </div>
                    </div>
                </div>
            `;
        }

        bindProductCardEvents() {
            // Add to cart buttons
            this.productsGrid.find('.ssw-add-to-cart').on('click', (e) => {
                e.stopPropagation();
                this.addToCart($(e.currentTarget));
            });

            // Select options buttons
            this.productsGrid.find('.ssw-select-options').on('click', (e) => {
                e.stopPropagation();
                const productCard = $(e.currentTarget).closest('.ssw-product-card');
                const url = productCard.data('product-url');
                if (url) {
                    window.location.href = url;
                }
            });

            // Product card click
            this.productsGrid.find('.ssw-product-card').on('click', (e) => {
                if (!$(e.target).is('button')) {
                    const productCard = $(e.currentTarget);
                    const url = productCard.data('product-url');
                    if (url) {
                        window.location.href = url;
                    }
                }
            });
        }

        async addToCart(button) {
            const productId = button.data('product-id');
            const originalText = button.text();
            
            button.addClass('loading').text('Adding...');
            
            // Ensure we have the correct AJAX URL
            let ajaxUrl = semanticSearchConfig.ajaxUrl;
            if (!ajaxUrl || ajaxUrl.indexOf('admin-ajax.php') === -1) {
                // Fallback: construct the admin-ajax.php URL
                const currentUrl = window.location.href;
                const baseUrl = currentUrl.split('/wp-')[0];
                ajaxUrl = baseUrl + '/wp-admin/admin-ajax.php';
            }
            
            try {
                const response = await $.ajax({
                    url: ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'ssw_add_to_cart',
                        product_id: productId,
                        quantity: 1,
                        nonce: semanticSearchConfig.addToCartNonce
                    }
                });

                if (response.success) {
                    button.text('Added!');
                    setTimeout(() => {
                        button.text(originalText);
                    }, 2000);
                    
                    // Trigger cart update event
                    $(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, button]);
                    
                    // Update cart fragments if provided
                    if (response.fragments) {
                        $.each(response.fragments, function(key, value) {
                            $(key).replaceWith(value);
                        });
                    }
                } else {
                    button.text(originalText);
                    alert(response.data?.message || 'Failed to add to cart. Please try again.');
                }
            } catch (error) {
                button.text(originalText);
                console.error('Add to cart failed:', error);
                alert('Failed to add to cart. Please try again.');
            }
        }

        updateResultsHeader(data) {
            const header = this.container.find('.ssw-results-header');
            const title = header.find('.ssw-results-title');
            const count = header.find('.ssw-results-count');
            const time = header.find('.ssw-results-time');
            
            if (data.products && data.products.length > 0) {
                title.text(`Results for "${this.currentQuery}"`);
                count.text(`${data.total} products found`);
                time.text(`${data.search_time}s`);
                header.show();
            }
        }

        formatPrice(price) {
            if (!price) return '';
            const value = parseFloat(price);
            const locale = (this.config.locale || 'en-US').replace('_', '-');
            const formattedNumber = new Intl.NumberFormat(locale, {
                minimumFractionDigits: parseInt(this.config.decimals),
                maximumFractionDigits: parseInt(this.config.decimals)
            }).format(value);
            const symbol = this.config.currencySymbol;
            switch (this.config.currencyPosition) {
                case 'left':
                    return symbol + formattedNumber;
                case 'right':
                    return formattedNumber + symbol;
                case 'left_space':
                    return symbol + ' ' + formattedNumber;
                case 'right_space':
                    return formattedNumber + ' ' + symbol;
                default:
                    return symbol + formattedNumber;
            }
        }

        showNoResults() {
            this.resultsContainer.hide();
            this.noResults.show();
        }

        showError() {
            this.resultsContainer.hide();
            this.noResults.find('h3').text('Search Error');
            this.noResults.find('p').text('An error occurred while searching. Please try again.');
            this.noResults.show();
        }

        clearSearch() {
            this.searchInput.val('');
            this.currentQuery = '';
            this.clearButton.hide();
            this.hideSuggestions();
            this.resultsContainer.hide();
            this.noResults.hide();
            this.searchInput.focus();
        }

        async loadMoreProducts() {
            if (this.isLoading || this.currentPage >= this.totalPages) {
                return;
            }

            this.currentPage++;
            const loadMoreButton = this.container.find('.ssw-load-more');
            const originalText = loadMoreButton.text();
            
            loadMoreButton.addClass('loading').text('Loading...');

            try {
                const response = await $.ajax({
                    url: this.config.apiUrl + 'search',
                    method: 'POST',
                    data: {
                        query: this.currentQuery,
                        limit: this.config.limit,
                        page: this.currentPage,
                        filters: {}
                    },
                    beforeSend: (xhr) => {
                        xhr.setRequestHeader('X-WP-Nonce', this.config.nonce);
                    }
                });

                if (response.data && response.data.products) {
                    this.renderProducts(response.data.products);
                    
                    if (this.currentPage >= this.totalPages) {
                        this.container.find('.ssw-load-more-container').hide();
                    }
                }
            } catch (error) {
                console.error('Load more failed:', error);
                this.currentPage--; // Reset page on error
            } finally {
                loadMoreButton.removeClass('loading').text(originalText);
            }
        }

        getSearchHistory() {
            const history = localStorage.getItem('ssw_search_history');
            return history ? JSON.parse(history) : [];
        }

        addToSearchHistory(query) {
            // Remove if already exists
            this.searchHistory = this.searchHistory.filter(term => term !== query);
            
            // Add to beginning
            this.searchHistory.unshift(query);
            
            // Keep only max items
            this.searchHistory = this.searchHistory.slice(0, this.config.maxHistory);
            
            // Save to localStorage
            localStorage.setItem('ssw_search_history', JSON.stringify(this.searchHistory));
        }

        clearSearchHistory() {
            this.searchHistory = [];
            localStorage.removeItem('ssw_search_history');
            this.hideSearchHistory();
        }

        loadSearchHistory() {
            // This is called on init, but we only show history on focus
        }
    }

    // Initialize semantic search when DOM is ready
    $(document).ready(function() {
        $('.ssw-search-container').each(function() {
            new SemanticSearch(this);
        });
    });

    // Debounce utility
    $.debounce = function(func, wait, immediate) {
        let timeout;
        return function() {
            const context = this;
            const args = arguments;
            const later = function() {
                timeout = null;
                if (!immediate) func.apply(context, args);
            };
            const callNow = immediate && !timeout;
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
            if (callNow) func.apply(context, args);
        };
    };

})(jQuery);
