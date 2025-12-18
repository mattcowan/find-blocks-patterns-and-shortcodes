/**
 * Admin JavaScript for Find Blocks, Patterns & Shortcodes plugin
 * Loaded only on the plugin's admin page
 */

(function($){
    $(function(){
        var timer;
        var currentNonce = fbpsData.nonce;
        var allResults = [];
        var allPatternResults = [];
        var allShortcodeResults = [];
        var currentSearch = null;
        var currentPatternSearch = null;
        var currentShortcodeSearch = null;

        // Handle block dropdown selection
        $('#fbps-block-dropdown').on('change', function() {
            var selectedBlock = $(this).val();
            if (selectedBlock) {
                $('#fbps-block-name').val(selectedBlock);
            }
        });

        // Refresh nonce every 5 minutes (more frequent for long operations)
        setInterval(function() {
            $.post(fbpsData.ajaxUrl, {
                action: 'fbps_refresh_nonce'
            }, function(response) {
                if (response.success && response.data && response.data.nonce) {
                    currentNonce = response.data.nonce;
                }
            });
        }, 5 * 60 * 1000); // 5 minutes

        // Also refresh before each search if needed
        function ensureFreshNonce(callback) {
            $.post(fbpsData.ajaxUrl, {
                action: 'fbps_refresh_nonce'
            }, function(response) {
                if (response.success && response.data && response.data.nonce) {
                    currentNonce = response.data.nonce;
                }
                callback();
            });
        }

        // Escape HTML to prevent XSS
        function escapeHtml(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        // Sanitize CSV value to prevent formula injection
        function sanitizeCsvValue(value) {
            value = String(value);
            // Escape double quotes for CSV format
            value = value.replace(/"/g, '""');
            // Prevent formula injection by prefixing dangerous characters with single quote
            if (/^[=+\-@|%]/.test(value)) {
                value = "'" + value;
            }
            return value;
        }

        // Format date to match WordPress admin style
        function formatDate(dateString) {
            var date = new Date(dateString);
            if (isNaN(date.getTime())) return dateString;

            var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            var year = date.getFullYear();
            var month = months[date.getMonth()];
            var day = date.getDate();

            return month + ' ' + day + ', ' + year;
        }

        // Initialize table sorting
        function initTableSort(containerSelector) {
            $(containerSelector + ' .fbps-results-table th.sortable a').off('click').on('click', function(e){
                e.preventDefault();
                var $th = $(this).closest('th');
                var column = $th.data('column');
                var $table = $th.closest('table');
                var isAsc = $th.hasClass('sorted') && $th.hasClass('asc');

                // Remove sorted class from all headers
                $table.find('th').removeClass('sorted asc desc');

                // Add sorted class to current header
                $th.addClass('sorted').addClass(isAsc ? 'desc' : 'asc');

                // Sort the rows
                var $rows = $table.find('tbody tr').get();
                $rows.sort(function(a, b){
                    var aVal = $(a).data(column);
                    var bVal = $(b).data(column);

                    // Handle date sorting
                    if (column === 'date') {
                        aVal = new Date(aVal).getTime();
                        bVal = new Date(bVal).getTime();
                    } else {
                        // Case-insensitive string sorting
                        aVal = String(aVal).toLowerCase();
                        bVal = String(bVal).toLowerCase();
                    }

                    if (aVal < bVal) return isAsc ? 1 : -1;
                    if (aVal > bVal) return isAsc ? -1 : 1;
                    return 0;
                });

                $.each($rows, function(index, row){
                    $table.find('tbody').append(row);
                });
            });
        }

        function getSelectedPostTypes() {
            var selected = $('#fbps-post-types').val();
            return selected && selected.length ? selected : ['post', 'page'];
        }

        function searchBlockBatch(block, postTypes, offset, accumulated) {
            offset = offset || 0;
            accumulated = accumulated || [];

            $.post(fbpsData.ajaxUrl, {
                action:      'fbps_search_block',
                block_name:  block,
                post_types:  postTypes,
                batch_offset: offset,
                _ajax_nonce: currentNonce
            }, function(response){
                if (!response || typeof response !== 'object') {
                    displayError(fbpsData.i18n.invalidResponseFormat);
                    return;
                }

                if (!response.success) {
                    var errorMsg = response.data ? escapeHtml(String(response.data)) : fbpsData.i18n.unknownError;
                    displayError(errorMsg);
                    return;
                }

                var data = response.data;
                if (!data || !Array.isArray(data.results)) {
                    displayError(fbpsData.i18n.invalidResponseFormat);
                    return;
                }

                accumulated = accumulated.concat(data.results);
                allResults = accumulated;

                // Store total_posts from first batch
                if (data.total_posts) {
                    window.fbps_total_posts = data.total_posts;
                }

                // Update progress
                updateProgress(accumulated.length);

                // Display current results
                displayResults(accumulated, !data.has_more);

                // Continue batching if more results
                if (data.has_more && currentSearch === block) {
                    searchBlockBatch(block, postTypes, data.next_offset, accumulated);
                } else {
                    $('#fbps-search-button').prop('disabled', false).attr('aria-busy', 'false');
                    $('#fbps-cancel-button').hide();
                    $('#fbps-progress').hide();
                    if (accumulated.length > 0) {
                        $('#fbps-export-button').show();
                    }
                }
            }).fail(function() {
                displayError(fbpsData.i18n.networkError);
            });
        }

        function updateProgress(count) {
            var html = '<div class="fbps-progress-spinner" role="status" aria-label="' + escapeHtml(fbpsData.i18n.searching) + '"></div>';
            html += '<span class="fbps-progress-message">';
            html += fbpsData.i18n.searchingProgress.replace('%d', count);
            html += '</span>';
            $('#fbps-progress').html(html);
        }

        function displayResults(data, isComplete) {
            var html = '';
            if (data.length) {
                html += '<p class="fbps-results-count" aria-live="polite">';
                html += data.length + ' ' + (data.length === 1 ? fbpsData.i18n.result : fbpsData.i18n.results);
                if (!isComplete) {
                    html += ' ' + fbpsData.i18n.foundSoFar;
                } else {
                    html += ' ' + fbpsData.i18n.found;
                }
                html += '</p>';
                html += '<table class="wp-list-table widefat fixed striped fbps-results-table">';
                html += '<thead><tr>';
                html += '<th class="sortable" data-column="title"><a href="#"><span>' + escapeHtml(fbpsData.i18n.title) + '</span><span class="sorting-indicator"></span></a></th>';
                html += '<th class="sortable" data-column="type"><a href="#"><span>' + escapeHtml(fbpsData.i18n.type) + '</span><span class="sorting-indicator"></span></a></th>';
                html += '<th class="sortable sorted desc" data-column="date"><a href="#"><span>' + escapeHtml(fbpsData.i18n.date) + '</span><span class="sorting-indicator"></span></a></th>';
                html += '<th>' + escapeHtml(fbpsData.i18n.actions) + '</th>';
                html += '</tr></thead><tbody>';
                data.forEach(function(item){
                    if (item && item.edit_link && item.view_link && item.title && item.type && item.date) {
                        html += '<tr data-title="'+ escapeHtml(item.title) +'" data-type="'+ escapeHtml(item.type) +'" data-date="'+ escapeHtml(item.date) +'">';
                        html += '<td><strong>'+ escapeHtml(item.title) +'</strong></td>';
                        html += '<td>'+ escapeHtml(item.type) +'</td>';
                        html += '<td>'+ formatDate(item.date) +'</td>';
                        html += '<td>';
                        html += '<a href="'+ escapeHtml(item.view_link) +'" class="button button-small" aria-label="' + escapeHtml(fbpsData.i18n.view) + ' '+ escapeHtml(item.type) +': '+ escapeHtml(item.title) +'" target="_blank">' + escapeHtml(fbpsData.i18n.view) + '</a> ';
                        html += '<a href="'+ escapeHtml(item.edit_link) +'" class="button button-small" aria-label="' + escapeHtml(fbpsData.i18n.edit) + ' '+ escapeHtml(item.type) +': '+ escapeHtml(item.title) +'">' + escapeHtml(fbpsData.i18n.edit) + '</a>';
                        html += '</td>';
                        html += '</tr>';
                    }
                });
                html += '</tbody></table>';
            } else if (isComplete) {
                html = '<p>' + escapeHtml(fbpsData.i18n.noBlockResults) + '</p>';
            }
            $('#fbps-search-results').html(html).attr('tabindex', '-1').focus();
            initTableSort('#fbps-search-results');
        }

        function displayError(message) {
            var html = '<div role="alert" class="notice notice-error"><p><strong>' + escapeHtml(fbpsData.i18n.error) + '</strong> '+ message +'</p></div>';
            $('#fbps-search-results').html(html);
            $('#fbps-search-button').prop('disabled', false).attr('aria-busy', 'false');
            $('#fbps-cancel-button').hide();
            $('#fbps-progress').hide();
        }

        function searchBlock(block) {
            currentSearch = block;
            allResults = [];
            $('#fbps-export-button').hide();
            $('#fbps-search-button').prop('disabled', true).attr('aria-busy', 'true');
            $('#fbps-cancel-button').show();
            // Clear all result containers
            $('#fbps-search-results').empty();
            $('#fbps-pattern-search-results').empty();
            $('#fbps-shortcode-search-results').empty();
            $('#fbps-progress').show();
            updateProgress(0);

            ensureFreshNonce(function() {
                searchBlockBatch(block, getSelectedPostTypes(), 0, []);
            });
        }

        $('#fbps-search-button').on('click', function(){
            searchBlock( $('#fbps-block-name').val() );
        });

        // Cancel block search
        $('#fbps-cancel-button').on('click', function(){
            currentSearch = null;
            $('#fbps-search-button').prop('disabled', false).attr('aria-busy', 'false');
            $('#fbps-cancel-button').hide();
            $('#fbps-progress').hide();
            var html = '<div role="alert" class="notice notice-warning"><p>' + escapeHtml(fbpsData.i18n.searchCancelled) + '</p></div>';
            $('#fbps-search-results').html(html);
        });

        // Unified CSV Export - detects which search type has results
        $('#fbps-export-button').on('click', function(){
            var csv = 'Title,Type,Date,View Link\n';
            var filename = '';
            var results = [];

            // Determine which search has results and use appropriate data
            if (allResults.length > 0) {
                results = allResults;
                filename = 'block-usage-' + $('#fbps-block-name').val().replace(/[^a-z0-9]/gi, '-') + '.csv';
            } else if (allPatternResults.length > 0) {
                results = allPatternResults;
                var patternName = $('#fbps-pattern-dropdown option:selected').text().replace(/[^a-z0-9]/gi, '-');
                filename = 'pattern-usage-' + patternName + '.csv';
            } else if (allShortcodeResults.length > 0) {
                results = allShortcodeResults;
                var shortcodeName = $('#fbps-shortcode-dropdown').val().replace(/[^a-z0-9]/gi, '-');
                filename = 'shortcode-usage-' + shortcodeName + '.csv';
            }

            // Build CSV from results
            results.forEach(function(item){
                csv += '"' + sanitizeCsvValue(item.title) + '",';
                csv += '"' + sanitizeCsvValue(item.type) + '",';
                csv += '"' + sanitizeCsvValue(item.date) + '",';
                csv += '"' + sanitizeCsvValue(item.view_link) + '"\n';
            });

            // Download CSV
            var blob = new Blob([csv], { type: 'text/csv' });
            var url = window.URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = filename;
            a.click();
            window.URL.revokeObjectURL(url);
        });

        // Add Enter key support
        $('#fbps-block-name').on('keypress', function(e){
            if (e.which === 13) { // Enter key
                e.preventDefault();
                clearTimeout(timer);
                searchBlock( $(this).val() );
            }
        });

        $('#fbps-block-name').on('keyup', function(e){
            if (e.which === 13) return; // Skip Enter key for debounced search
            clearTimeout(timer);
            timer = setTimeout(function(){
                searchBlock( $('#fbps-block-name').val() );
            }, 500);
        });

        // ========== PATTERN SEARCH FUNCTIONS ==========

        function getSelectedPatternPostTypes() {
            var selected = $('#fbps-pattern-post-types').val();
            return selected && selected.length ? selected : ['post', 'page'];
        }

        function searchPatternBatch(patternId, postTypes, offset, accumulated) {
            offset = offset || 0;
            accumulated = accumulated || [];

            $.post(fbpsData.ajaxUrl, {
                action:      'fbps_search_pattern',
                pattern_id:  patternId,
                post_types:  postTypes,
                batch_offset: offset,
                _ajax_nonce: currentNonce
            }, function(response){
                if (!response || typeof response !== 'object') {
                    displayPatternError(fbpsData.i18n.invalidResponseFormat);
                    return;
                }

                if (!response.success) {
                    var errorMsg = response.data ? escapeHtml(String(response.data)) : fbpsData.i18n.unknownError;
                    displayPatternError(errorMsg);
                    return;
                }

                var data = response.data;
                if (!data || !Array.isArray(data.results)) {
                    displayPatternError(fbpsData.i18n.invalidResponseFormat);
                    return;
                }

                accumulated = accumulated.concat(data.results);
                allPatternResults = accumulated;

                // Store total_posts from first batch
                if (data.total_posts) {
                    window.fbps_pattern_total_posts = data.total_posts;
                }

                // Update progress
                updatePatternProgress(accumulated.length);

                // Display current results
                displayPatternResults(accumulated, !data.has_more);

                // Continue batching if more results
                if (data.has_more && currentPatternSearch === patternId) {
                    searchPatternBatch(patternId, postTypes, data.next_offset, accumulated);
                } else {
                    $('#fbps-pattern-search-button').prop('disabled', false).attr('aria-busy', 'false');
                    $('#fbps-pattern-cancel-button').hide();
                    $('#fbps-pattern-progress').hide();
                    if (accumulated.length > 0) {
                        $('#fbps-pattern-export-button').show();
                    }
                }
            }).fail(function() {
                displayPatternError(fbpsData.i18n.networkError);
            });
        }

        function updatePatternProgress(count) {
            var html = '<div class="fbps-progress-spinner" role="status" aria-label="' + escapeHtml(fbpsData.i18n.searching) + '"></div>';
            html += '<span class="fbps-progress-message">';
            html += fbpsData.i18n.searchingProgress.replace('%d', count);
            html += '</span>';
            $('#fbps-pattern-progress').html(html);
        }

        function displayPatternResults(data, isComplete) {
            var html = '';
            if (data.length) {
                html += '<p class="fbps-results-count" aria-live="polite">';
                html += data.length + ' ' + (data.length === 1 ? fbpsData.i18n.result : fbpsData.i18n.results);
                if (!isComplete) {
                    html += ' ' + fbpsData.i18n.foundSoFar;
                } else {
                    html += ' ' + fbpsData.i18n.found;
                }
                html += '</p>';
                html += '<table class="wp-list-table widefat fixed striped fbps-results-table">';
                html += '<thead><tr>';
                html += '<th class="sortable" data-column="title"><a href="#"><span>' + escapeHtml(fbpsData.i18n.title) + '</span><span class="sorting-indicator"></span></a></th>';
                html += '<th class="sortable" data-column="type"><a href="#"><span>' + escapeHtml(fbpsData.i18n.type) + '</span><span class="sorting-indicator"></span></a></th>';
                html += '<th class="sortable sorted desc" data-column="date"><a href="#"><span>' + escapeHtml(fbpsData.i18n.date) + '</span><span class="sorting-indicator"></span></a></th>';
                html += '<th>' + escapeHtml(fbpsData.i18n.actions) + '</th>';
                html += '</tr></thead><tbody>';
                data.forEach(function(item){
                    if (item && item.edit_link && item.view_link && item.title && item.type && item.date) {
                        html += '<tr data-title="'+ escapeHtml(item.title) +'" data-type="'+ escapeHtml(item.type) +'" data-date="'+ escapeHtml(item.date) +'">';
                        html += '<td><strong>'+ escapeHtml(item.title) +'</strong></td>';
                        html += '<td>'+ escapeHtml(item.type) +'</td>';
                        html += '<td>'+ formatDate(item.date) +'</td>';
                        html += '<td>';
                        html += '<a href="'+ escapeHtml(item.view_link) +'" class="button button-small" aria-label="' + escapeHtml(fbpsData.i18n.view) + ' '+ escapeHtml(item.type) +': '+ escapeHtml(item.title) +'" target="_blank">' + escapeHtml(fbpsData.i18n.view) + '</a> ';
                        html += '<a href="'+ escapeHtml(item.edit_link) +'" class="button button-small" aria-label="' + escapeHtml(fbpsData.i18n.edit) + ' '+ escapeHtml(item.type) +': '+ escapeHtml(item.title) +'">' + escapeHtml(fbpsData.i18n.edit) + '</a>';
                        html += '</td>';
                        html += '</tr>';
                    }
                });
                html += '</tbody></table>';
            } else if (isComplete) {
                html = '<p>' + escapeHtml(fbpsData.i18n.noPatternResults) + '</p>';
            }
            $('#fbps-pattern-search-results').html(html).attr('tabindex', '-1').focus();
            initTableSort('#fbps-pattern-search-results');
        }

        function displayPatternError(message) {
            var html = '<div role="alert" class="notice notice-error"><p><strong>' + escapeHtml(fbpsData.i18n.error) + '</strong> '+ message +'</p></div>';
            $('#fbps-pattern-search-results').html(html);
            $('#fbps-pattern-search-button').prop('disabled', false).attr('aria-busy', 'false');
            $('#fbps-pattern-cancel-button').hide();
            $('#fbps-pattern-progress').hide();
        }

        function searchPattern(patternId) {
            if (!patternId) {
                displayPatternError(fbpsData.i18n.selectPattern);
                return;
            }

            currentPatternSearch = patternId;
            allPatternResults = [];
            $('#fbps-export-button').hide();
            $('#fbps-pattern-search-button').prop('disabled', true).attr('aria-busy', 'true');
            $('#fbps-pattern-cancel-button').show();
            // Clear all result containers
            $('#fbps-search-results').empty();
            $('#fbps-pattern-search-results').empty();
            $('#fbps-shortcode-search-results').empty();
            $('#fbps-pattern-progress').show();
            updatePatternProgress(0);

            ensureFreshNonce(function() {
                searchPatternBatch(patternId, getSelectedPatternPostTypes(), 0, []);
            });
        }

        // Pattern search button handler
        $('#fbps-pattern-search-button').on('click', function(){
            searchPattern( $('#fbps-pattern-dropdown').val() );
        });

        // Cancel pattern search
        $('#fbps-pattern-cancel-button').on('click', function(){
            currentPatternSearch = null;
            $('#fbps-pattern-search-button').prop('disabled', false).attr('aria-busy', 'false');
            $('#fbps-pattern-cancel-button').hide();
            $('#fbps-pattern-progress').hide();
            var html = '<div role="alert" class="notice notice-warning"><p>' + escapeHtml(fbpsData.i18n.searchCancelled) + '</p></div>';
            $('#fbps-pattern-search-results').html(html);
        });

        // ========== SHORTCODE SEARCH FUNCTIONS ==========

        function getSelectedShortcodePostTypes() {
            var selected = $('#fbps-shortcode-post-types').val();
            return selected && selected.length ? selected : ['post', 'page'];
        }

        function searchShortcodeBatch(shortcodeName, postTypes, offset, accumulated) {
            offset = offset || 0;
            accumulated = accumulated || [];

            $.post(fbpsData.ajaxUrl, {
                action:         'fbps_search_shortcode',
                shortcode_name: shortcodeName,
                post_types:     postTypes,
                batch_offset:   offset,
                _ajax_nonce:    currentNonce
            }, function(response){
                if (!response || typeof response !== 'object') {
                    displayShortcodeError(fbpsData.i18n.invalidResponseFormat);
                    return;
                }

                if (!response.success) {
                    var errorMsg = response.data ? escapeHtml(String(response.data)) : fbpsData.i18n.unknownError;
                    displayShortcodeError(errorMsg);
                    return;
                }

                var data = response.data;
                if (!data || !Array.isArray(data.results)) {
                    displayShortcodeError(fbpsData.i18n.invalidResponseFormat);
                    return;
                }

                accumulated = accumulated.concat(data.results);
                allShortcodeResults = accumulated;

                // Store total_posts from first batch
                if (data.total_posts) {
                    window.fbps_shortcode_total_posts = data.total_posts;
                }

                // Update progress
                updateShortcodeProgress(accumulated.length);

                // Display current results
                displayShortcodeResults(accumulated, !data.has_more);

                // Continue batching if more results
                if (data.has_more && currentShortcodeSearch === shortcodeName) {
                    searchShortcodeBatch(shortcodeName, postTypes, data.next_offset, accumulated);
                } else {
                    $('#fbps-shortcode-search-button').prop('disabled', false).attr('aria-busy', 'false');
                    $('#fbps-shortcode-cancel-button').hide();
                    $('#fbps-shortcode-progress').hide();
                    if (accumulated.length > 0) {
                        $('#fbps-shortcode-export-button').show();
                    }
                }
            }).fail(function() {
                displayShortcodeError(fbpsData.i18n.networkError);
            });
        }

        function updateShortcodeProgress(count) {
            var html = '<div class="fbps-progress-spinner" role="status" aria-label="' + escapeHtml(fbpsData.i18n.searching) + '"></div>';
            html += '<span class="fbps-progress-message">';
            html += fbpsData.i18n.searchingProgress.replace('%d', count);
            html += '</span>';
            $('#fbps-shortcode-progress').html(html);
        }

        function displayShortcodeResults(data, isComplete) {
            var html = '';
            if (data.length) {
                html += '<p class="fbps-results-count" aria-live="polite">';
                html += data.length + ' ' + (data.length === 1 ? fbpsData.i18n.result : fbpsData.i18n.results);
                if (!isComplete) {
                    html += ' ' + fbpsData.i18n.foundSoFar;
                } else {
                    html += ' ' + fbpsData.i18n.found;
                }
                html += '</p>';
                html += '<table class="wp-list-table widefat fixed striped fbps-results-table">';
                html += '<thead><tr>';
                html += '<th class="sortable" data-column="title"><a href="#"><span>' + escapeHtml(fbpsData.i18n.title) + '</span><span class="sorting-indicator"></span></a></th>';
                html += '<th class="sortable" data-column="type"><a href="#"><span>' + escapeHtml(fbpsData.i18n.type) + '</span><span class="sorting-indicator"></span></a></th>';
                html += '<th class="sortable sorted desc" data-column="date"><a href="#"><span>' + escapeHtml(fbpsData.i18n.date) + '</span><span class="sorting-indicator"></span></a></th>';
                html += '<th>' + escapeHtml(fbpsData.i18n.actions) + '</th>';
                html += '</tr></thead><tbody>';
                data.forEach(function(item){
                    if (item && item.edit_link && item.view_link && item.title && item.type && item.date) {
                        html += '<tr data-title="'+ escapeHtml(item.title) +'" data-type="'+ escapeHtml(item.type) +'" data-date="'+ escapeHtml(item.date) +'">';
                        html += '<td><strong>'+ escapeHtml(item.title) +'</strong></td>';
                        html += '<td>'+ escapeHtml(item.type) +'</td>';
                        html += '<td>'+ formatDate(item.date) +'</td>';
                        html += '<td>';
                        html += '<a href="'+ escapeHtml(item.view_link) +'" class="button button-small" aria-label="' + escapeHtml(fbpsData.i18n.view) + ' '+ escapeHtml(item.type) +': '+ escapeHtml(item.title) +'" target="_blank">' + escapeHtml(fbpsData.i18n.view) + '</a> ';
                        html += '<a href="'+ escapeHtml(item.edit_link) +'" class="button button-small" aria-label="' + escapeHtml(fbpsData.i18n.edit) + ' '+ escapeHtml(item.type) +': '+ escapeHtml(item.title) +'">' + escapeHtml(fbpsData.i18n.edit) + '</a>';
                        html += '</td>';
                        html += '</tr>';
                    }
                });
                html += '</tbody></table>';
            } else if (isComplete) {
                html = '<p>' + escapeHtml(fbpsData.i18n.noShortcodeResults) + '</p>';
            }
            $('#fbps-shortcode-search-results').html(html).attr('tabindex', '-1').focus();
            initTableSort('#fbps-shortcode-search-results');
        }

        function displayShortcodeError(message) {
            var html = '<div role="alert" class="notice notice-error"><p><strong>' + escapeHtml(fbpsData.i18n.error) + '</strong> '+ message +'</p></div>';
            $('#fbps-shortcode-search-results').html(html);
            $('#fbps-shortcode-search-button').prop('disabled', false).attr('aria-busy', 'false');
            $('#fbps-shortcode-cancel-button').hide();
            $('#fbps-shortcode-progress').hide();
        }

        function searchShortcode(shortcodeName) {
            if (!shortcodeName) {
                displayShortcodeError(fbpsData.i18n.selectShortcode);
                return;
            }

            currentShortcodeSearch = shortcodeName;
            allShortcodeResults = [];
            $('#fbps-export-button').hide();
            $('#fbps-shortcode-search-button').prop('disabled', true).attr('aria-busy', 'true');
            $('#fbps-shortcode-cancel-button').show();
            // Clear all result containers
            $('#fbps-search-results').empty();
            $('#fbps-pattern-search-results').empty();
            $('#fbps-shortcode-search-results').empty();
            $('#fbps-shortcode-progress').show();
            updateShortcodeProgress(0);

            ensureFreshNonce(function() {
                searchShortcodeBatch(shortcodeName, getSelectedShortcodePostTypes(), 0, []);
            });
        }

        // Shortcode search button handler
        $('#fbps-shortcode-search-button').on('click', function(){
            searchShortcode( $('#fbps-shortcode-dropdown').val() );
        });

        // Cancel shortcode search
        $('#fbps-shortcode-cancel-button').on('click', function(){
            currentShortcodeSearch = null;
            $('#fbps-shortcode-search-button').prop('disabled', false).attr('aria-busy', 'false');
            $('#fbps-shortcode-cancel-button').hide();
            $('#fbps-shortcode-progress').hide();
            var html = '<div role="alert" class="notice notice-warning"><p>' + escapeHtml(fbpsData.i18n.searchCancelled) + '</p></div>';
            $('#fbps-shortcode-search-results').html(html);
        });
    });
})(jQuery);
