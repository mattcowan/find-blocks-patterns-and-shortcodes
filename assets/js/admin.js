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
        var blockSearchComplete = false;
        var patternSearchComplete = false;
        var shortcodeSearchComplete = false;

        // Column definitions: key, i18n label key, sortable
        var columnDefs = {
            title:     { label: 'title',     sortable: true },
            type:      { label: 'type',      sortable: true },
            date:      { label: 'date',      sortable: true },
            className: { label: 'cssClass',  sortable: true },
            anchor:    { label: 'htmlAnchor', sortable: true }
        };

        function getVisibleColumns() {
            var cols = [];
            $('.fbps-col-toggle:checked').each(function() {
                cols.push($(this).val());
            });
            return cols;
        }

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

        // Toggle the landmark role on a results container.
        //
        // All three containers exist from first paint, so leaving role="region"
        // in the markup puts two permanently empty landmarks in the screen
        // reader's landmark list. The role is only meaningful once the
        // container actually holds results, so it is applied here instead.
        function syncResultsRegion($container) {
            if ($.trim($container.text()).length) {
                $container.attr({
                    'role': 'region',
                    'aria-label': $container.data('region-label') || ''
                });
            } else {
                // aria-label is prohibited on a div with no role, so both come off.
                $container.removeAttr('role').removeAttr('aria-label');
            }
            return $container;
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
            $(containerSelector + ' .fbps-results-table th.sortable .fbps-sort-button').off('click').on('click', function(e){
                e.preventDefault();
                var $th = $(this).closest('th');
                var column = $th.data('column');
                var $table = $th.closest('table');
                var isAsc = $th.hasClass('sorted') && $th.hasClass('asc');

                // Remove sorted state from all headers
                $table.find('th').removeClass('sorted asc desc');
                $table.find('th.sortable').attr('aria-sort', 'none');

                // Add sorted state to current header
                var newDir = isAsc ? 'desc' : 'asc';
                $th.addClass('sorted').addClass(newDir);
                $th.attr('aria-sort', newDir === 'asc' ? 'ascending' : 'descending');

                // Sort the rows
                var dataKey = column.toLowerCase();
                var $rows = $table.find('tbody tr').get();
                $rows.sort(function(a, b){
                    var aVal = $(a).data(dataKey);
                    var bVal = $(b).data(dataKey);

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

        function searchBlockBatch(block, postTypes, offset, accumulated, className, anchorName) {
            offset = offset || 0;
            accumulated = accumulated || [];

            var postData = {
                action:      'fbps_search_block',
                block_name:  block,
                post_types:  postTypes,
                batch_offset: offset,
                _ajax_nonce: currentNonce
            };
            if (className) {
                postData.class_name = className;
            }
            if (anchorName) {
                postData.anchor_name = anchorName;
            }

            $.post(fbpsData.ajaxUrl, postData, function(response){
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
                    searchBlockBatch(block, postTypes, data.next_offset, accumulated, className, anchorName);
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

        function buildResultsTableHtml(data, isComplete, noResultsMsg) {
            var html = '';
            var cols = getVisibleColumns();
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
                cols.forEach(function(col) {
                    var def = columnDefs[col];
                    if (def) {
                        var isDefaultSort = (col === 'date');
                        var sortedClass = isDefaultSort ? ' sorted desc' : '';
                        // aria-sort carries the sort state for assistive tech; the CSS
                        // class and the arrow glyph convey it to sighted users only.
                        var ariaSort = isDefaultSort ? 'descending' : 'none';
                        html += '<th scope="col" aria-sort="' + ariaSort + '" class="sortable' + sortedClass + '" data-column="' + col + '">' +
                                '<button type="button" class="fbps-sort-button">' +
                                '<span>' + escapeHtml(fbpsData.i18n[def.label]) + '</span>' +
                                '<span class="sorting-indicator" aria-hidden="true"></span>' +
                                '</button></th>';
                    }
                });
                html += '<th scope="col">' + escapeHtml(fbpsData.i18n.actions) + '</th>';
                html += '</tr></thead><tbody>';
                data.forEach(function(item){
                    // Render every item the server returned. Guarding on a truthy
                    // title used to drop untitled posts silently, so the count and
                    // the CSV export disagreed with the table - and an untitled
                    // draft is exactly what a content audit needs to find. Each
                    // action link is emitted only when its URL exists, so a post
                    // the current user cannot edit still gets a row.
                    if (item) {
                        var displayTitle = item.title || fbpsData.i18n.noTitle;
                        html += '<tr';
                        cols.forEach(function(col) {
                            var sortVal = (col === 'title') ? displayTitle : (item[col] || '');
                            html += ' data-' + col.toLowerCase() + '="' + escapeHtml(sortVal) + '"';
                        });
                        html += '>';
                        cols.forEach(function(col) {
                            var val = item[col] || '';
                            if (col === 'title') {
                                html += '<td><strong>' + escapeHtml(displayTitle) + '</strong></td>';
                            } else if (col === 'date') {
                                html += '<td>' + escapeHtml(formatDate(val)) + '</td>';
                            } else {
                                html += '<td>' + escapeHtml(val) + '</td>';
                            }
                        });
                        html += '<td>';
                        if (item.view_link) {
                            html += '<a href="'+ escapeHtml(item.view_link) +'" class="button button-small" aria-label="' + escapeHtml(fbpsData.i18n.view) + ' '+ escapeHtml(item.type || '') +': '+ escapeHtml(displayTitle) +'" target="_blank" rel="noopener noreferrer">' + escapeHtml(fbpsData.i18n.view) + '</a> ';
                        }
                        if (item.edit_link) {
                            html += '<a href="'+ escapeHtml(item.edit_link) +'" class="button button-small" aria-label="' + escapeHtml(fbpsData.i18n.edit) + ' '+ escapeHtml(item.type || '') +': '+ escapeHtml(displayTitle) +'" target="_blank" rel="noopener noreferrer">' + escapeHtml(fbpsData.i18n.edit) + '</a>';
                        }
                        html += '</td>';
                        html += '</tr>';
                    }
                });
                html += '</tbody></table>';
            } else if (isComplete) {
                html = '<p>' + escapeHtml(noResultsMsg) + '</p>';
            }
            return html;
        }

        function displayResults(data, isComplete, moveFocus) {
            blockSearchComplete = isComplete;
            var className = $('#fbps-class-name').val();
            var anchorName = $('#fbps-anchor-name').val();
            var noResultsMsg = (className || anchorName) ? fbpsData.i18n.noAttributeResults : fbpsData.i18n.noBlockResults;
            var html = buildResultsTableHtml(data, isComplete, noResultsMsg);
            var $c = syncResultsRegion($('#fbps-search-results').html(html).attr('tabindex', '-1'));
            // Only pull focus when a search produced this render. Re-rendering
            // from a column toggle must leave focus on the checkbox the user
            // just operated, or they are ejected from the fieldset each time.
            if (moveFocus !== false) {
                $c.focus();
            }
            initTableSort('#fbps-search-results');
        }

        function displayError(message) {
            var html = '<div role="alert" class="notice notice-error"><p><strong>' + escapeHtml(fbpsData.i18n.error) + '</strong> '+ message +'</p></div>';

            // Keep what the completed batches already found. Replacing the whole
            // container used to discard every result gathered before the failure,
            // which on a large site meant losing hundreds of rows to one blip -
            // and the retry is itself rate limited.
            if (allResults.length) {
                html += '<p class="fbps-partial-notice">' + escapeHtml(fbpsData.i18n.partialResults) + '</p>';
                html += buildResultsTableHtml(allResults, false, fbpsData.i18n.noBlockResults);
            }

            syncResultsRegion($('#fbps-search-results').html(html));
            initTableSort('#fbps-search-results');
            $('#fbps-search-button').prop('disabled', false).attr('aria-busy', 'false');
            $('#fbps-cancel-button').hide();
            $('#fbps-progress').hide();
            if (allResults.length) {
                $('#fbps-export-button').show();
            }
        }

        function searchBlock(block) {
            var className = $('#fbps-class-name').val().trim();
            var anchorName = $('#fbps-anchor-name').val().trim();

            // Require at least one search criterion
            if (!block && !className && !anchorName) {
                displayError(fbpsData.i18n.classOrAnchorRequired);
                return;
            }

            currentSearch = block || className || anchorName;
            allResults = [];
            $('#fbps-export-button').hide();
            $('#fbps-search-button').prop('disabled', true).attr('aria-busy', 'true');
            $('#fbps-cancel-button').show();
            // Clear all result containers
            syncResultsRegion($('#fbps-search-results').empty());
            syncResultsRegion($('#fbps-pattern-search-results').empty());
            syncResultsRegion($('#fbps-shortcode-search-results').empty());
            $('#fbps-progress').show();
            updateProgress(0);

            ensureFreshNonce(function() {
                searchBlockBatch(block, getSelectedPostTypes(), 0, [], className, anchorName);
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
            syncResultsRegion($('#fbps-search-results').html(html));
        });

        // Unified CSV Export - detects which search type has results
        $('#fbps-export-button').on('click', function(){
            var cols = getVisibleColumns();
            var columnLabels = {
                title: 'Title',
                type: 'Type',
                date: 'Date',
                className: 'CSS Class',
                anchor: 'HTML Anchor'
            };

            // Build CSV header from visible columns + View Link
            var headerParts = [];
            cols.forEach(function(col) {
                headerParts.push(columnLabels[col] || col);
            });
            headerParts.push('View Link');
            var csv = headerParts.join(',') + '\n';

            var filename = '';
            var results = [];

            // Determine which search has results and use appropriate data
            if (allResults.length > 0) {
                results = allResults;
                var blockVal = $('#fbps-block-name').val();
                var classVal = $('#fbps-class-name').val();
                var anchorVal = $('#fbps-anchor-name').val();
                var nameParts = [];
                if (blockVal) nameParts.push(blockVal);
                if (classVal) nameParts.push('class-' + classVal);
                if (anchorVal) nameParts.push('anchor-' + anchorVal);
                filename = 'block-usage-' + (nameParts.join('-') || 'search').replace(/[^a-z0-9-]/gi, '-') + '.csv';
            } else if (allPatternResults.length > 0) {
                results = allPatternResults;
                var patternName = $('#fbps-pattern-dropdown option:selected').text().replace(/[^a-z0-9]/gi, '-');
                filename = 'pattern-usage-' + patternName + '.csv';
            } else if (allShortcodeResults.length > 0) {
                results = allShortcodeResults;
                var shortcodeName = $('#fbps-shortcode-dropdown').val().replace(/[^a-z0-9]/gi, '-');
                filename = 'shortcode-usage-' + shortcodeName + '.csv';
            }

            // Build CSV from results using visible columns
            results.forEach(function(item){
                var rowParts = [];
                cols.forEach(function(col) {
                    rowParts.push('"' + sanitizeCsvValue(item[col] || '') + '"');
                });
                rowParts.push('"' + sanitizeCsvValue(item.view_link) + '"');
                csv += rowParts.join(',') + '\n';
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

        // Add Enter key support for all block search fields
        $('#fbps-block-name, #fbps-class-name, #fbps-anchor-name').on('keypress', function(e){
            if (e.which === 13) { // Enter key
                e.preventDefault();
                clearTimeout(timer);
                searchBlock( $('#fbps-block-name').val() );
            }
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
                        $('#fbps-export-button').show();
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

        function displayPatternResults(data, isComplete, moveFocus) {
            patternSearchComplete = isComplete;
            var html = buildResultsTableHtml(data, isComplete, fbpsData.i18n.noPatternResults);
            var $c = syncResultsRegion($('#fbps-pattern-search-results').html(html).attr('tabindex', '-1'));
            // Only pull focus when a search produced this render. Re-rendering
            // from a column toggle must leave focus on the checkbox the user
            // just operated, or they are ejected from the fieldset each time.
            if (moveFocus !== false) {
                $c.focus();
            }
            initTableSort('#fbps-pattern-search-results');
        }

        function displayPatternError(message) {
            var html = '<div role="alert" class="notice notice-error"><p><strong>' + escapeHtml(fbpsData.i18n.error) + '</strong> '+ message +'</p></div>';

            // Keep what the completed batches already found. Replacing the whole
            // container used to discard every result gathered before the failure,
            // which on a large site meant losing hundreds of rows to one blip -
            // and the retry is itself rate limited.
            if (allPatternResults.length) {
                html += '<p class="fbps-partial-notice">' + escapeHtml(fbpsData.i18n.partialResults) + '</p>';
                html += buildResultsTableHtml(allPatternResults, false, fbpsData.i18n.noPatternResults);
            }

            syncResultsRegion($('#fbps-pattern-search-results').html(html));
            initTableSort('#fbps-pattern-search-results');
            $('#fbps-pattern-search-button').prop('disabled', false).attr('aria-busy', 'false');
            $('#fbps-pattern-cancel-button').hide();
            $('#fbps-pattern-progress').hide();
            if (allPatternResults.length) {
                $('#fbps-export-button').show();
            }
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
            syncResultsRegion($('#fbps-search-results').empty());
            syncResultsRegion($('#fbps-pattern-search-results').empty());
            syncResultsRegion($('#fbps-shortcode-search-results').empty());
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
            syncResultsRegion($('#fbps-pattern-search-results').html(html));
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
                        $('#fbps-export-button').show();
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

        function displayShortcodeResults(data, isComplete, moveFocus) {
            shortcodeSearchComplete = isComplete;
            var html = buildResultsTableHtml(data, isComplete, fbpsData.i18n.noShortcodeResults);
            var $c = syncResultsRegion($('#fbps-shortcode-search-results').html(html).attr('tabindex', '-1'));
            // Only pull focus when a search produced this render. Re-rendering
            // from a column toggle must leave focus on the checkbox the user
            // just operated, or they are ejected from the fieldset each time.
            if (moveFocus !== false) {
                $c.focus();
            }
            initTableSort('#fbps-shortcode-search-results');
        }

        function displayShortcodeError(message) {
            var html = '<div role="alert" class="notice notice-error"><p><strong>' + escapeHtml(fbpsData.i18n.error) + '</strong> '+ message +'</p></div>';

            // Keep what the completed batches already found. Replacing the whole
            // container used to discard every result gathered before the failure,
            // which on a large site meant losing hundreds of rows to one blip -
            // and the retry is itself rate limited.
            if (allShortcodeResults.length) {
                html += '<p class="fbps-partial-notice">' + escapeHtml(fbpsData.i18n.partialResults) + '</p>';
                html += buildResultsTableHtml(allShortcodeResults, false, fbpsData.i18n.noShortcodeResults);
            }

            syncResultsRegion($('#fbps-shortcode-search-results').html(html));
            initTableSort('#fbps-shortcode-search-results');
            $('#fbps-shortcode-search-button').prop('disabled', false).attr('aria-busy', 'false');
            $('#fbps-shortcode-cancel-button').hide();
            $('#fbps-shortcode-progress').hide();
            if (allShortcodeResults.length) {
                $('#fbps-export-button').show();
            }
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
            syncResultsRegion($('#fbps-search-results').empty());
            syncResultsRegion($('#fbps-pattern-search-results').empty());
            syncResultsRegion($('#fbps-shortcode-search-results').empty());
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
            syncResultsRegion($('#fbps-shortcode-search-results').html(html));
        });

        // Re-render results when column toggles change
        $('.fbps-col-toggle').on('change', function() {
            if (allResults.length > 0) {
                displayResults(allResults, blockSearchComplete, false);
            }
            if (allPatternResults.length > 0) {
                displayPatternResults(allPatternResults, patternSearchComplete, false);
            }
            if (allShortcodeResults.length > 0) {
                displayShortcodeResults(allShortcodeResults, shortcodeSearchComplete, false);
            }
        });
    });
})(jQuery);
