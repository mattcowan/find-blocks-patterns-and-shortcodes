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
        // One token per surface. Each search takes the next value and every
        // batch callback compares against it, so a response from a cancelled
        // or superseded search is dropped. Tokens replace the old string
        // guards, which compared "block || class || anchor" as one string
        // against block alone and so never matched a class-only search.
        var searchToken = { block: 0, pattern: 0, shortcode: 0 };

        // What the last batch of each surface's search said about coverage.
        // Set when has_more is false; read by the table builder so the notice
        // survives re-renders (column toggles, sorts).
        var searchMeta = { block: null, pattern: null, shortcode: null };
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

        /**
         * Search-type tabs (WAI-ARIA tabs pattern, automatic activation).
         *
         * Only one form shows at a time. Selecting a tab only swaps which
         * panel is visible: it does not start a search, cancel one, or touch
         * the shared results area. Only the selected tab is in the Tab order
         * (roving tabindex); Left/Right wrap around, Home/End jump to the
         * ends.
         */
        var $tabs = $('.fbps-tabs [role="tab"]');

        function selectTab($tab, moveFocus) {
            $tabs.each(function() {
                var $t = $(this);
                var selected = $t.is($tab);
                $t.attr('aria-selected', selected ? 'true' : 'false')
                  .attr('tabindex', selected ? '0' : '-1');
                $('#' + $t.attr('aria-controls')).prop('hidden', !selected);
            });
            if (moveFocus) {
                $tab.trigger('focus');
            }
        }

        $tabs.on('click', function() {
            selectTab($(this), false);
        });

        $tabs.on('keydown', function(e) {
            var index = $tabs.index(this);
            var next;
            switch (e.key) {
                case 'ArrowRight':
                    next = (index + 1) % $tabs.length;
                    break;
                case 'ArrowLeft':
                    next = (index - 1 + $tabs.length) % $tabs.length;
                    break;
                case 'Home':
                    next = 0;
                    break;
                case 'End':
                    next = $tabs.length - 1;
                    break;
                default:
                    return;
            }
            e.preventDefault();
            selectTab($tabs.eq(next), true);
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

        /**
         * Reset every results surface: the three accumulator arrays and the
         * three containers.
         *
         * These must move together. The Export CSV handler picks the first
         * non-empty array (block, then pattern, then shortcode), so leaving a
         * previous search's array populated makes the button export stale data
         * that is no longer on screen - a block search followed by a pattern
         * search would export the block results.
         */
        function clearAllResults() {
            // Cancel any search still in flight on another surface. Each batch
            // callback checks its own guard and drops the response when it no
            // longer matches, so a block search that was mid-batch when a
            // pattern search started cannot repopulate allResults and paint
            // its table over the pattern results - which would have made the
            // export produce block data under a pattern filename.
            searchToken.block++;
            searchToken.pattern++;
            searchToken.shortcode++;
            searchMeta.block = null;
            searchMeta.pattern = null;
            searchMeta.shortcode = null;
            // A superseded search's responses are dropped, so its own
            // completion path never runs: put every surface's controls back
            // to idle here. The search that is starting sets its own busy
            // state immediately after this returns.
            $('#fbps-search-button, #fbps-pattern-search-button, #fbps-shortcode-search-button')
                .prop('disabled', false).attr('aria-busy', 'false');
            $('#fbps-cancel-button, #fbps-pattern-cancel-button, #fbps-shortcode-cancel-button').hide();
            $('#fbps-progress, #fbps-pattern-progress, #fbps-shortcode-progress').empty().hide();
            allResults = [];
            allPatternResults = [];
            allShortcodeResults = [];
            resetSort('block');
            resetSort('pattern');
            resetSort('shortcode');
            syncResultsRegion($('#fbps-search-results').empty());
            syncResultsRegion($('#fbps-pattern-search-results').empty());
            syncResultsRegion($('#fbps-shortcode-search-results').empty());
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

        /**
         * Put focus back on a search button that lost it by being disabled.
         *
         * Disabling the element that currently has focus drops focus to <body>,
         * which sends a keyboard user back to the top of the document. Since the
         * results no longer take focus, this is the only thing keeping the user
         * where they were.
         *
         * Only restores when focus actually fell to body or was lost entirely -
         * if the user moved somewhere else while the search ran, leave them.
         */
        function restoreFocusIfLost($button) {
            var active = document.activeElement;
            if (!active || active === document.body || active === document.documentElement) {
                $button.trigger('focus');
            }
        }

        /** The block search has two "nothing found" messages depending on the fields used. */
        function currentNoBlockResultsMsg() {
            var className = $('#fbps-class-name').val();
            var anchorName = $('#fbps-anchor-name').val();
            return (className || anchorName) ? fbpsData.i18n.noAttributeResults : fbpsData.i18n.noBlockResults;
        }

        /**
         * Announce that a search finished.
         *
         * The results containers are deliberately not live regions: when they
         * were, aria-atomic made NVDA read the whole table on every change.
         * The progress region is small, has role="status", and exists from page
         * load, which is what makes it announce reliably - a live region that
         * arrives together with its own content does not.
         *
         * The text is screen-reader only. The visible count already sits above
         * the table, so showing it twice would just be noise.
         */
        function announceSearchComplete($progress, count, noResultsMsg, surface) {
            var msg = count
                ? count + ' ' + (count === 1 ? fbpsData.i18n.result : fbpsData.i18n.results) + ' ' + fbpsData.i18n.found
                : noResultsMsg;
            var m = surface && searchMeta[surface];
            if (m && m.truncated) {
                msg += ' ' + fbpsData.i18n.truncatedNotice.replace('%1$s', String(m.scanned)).replace('%2$s', String(m.total));
            }
            $progress
                .addClass('fbps-progress-done')
                .html('<span class="screen-reader-text">' + escapeHtml(msg) + '</span>');
        }

        /**
         * The current sort for each search surface.
         *
         * Sorting is applied to the accumulated ARRAY, never to the rendered
         * rows, so the table and the CSV export can never disagree about
         * order. Every surface starts newest-first by the Modified column; a
         * new search resets it; clicking a header changes it and re-renders.
         */
        var sortState = {
            block:     { col: 'date', dir: 'desc' },
            pattern:   { col: 'date', dir: 'desc' },
            shortcode: { col: 'date', dir: 'desc' }
        };

        function resetSort(surface) {
            sortState[surface] = { col: 'date', dir: 'desc' };
        }

        /**
         * Return a copy of items ordered by the given column and direction.
         *
         * Dates compare as timestamps; everything else compares as
         * case-insensitive text. Rows whose date does not parse sink to the
         * bottom in either direction rather than landing somewhere arbitrary.
         */
        function sortResults(items, col, dir) {
            var sign = (dir === 'asc') ? 1 : -1;
            return items.slice().sort(function(a, b) {
                var av, bv;
                if (col === 'date') {
                    av = parseMysqlDate(a && a.date);
                    bv = parseMysqlDate(b && b.date);
                    if (isNaN(av) && isNaN(bv)) return 0;
                    if (isNaN(av)) return 1;
                    if (isNaN(bv)) return -1;
                    return (av - bv) * sign;
                }
                av = String((a && a[col]) || '').toLowerCase();
                bv = String((b && b[col]) || '').toLowerCase();
                if (av < bv) return -1 * sign;
                if (av > bv) return 1 * sign;
                return 0;
            });
        }

        /** Sort by the surface's current sort state. */
        function applySort(surface, items) {
            var st = sortState[surface];
            return sortResults(items, st.col, st.dir);
        }

        /**
         * Parse a MySQL DATETIME ("2026-09-18 14:23:01") into a timestamp.
         *
         * new Date() on that string is engine-dependent - the space instead of
         * a "T" is not in the ECMAScript date-time format, and Safari has
         * returned Invalid Date for it. Where that happened the default sort
         * silently left rows in ID order while the header still announced
         * "sorted descending". Parsing the fields explicitly makes the result
         * the same in every engine. Returns NaN for anything else.
         */
        function parseMysqlDate(str) {
            var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}):(\d{2}))?$/.exec(String(str || '').trim());
            if (!m) return NaN;
            return new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0), +(m[6] || 0)).getTime();
        }

        // Format date to match WordPress admin style
        function formatDate(dateString) {
            var ts = parseMysqlDate(dateString);
            if (isNaN(ts)) return dateString;
            var date = new Date(ts);

            var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            var year = date.getFullYear();
            var month = months[date.getMonth()];
            var day = date.getDate();

            return month + ' ' + day + ', ' + year;
        }

        /**
         * Bind the column-header sort buttons of one surface.
         *
         * A click changes that surface's sort state, re-sorts the underlying
         * array and re-renders the table from it - it does not shuffle DOM rows.
         * That is what keeps the CSV export in the same order as the screen.
         *
         * Re-rendering destroys the button the user just pressed, so focus is
         * put back on the same column's new button; otherwise it would fall to
         * <body> and a keyboard user would be sent to the top of the page.
         */
        function initTableSort(containerSelector, surface) {
            $(containerSelector + ' .fbps-results-table th.sortable .fbps-sort-button').off('click').on('click', function(e){
                e.preventDefault();
                var column = $(this).closest('th').data('column');
                var st = sortState[surface];
                if (st.col === column) {
                    st.dir = (st.dir === 'asc') ? 'desc' : 'asc';
                } else {
                    st.col = column;
                    st.dir = 'asc';
                }
                var r = surfaces[surface];
                r.set(applySort(surface, r.get()));
                r.display(r.get(), r.complete());
                $(containerSelector + ' th[data-column="' + column + '"] .fbps-sort-button').trigger('focus');
            });
        }

        function getSelectedPostTypes() {
            var selected = $('#fbps-post-types').val();
            return selected && selected.length ? selected : ['post', 'page'];
        }

        function searchBlockBatch(token, block, postTypes, offset, accumulated, className, anchorName) {
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
                // Dropped if this search was cancelled or another search started
                // while the request was in flight. Everything below writes
                // shared state, so the check has to come first.
                if (token !== searchToken.block) {
                    return;
                }
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

                accumulated = applySort('block', accumulated.concat(data.results));
                allResults = accumulated;

                // Store total_posts from first batch
                if (data.total_posts) {
                    window.fbps_total_posts = data.total_posts;
                }

                // Update progress
                updateProgress(accumulated.length);

                if (!data.has_more) {
                    searchMeta.block = { truncated: !!data.truncated, scanned: data.scanned, total: data.total_posts };
                }

                // Display current results
                displayResults(accumulated, !data.has_more);

                // Continue batching if more results
                if (data.has_more) {
                    searchBlockBatch(token, block, postTypes, data.next_offset, accumulated, className, anchorName);
                } else {
                    $('#fbps-search-button').prop('disabled', false).attr('aria-busy', 'false');
            restoreFocusIfLost($('#fbps-search-button'));
                    $('#fbps-cancel-button').hide();
                    announceSearchComplete($('#fbps-progress'), accumulated.length, currentNoBlockResultsMsg(), 'block');
                    if (accumulated.length > 0) {
                        $('#fbps-export-button').show();
                    }
                }
            }).fail(function() {
                // Same rule as the success path: a failure from a cancelled or
                // replaced search must not repaint newer results or reset controls.
                if (token !== searchToken.block) {
                    return;
                }
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

        function truncationNotice(surface) {
            var m = searchMeta[surface];
            if (!m || !m.truncated) return '';
            var msg = fbpsData.i18n.truncatedNotice
                .replace('%1$s', String(m.scanned))
                .replace('%2$s', String(m.total));
            return '<div class="notice notice-warning inline fbps-truncated"><p>' + escapeHtml(msg) + '</p></div>';
        }

        function buildResultsTableHtml(data, isComplete, noResultsMsg, surface) {
            var st = sortState[surface] || sortState.block;
            var html = isComplete ? truncationNotice(surface) : '';
            var cols = getVisibleColumns();
            if (data.length) {
                                // Not a live region: it is injected with the table, and a live
                // region that arrives with its own content does not announce.
                // announceSearchComplete() carries the announcement instead.
                html += '<p class="fbps-results-count">';
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
                        // The header states the sort that applySort() actually put on
                        // the data. It was a false statement to a screen reader when
                        // the rows were still in post-ID order.
                        var isSorted = (col === st.col);
                        var sortedClass = isSorted ? ' sorted ' + st.dir : '';
                        var ariaSort = isSorted ? (st.dir === 'asc' ? 'ascending' : 'descending') : 'none';
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
                                // date_display is formatted server-side with the site's
                                // date format and locale. formatDate() is the fallback only.
                                html += '<td>' + escapeHtml(item.date_display || formatDate(val)) + '</td>';
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
                html += '<p>' + escapeHtml(noResultsMsg) + '</p>';
            }
            return html;
        }

        function displayResults(data, isComplete) {
            blockSearchComplete = isComplete;
            var className = $('#fbps-class-name').val();
            var anchorName = $('#fbps-anchor-name').val();
            var noResultsMsg = (className || anchorName) ? fbpsData.i18n.noAttributeResults : fbpsData.i18n.noBlockResults;
            var html = buildResultsTableHtml(data, isComplete, noResultsMsg, 'block');
            // Focus is deliberately left where the user put it. Moving it into
            // this container made NVDA read the entire table, and it ejected a
            // keyboard user from the column-toggle fieldset on every change.
            // announceSearchComplete() reports the outcome instead.
            syncResultsRegion($('#fbps-search-results').html(html));
            initTableSort('#fbps-search-results', 'block');
        }

        function displayError(message) {
            var html = '<div role="alert" class="notice notice-error"><p><strong>' + escapeHtml(fbpsData.i18n.error) + '</strong> '+ message +'</p></div>';

            // Keep what the completed batches already found. Replacing the whole
            // container used to discard every result gathered before the failure,
            // which on a large site meant losing hundreds of rows to one blip -
            // and the retry is itself rate limited.
            if (allResults.length) {
                html += '<p class="fbps-partial-notice">' + escapeHtml(fbpsData.i18n.partialResults) + '</p>';
                html += buildResultsTableHtml(allResults, false, fbpsData.i18n.noBlockResults, 'block');
            }

            syncResultsRegion($('#fbps-search-results').html(html));
            initTableSort('#fbps-search-results', 'block');
            $('#fbps-search-button').prop('disabled', false).attr('aria-busy', 'false');
            restoreFocusIfLost($('#fbps-search-button'));
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

            clearAllResults();
            var token = searchToken.block;   // clearAllResults() advanced it; this search owns the new value
            $('#fbps-export-button').hide();
            $('#fbps-search-button').prop('disabled', true).attr('aria-busy', 'true');
            $('#fbps-cancel-button').show();
            $('#fbps-progress').removeClass('fbps-progress-done').show();
            updateProgress(0);

            ensureFreshNonce(function() {
                searchBlockBatch(token, block, getSelectedPostTypes(), 0, [], className, anchorName);
            });
        }

        $('#fbps-search-button').on('click', function(){
            searchBlock( $('#fbps-block-name').val() );
        });

        // Cancel block search
        $('#fbps-cancel-button').on('click', function(){
            searchToken.block++;   // in-flight responses will see a stale token and drop
            $('#fbps-search-button').prop('disabled', false).attr('aria-busy', 'false');
            restoreFocusIfLost($('#fbps-search-button'));
            $('#fbps-cancel-button').hide();
            $('#fbps-progress').hide();
            var html = '<div role="alert" class="notice notice-warning"><p>' + escapeHtml(fbpsData.i18n.searchCancelled) + '</p></div>';
            syncResultsRegion($('#fbps-search-results').html(html));
        });

        // Unified CSV Export - detects which search type has results
        $('#fbps-export-button').on('click', function(){
            var cols = getVisibleColumns();
            // Build CSV header from visible columns + View Link, using the same
            // translated labels as the table so the two never disagree.
            var headerParts = [];
            // Quoted like the data rows: the labels are translatable now, and a
            // locale whose label contains a comma or a quote would otherwise
            // shift the header against the columns.
            cols.forEach(function(col) {
                var def = columnDefs[col];
                headerParts.push('"' + sanitizeCsvValue(def ? fbpsData.i18n[def.label] : col) + '"');
            });
            headerParts.push('"' + sanitizeCsvValue(fbpsData.i18n.viewLink) + '"');
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

            // Build CSV from results using visible columns.
            // The Title cell uses the same fallback as the table, so a row that
            // reads "(no title)" on screen reads "(no title)" in the export
            // rather than coming out blank.
            //
            // The Modified cell is deliberately NOT the localized display value
            // the table shows: the export is data, and the raw MySQL datetime
            // sorts correctly as text in a spreadsheet where "March 29, 2026"
            // would not.
            results.forEach(function(item){
                var rowParts = [];
                cols.forEach(function(col) {
                    var val = (col === 'title') ? (item.title || fbpsData.i18n.noTitle) : (item[col] || '');
                    rowParts.push('"' + sanitizeCsvValue(val) + '"');
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

        function searchPatternBatch(token, patternId, postTypes, offset, accumulated) {
            offset = offset || 0;
            accumulated = accumulated || [];

            $.post(fbpsData.ajaxUrl, {
                action:      'fbps_search_pattern',
                pattern_id:  patternId,
                post_types:  postTypes,
                batch_offset: offset,
                _ajax_nonce: currentNonce
            }, function(response){
                // Dropped if this search was cancelled or another search started
                // while the request was in flight. Everything below writes
                // shared state, so the check has to come first.
                if (token !== searchToken.pattern) {
                    return;
                }
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

                accumulated = applySort('pattern', accumulated.concat(data.results));
                allPatternResults = accumulated;

                // Store total_posts from first batch
                if (data.total_posts) {
                    window.fbps_pattern_total_posts = data.total_posts;
                }

                // Update progress
                updatePatternProgress(accumulated.length);

                if (!data.has_more) {
                    searchMeta.pattern = { truncated: !!data.truncated, scanned: data.scanned, total: data.total_posts };
                }

                // Display current results
                displayPatternResults(accumulated, !data.has_more);

                // Continue batching if more results
                if (data.has_more) {
                    searchPatternBatch(token, patternId, postTypes, data.next_offset, accumulated);
                } else {
                    $('#fbps-pattern-search-button').prop('disabled', false).attr('aria-busy', 'false');
            restoreFocusIfLost($('#fbps-pattern-search-button'));
                    $('#fbps-pattern-cancel-button').hide();
                    announceSearchComplete($('#fbps-pattern-progress'), accumulated.length, fbpsData.i18n.noPatternResults, 'pattern');
                    if (accumulated.length > 0) {
                        $('#fbps-export-button').show();
                    }
                }
            }).fail(function() {
                // Same rule as the success path: a failure from a cancelled or
                // replaced search must not repaint newer results or reset controls.
                if (token !== searchToken.pattern) {
                    return;
                }
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
            patternSearchComplete = isComplete;
            var html = buildResultsTableHtml(data, isComplete, fbpsData.i18n.noPatternResults, 'pattern');
            // Focus is deliberately left where the user put it. Moving it into
            // this container made NVDA read the entire table, and it ejected a
            // keyboard user from the column-toggle fieldset on every change.
            // announceSearchComplete() reports the outcome instead.
            syncResultsRegion($('#fbps-pattern-search-results').html(html));
            initTableSort('#fbps-pattern-search-results', 'pattern');
        }

        function displayPatternError(message) {
            var html = '<div role="alert" class="notice notice-error"><p><strong>' + escapeHtml(fbpsData.i18n.error) + '</strong> '+ message +'</p></div>';

            // Keep what the completed batches already found. Replacing the whole
            // container used to discard every result gathered before the failure,
            // which on a large site meant losing hundreds of rows to one blip -
            // and the retry is itself rate limited.
            if (allPatternResults.length) {
                html += '<p class="fbps-partial-notice">' + escapeHtml(fbpsData.i18n.partialResults) + '</p>';
                html += buildResultsTableHtml(allPatternResults, false, fbpsData.i18n.noPatternResults, 'pattern');
            }

            syncResultsRegion($('#fbps-pattern-search-results').html(html));
            initTableSort('#fbps-pattern-search-results', 'pattern');
            $('#fbps-pattern-search-button').prop('disabled', false).attr('aria-busy', 'false');
            restoreFocusIfLost($('#fbps-pattern-search-button'));
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

            clearAllResults();
            var token = searchToken.pattern;
            $('#fbps-export-button').hide();
            $('#fbps-pattern-search-button').prop('disabled', true).attr('aria-busy', 'true');
            $('#fbps-pattern-cancel-button').show();
            $('#fbps-pattern-progress').removeClass('fbps-progress-done').show();
            updatePatternProgress(0);

            ensureFreshNonce(function() {
                searchPatternBatch(token, patternId, getSelectedPatternPostTypes(), 0, []);
            });
        }

        // Pattern search button handler
        $('#fbps-pattern-search-button').on('click', function(){
            searchPattern( $('#fbps-pattern-dropdown').val() );
        });

        // Cancel pattern search
        $('#fbps-pattern-cancel-button').on('click', function(){
            searchToken.pattern++;
            $('#fbps-pattern-search-button').prop('disabled', false).attr('aria-busy', 'false');
            restoreFocusIfLost($('#fbps-pattern-search-button'));
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

        function searchShortcodeBatch(token, shortcodeName, postTypes, offset, accumulated) {
            offset = offset || 0;
            accumulated = accumulated || [];

            $.post(fbpsData.ajaxUrl, {
                action:         'fbps_search_shortcode',
                shortcode_name: shortcodeName,
                post_types:     postTypes,
                batch_offset:   offset,
                _ajax_nonce:    currentNonce
            }, function(response){
                // Dropped if this search was cancelled or another search started
                // while the request was in flight. Everything below writes
                // shared state, so the check has to come first.
                if (token !== searchToken.shortcode) {
                    return;
                }
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

                accumulated = applySort('shortcode', accumulated.concat(data.results));
                allShortcodeResults = accumulated;

                // Store total_posts from first batch
                if (data.total_posts) {
                    window.fbps_shortcode_total_posts = data.total_posts;
                }

                // Update progress
                updateShortcodeProgress(accumulated.length);

                if (!data.has_more) {
                    searchMeta.shortcode = { truncated: !!data.truncated, scanned: data.scanned, total: data.total_posts };
                }

                // Display current results
                displayShortcodeResults(accumulated, !data.has_more);

                // Continue batching if more results
                if (data.has_more) {
                    searchShortcodeBatch(token, shortcodeName, postTypes, data.next_offset, accumulated);
                } else {
                    $('#fbps-shortcode-search-button').prop('disabled', false).attr('aria-busy', 'false');
            restoreFocusIfLost($('#fbps-shortcode-search-button'));
                    $('#fbps-shortcode-cancel-button').hide();
                    announceSearchComplete($('#fbps-shortcode-progress'), accumulated.length, fbpsData.i18n.noShortcodeResults, 'shortcode');
                    if (accumulated.length > 0) {
                        $('#fbps-export-button').show();
                    }
                }
            }).fail(function() {
                // Same rule as the success path: a failure from a cancelled or
                // replaced search must not repaint newer results or reset controls.
                if (token !== searchToken.shortcode) {
                    return;
                }
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
            shortcodeSearchComplete = isComplete;
            var html = buildResultsTableHtml(data, isComplete, fbpsData.i18n.noShortcodeResults, 'shortcode');
            // Focus is deliberately left where the user put it. Moving it into
            // this container made NVDA read the entire table, and it ejected a
            // keyboard user from the column-toggle fieldset on every change.
            // announceSearchComplete() reports the outcome instead.
            syncResultsRegion($('#fbps-shortcode-search-results').html(html));
            initTableSort('#fbps-shortcode-search-results', 'shortcode');
        }

        function displayShortcodeError(message) {
            var html = '<div role="alert" class="notice notice-error"><p><strong>' + escapeHtml(fbpsData.i18n.error) + '</strong> '+ message +'</p></div>';

            // Keep what the completed batches already found. Replacing the whole
            // container used to discard every result gathered before the failure,
            // which on a large site meant losing hundreds of rows to one blip -
            // and the retry is itself rate limited.
            if (allShortcodeResults.length) {
                html += '<p class="fbps-partial-notice">' + escapeHtml(fbpsData.i18n.partialResults) + '</p>';
                html += buildResultsTableHtml(allShortcodeResults, false, fbpsData.i18n.noShortcodeResults, 'shortcode');
            }

            syncResultsRegion($('#fbps-shortcode-search-results').html(html));
            initTableSort('#fbps-shortcode-search-results', 'shortcode');
            $('#fbps-shortcode-search-button').prop('disabled', false).attr('aria-busy', 'false');
            restoreFocusIfLost($('#fbps-shortcode-search-button'));
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

            clearAllResults();
            var token = searchToken.shortcode;
            $('#fbps-export-button').hide();
            $('#fbps-shortcode-search-button').prop('disabled', true).attr('aria-busy', 'true');
            $('#fbps-shortcode-cancel-button').show();
            $('#fbps-shortcode-progress').removeClass('fbps-progress-done').show();
            updateShortcodeProgress(0);

            ensureFreshNonce(function() {
                searchShortcodeBatch(token, shortcodeName, getSelectedShortcodePostTypes(), 0, []);
            });
        }

        // Shortcode search button handler
        $('#fbps-shortcode-search-button').on('click', function(){
            searchShortcode( $('#fbps-shortcode-dropdown').val() );
        });

        // Cancel shortcode search
        $('#fbps-shortcode-cancel-button').on('click', function(){
            searchToken.shortcode++;
            $('#fbps-shortcode-search-button').prop('disabled', false).attr('aria-busy', 'false');
            restoreFocusIfLost($('#fbps-shortcode-search-button'));
            $('#fbps-shortcode-cancel-button').hide();
            $('#fbps-shortcode-progress').hide();
            var html = '<div role="alert" class="notice notice-warning"><p>' + escapeHtml(fbpsData.i18n.searchCancelled) + '</p></div>';
            syncResultsRegion($('#fbps-shortcode-search-results').html(html));
        });

        /** How initTableSort() reaches each surface's data and renderer. */
        var surfaces = {
            block: {
                get: function() { return allResults; },
                set: function(v) { allResults = v; },
                display: function(d, c) { displayResults(d, c); },
                complete: function() { return blockSearchComplete; }
            },
            pattern: {
                get: function() { return allPatternResults; },
                set: function(v) { allPatternResults = v; },
                display: function(d, c) { displayPatternResults(d, c); },
                complete: function() { return patternSearchComplete; }
            },
            shortcode: {
                get: function() { return allShortcodeResults; },
                set: function(v) { allShortcodeResults = v; },
                display: function(d, c) { displayShortcodeResults(d, c); },
                complete: function() { return shortcodeSearchComplete; }
            }
        };

        // Re-render results when column toggles change
        $('.fbps-col-toggle').on('change', function() {
            if (allResults.length > 0) {
                displayResults(allResults, blockSearchComplete);
            }
            if (allPatternResults.length > 0) {
                displayPatternResults(allPatternResults, patternSearchComplete);
            }
            if (allShortcodeResults.length > 0) {
                displayShortcodeResults(allShortcodeResults, shortcodeSearchComplete);
            }
        });
    });
})(jQuery);
