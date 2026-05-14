/**
 * HIMS ICD-11 Lookup — Public JS
 * Version: 2.0.0
 */
(function($){
    'use strict';

    var cfg    = window.himsICD11 || {};
    var i18n   = cfg.i18n || {};
    var state  = {
        query:       '',
        results:     [],
        page:        1,
        totalPages:  1,
        lang:        cfg.lang || 'en',
        release:     cfg.release || '2025-01',
        perPage:     parseInt(cfg.per_page) || 10,
        searchTimer: null,
        suggestTimer:null,
        browseStack: [],   // for browse breadcrumbs
    };

    /* ════════════════════════════════════════
       INIT
    ════════════════════════════════════════ */
    $(document).ready(function(){
        initTabs();
        initSearch();
        initCodeInfo();
        initHistory();
        loadChapters();
        loadChapterFilter();
    });

    /* ════════════════════════════════════════
       TABS
    ════════════════════════════════════════ */
    function initTabs(){
        $(document).on('click', '.hims-tab', function(){
            var tab = $(this).data('tab');
            var $wrap = $(this).closest('.hims-icd11-wrap');
            $wrap.find('.hims-tab').removeClass('active');
            $wrap.find('.hims-tab-content').removeClass('active');
            $(this).addClass('active');
            $wrap.find('#hims-tab-' + tab).addClass('active');

            if(tab === 'history') loadHistory();
            if(tab === 'browse')  loadChapters();
        });
    }

    /* ════════════════════════════════════════
       SEARCH
    ════════════════════════════════════════ */
    function initSearch(){
        /* Typing → debounce search + suggestions */
        $(document).on('keyup', '#hims-search-input', function(e){
            var q = $(this).val().trim();

            // Suggestions
            clearTimeout(state.suggestTimer);
            if(q.length >= 2){
                state.suggestTimer = setTimeout(function(){ fetchSuggestions(q); }, 300);
            } else {
                $('#hims-suggestions').hide().empty();
            }

            // Search on Enter or after pause
            if(e.key === 'Enter'){
                clearTimeout(state.searchTimer);
                doSearch(q, 1);
                $('#hims-suggestions').hide();
                return;
            }

            if(q.length >= 3){
                clearTimeout(state.searchTimer);
                state.searchTimer = setTimeout(function(){ doSearch(q, 1); }, 600);
            }
        });

        /* Clear button */
        $(document).on('click', '#hims-search-clear', function(){
            $('#hims-search-input').val('').focus();
            $('#hims-search-results, #hims-search-pagination').empty();
            $('#hims-detail-panel').hide();
            $('#hims-suggestions').hide().empty();
            $('#hims-search-status').text('');
        });

        /* Filter change → re-search */
        $(document).on('change', '#hims-filter-chapter, #hims-filter-flex', function(){
            var q = $('#hims-search-input').val().trim();
            if(q.length >= 2) doSearch(q, 1);
        });
        $(document).on('change', '#hims-filter-keyword', function(){
            var q = $('#hims-search-input').val().trim();
            if(q.length >= 2) doSearch(q, 1);
        });

        /* Click suggestion */
        $(document).on('click', '.hims-suggestion-item', function(){
            var code  = $(this).data('code');
            var title = $(this).data('title');
            var id    = $(this).data('id');
            $('#hims-search-input').val(code + ' ' + title);
            $('#hims-suggestions').hide();
            doSearch(code + ' ' + title, 1);
        });

        /* Click result item → show detail */
        $(document).on('click', '.hims-result-item', function(){
            var id = $(this).data('id');
            fetchEntityDetail(id);
        });

        /* Close suggestions on outside click */
        $(document).on('click', function(e){
            if(!$(e.target).closest('.hims-search-bar').length){
                $('#hims-suggestions').hide();
            }
        });

        /* Export buttons (dynamic, added inside results) */
        $(document).on('click', '.hims-export-csv-btn', function(){
            exportResults('csv');
        });
        $(document).on('click', '.hims-export-json-btn', function(){
            exportResults('json');
        });
    }

    /* ── Do Search ── */
    function doSearch(q, page){
        if(!q) return;
        state.query = q;
        state.page  = page || 1;

        var $status  = $('#hims-search-status');
        var $results = $('#hims-search-results');
        var $pages   = $('#hims-search-pagination');
        var $detail  = $('#hims-detail-panel');

        $status.text(i18n.searching || 'Searching…');
        $results.empty();
        $pages.empty();
        $detail.hide();

        $.post(cfg.ajax_url, {
            action:   'hims_icd11_search',
            nonce:    cfg.nonce,
            q:        q,
            lang:     getLang(),
            flex:     $('#hims-filter-flex').val() || 'false',
            chapter:  $('#hims-filter-chapter').val() || '',
            keywords: $('#hims-filter-keyword').is(':checked') ? 'true' : 'false',
        }, function(res){
            if(!res.success){
                $status.text('⚠ ' + (res.data || i18n.error));
                return;
            }
            var data    = res.data;
            var all     = data.destinationEntities || [];
            var total   = all.length;
            var pp      = state.perPage;
            var pages   = Math.ceil(total / pp);
            state.results    = all;
            state.totalPages = pages;

            var slice = all.slice((state.page-1)*pp, state.page*pp);

            if(!total){
                $status.text('');
                $results.html(renderEmpty('No results found for "<strong>' + escHtml(q) + '</strong>"'));
                return;
            }

            $status.html('Found <strong>' + total + '</strong> result(s) for "<em>' + escHtml(q) + '</em>" &nbsp; <button class="hims-btn hims-btn-sm hims-export-csv-btn">⬇ CSV</button> <button class="hims-btn hims-btn-sm hims-export-json-btn">⬇ JSON</button>');

            var html = '';
            slice.forEach(function(r){ html += renderResultItem(r); });
            $results.html(html);

            renderPagination($pages, state.page, pages);

        }).fail(function(){
            $status.text('⚠ ' + (i18n.error || 'Request failed.'));
        });
    }

    /* ── Render result item ── */
    function renderResultItem(r){
        var code  = r.theCode || '';
        var title = r.title   || r.titleEnglish || '';
        var chap  = r.chapter || '';
        var id    = entityIdFromUri(r.id || '');
        var classKind = r.classKind || '';

        return '<div class="hims-result-item" data-id="' + escHtml(id) + '">' +
            (code ? '<div class="hims-result-code">' + escHtml(code) + '</div>' : '') +
            '<div class="hims-result-body">' +
                '<div class="hims-result-title">' + title + '</div>' +
                '<div class="hims-result-meta">' +
                    (chap ? '<span class="hims-result-chapter">' + escHtml(chap) + '</span>' : '') +
                    (classKind ? '<span>📁 ' + escHtml(classKind) + '</span>' : '') +
                '</div>' +
            '</div>' +
            '<span class="hims-result-arrow">›</span>' +
        '</div>';
    }

    /* ── Pagination ── */
    function renderPagination($el, current, total){
        if(total <= 1){ $el.empty(); return; }
        var html = '<button class="hims-page-btn" data-page="' + (current-1) + '" ' + (current<=1?'disabled':'') + '>‹</button>';
        var start = Math.max(1, current-2);
        var end   = Math.min(total, current+2);
        if(start>1) html += '<button class="hims-page-btn" data-page="1">1</button>' + (start>2?'<span style="padding:0 4px">…</span>':'');
        for(var i=start; i<=end; i++){
            html += '<button class="hims-page-btn' + (i===current?' active':'') + '" data-page="'+i+'">'+i+'</button>';
        }
        if(end<total) html += (end<total-1?'<span style="padding:0 4px">…</span>':'') + '<button class="hims-page-btn" data-page="'+total+'">'+total+'</button>';
        html += '<button class="hims-page-btn" data-page="'+(current+1)+'" '+(current>=total?'disabled':'')+'">›</button>';
        $el.html(html);
    }

    $(document).on('click', '.hims-page-btn', function(){
        var p = parseInt($(this).data('page'));
        if(!p || p < 1) return;
        state.page = p;
        var pp    = state.perPage;
        var slice = state.results.slice((p-1)*pp, p*pp);
        var html  = '';
        slice.forEach(function(r){ html += renderResultItem(r); });
        $('#hims-search-results').html(html);
        renderPagination($('#hims-search-pagination'), p, state.totalPages);
        $('html,body').animate({scrollTop: $('.hims-icd11-wrap').offset().top - 20}, 300);
    });

    /* ════════════════════════════════════════
       ENTITY DETAIL
    ════════════════════════════════════════ */
    function fetchEntityDetail(entityId){
        var $panel = $('#hims-detail-panel');
        $panel.show().html('<div class="hims-loading">' + (i18n.loading||'Loading…') + '</div>');
        $('html,body').animate({scrollTop: $panel.offset().top - 20}, 300);

        $.post(cfg.ajax_url, {
            action:    'hims_icd11_entity',
            nonce:     cfg.nonce,
            entity_id: entityId,
            lang:      getLang(),
        }, function(res){
            if(!res.success){ $panel.html(renderAlert('error', res.data)); return; }
            $panel.html(renderEntityDetail(res.data));
        }).fail(function(){
            $panel.html(renderAlert('error', i18n.error));
        });
    }

    function renderEntityDetail(e){
        var code   = e.code  || e.blockId || '';
        var title  = getLabel(e.title);
        var desc   = getLabel(e.definition);
        var id     = entityIdFromUri(e['@id'] || '');

        var inclusions = e.inclusion  || [];
        var exclusions = e.exclusion  || [];
        var synonyms   = e.indexTerm  || [];
        var parents    = e.parent     || [];
        var children   = e.child      || [];

        var html = '<div class="hims-detail-panel">';

        // Header
        html += '<div class="hims-detail-header">';
        html += '<div>';
        if(code) html += '<div class="hims-detail-code-badge">' + escHtml(code) + '</div>';
        html += '</div>';
        html += '<div><div class="hims-detail-title">' + escHtml(title) + '</div>';
        if(e.classKind) html += '<div style="font-size:11px;opacity:0.7;margin-top:4px;">📁 ' + escHtml(e.classKind) + '</div>';
        html += '</div></div>';

        // Body
        html += '<div class="hims-detail-body">';

        // Description
        if(desc){
            html += '<div class="hims-detail-section">';
            html += '<div class="hims-detail-section-title">📋 Description</div>';
            html += '<div class="hims-detail-description">' + escHtml(desc) + '</div>';
            html += '</div>';
        }

        // Grid fields
        var fields = [
            ['Code', code],
            ['Class Kind', e.classKind],
            ['Chapter', e.chapterCode ? e.chapterCode + ' — ' + (e.chapter||'') : ''],
            ['Parent Count', parents.length],
            ['Children Count', children.length],
        ].filter(f => f[1]);

        if(fields.length){
            html += '<div class="hims-detail-section">';
            html += '<div class="hims-detail-section-title">ℹ️ Details</div>';
            html += '<div class="hims-detail-grid">';
            fields.forEach(function(f){
                html += '<div class="hims-detail-field"><div class="hims-detail-field-label">' + escHtml(f[0]) + '</div><div class="hims-detail-field-value">' + escHtml(String(f[1])) + '</div></div>';
            });
            html += '</div></div>';
        }

        // Synonyms
        if(synonyms.length){
            html += '<div class="hims-detail-section">';
            html += '<div class="hims-detail-section-title">🔤 Synonyms / Index Terms</div>';
            html += '<div class="hims-tags">';
            synonyms.slice(0,20).forEach(function(s){
                var lbl = getLabel(s.label || s);
                if(lbl) html += '<span class="hims-tag hims-tag-synonym">' + escHtml(lbl) + '</span>';
            });
            html += '</div></div>';
        }

        // Inclusions
        if(inclusions.length){
            html += '<div class="hims-detail-section">';
            html += '<div class="hims-detail-section-title">✅ Inclusions</div>';
            html += '<div class="hims-tags">';
            inclusions.forEach(function(inc){
                var lbl = getLabel(inc.label || inc);
                if(lbl) html += '<span class="hims-tag hims-tag-inclusion">' + escHtml(lbl) + '</span>';
            });
            html += '</div></div>';
        }

        // Exclusions
        if(exclusions.length){
            html += '<div class="hims-detail-section">';
            html += '<div class="hims-detail-section-title">❌ Exclusions</div>';
            html += '<div class="hims-tags">';
            exclusions.forEach(function(exc){
                var lbl = getLabel(exc.label || exc);
                if(lbl) html += '<span class="hims-tag hims-tag-exclusion">' + escHtml(lbl) + '</span>';
            });
            html += '</div></div>';
        }

        // Children (if not too many)
        if(children.length && children.length <= 30){
            html += '<div class="hims-detail-section">';
            html += '<div class="hims-detail-section-title">👶 Child Categories (' + children.length + ')</div>';
            html += '<div id="hims-children-' + id + '" class="hims-tags"><button class="hims-btn hims-btn-sm" onclick="himsLoadChildren(\'' + id + '\')">Load Children</button></div>';
            html += '</div>';
        }

        html += '</div>'; // body

        // Actions
        html += '<div class="hims-detail-actions">';
        if(code) html += '<button class="hims-btn hims-btn-sm hims-btn-copy" onclick="himsCopyText(\'' + escHtml(code) + '\',this)">📋 Copy Code</button>';
        html += '<button class="hims-btn hims-btn-sm hims-btn-copy" onclick="himsCopyText(\'' + escHtml(title) + '\',this)">📋 Copy Title</button>';
        if(code) html += '<button class="hims-btn hims-btn-sm" onclick="himsOpenWHO(\'' + escHtml(code) + '\')">🌐 Open on WHO</button>';
        if(id) html += '<button class="hims-btn hims-btn-sm" onclick="himsLoadPostcoor(\'' + escHtml(id) + '\')">🔗 Postcoordination</button>';
        html += '<button class="hims-btn hims-btn-sm" onclick="$(\'#hims-detail-panel\').hide()">✕ Close</button>';
        html += '</div>';

        html += '</div>'; // detail panel
        return html;
    }

    /* ════════════════════════════════════════
       SUGGESTIONS / AUTOCOMPLETE
    ════════════════════════════════════════ */
    function fetchSuggestions(q){
        $.post(cfg.ajax_url, {
            action: 'hims_icd11_suggest',
            nonce:  cfg.nonce,
            q:      q,
            lang:   getLang(),
        }, function(res){
            var $sug = $('#hims-suggestions');
            if(!res.success || !res.data.length){ $sug.hide().empty(); return; }
            var html = '';
            res.data.slice(0,8).forEach(function(r){
                var code  = r.theCode || '';
                var title = r.title || r.titleEnglish || '';
                var id    = entityIdFromUri(r.id || '');
                html += '<div class="hims-suggestion-item" data-code="' + escHtml(code) + '" data-title="' + escHtml(title) + '" data-id="' + escHtml(id) + '">';
                if(code) html += '<span class="hims-suggestion-code">' + escHtml(code) + '</span>';
                html += '<span>' + escHtml(title) + '</span>';
                html += '</div>';
            });
            $sug.html(html).show();
        });
    }

    /* ════════════════════════════════════════
       CODE INFO TAB
    ════════════════════════════════════════ */
    function initCodeInfo(){
        $(document).on('click', '#hims-code-lookup-btn', function(){
            var code = $('#hims-code-input').val().trim();
            if(!code) return;
            fetchCodeInfo(code);
        });
        $(document).on('keyup', '#hims-code-input', function(e){
            if(e.key === 'Enter') $('#hims-code-lookup-btn').trigger('click');
        });
    }

    function fetchCodeInfo(code){
        var $res = $('#hims-codeinfo-result');
        $res.html('<div class="hims-loading">Loading…</div>');

        $.post(cfg.ajax_url, {
            action: 'hims_icd11_codeinfo',
            nonce:  cfg.nonce,
            code:   code,
            lang:   getLang(),
        }, function(res){
            if(!res.success){ $res.html(renderAlert('error', res.data)); return; }
            $res.html(renderCodeInfo(res.data, code));
        }).fail(function(){
            $res.html(renderAlert('error', i18n.error));
        });
    }

    function renderCodeInfo(data, rawCode){
        var ci = data.codeinfo || {};
        var en = data.entity   || {};
        var stemCode = ci.stemCode || rawCode;
        var title    = getLabel(en.title) || stemCode;
        var desc     = getLabel(en.definition);

        var html = '<div class="hims-codeinfo-card">';
        html += '<div class="hims-codeinfo-header">';
        html += '<div class="hims-codeinfo-code">' + escHtml(stemCode) + '</div>';
        html += '<div class="hims-codeinfo-label">' + escHtml(title) + '</div>';
        html += '</div>';
        html += '<div class="hims-codeinfo-body">';

        if(desc){
            html += '<div class="hims-detail-section"><div class="hims-detail-section-title">📋 Definition</div>';
            html += '<div class="hims-detail-description">' + escHtml(desc) + '</div></div>';
        }

        // Postcoordination axes used
        var axes = ['laterality','specificAnatomy','histopathology','infectiousAgent','hasManifestation','associatedWith','dueToOrAssociatedWith','causality','severity','temporalPatternAndOnset','medication','substance','dosageForm','routeOfAdministration'];
        var usedAxes = [];
        axes.forEach(function(ax){
            if(ci[ax] && ci[ax].length) usedAxes.push({ name: ax, values: ci[ax] });
        });

        if(usedAxes.length){
            html += '<div class="hims-detail-section"><div class="hims-detail-section-title">🔗 Postcoordination Axes</div>';
            html += '<div class="hims-postcoor-axes">';
            usedAxes.forEach(function(ax){
                html += '<div class="hims-postcoor-axis"><strong>' + escHtml(ax.name) + ':</strong> ' + ax.values.map(escHtml).join(', ') + '</div>';
            });
            html += '</div></div>';
        }

        // Entity details
        if(en && en.code){
            html += renderEntityDetail(en);
        }

        html += '</div></div>';
        return html;
    }

    /* ════════════════════════════════════════
       CHAPTERS BROWSE
    ════════════════════════════════════════ */
    function loadChapters(){
        var $grid = $('#hims-chapters-list');
        if(!$grid.length || $grid.data('loaded')) return;
        $grid.data('loaded', true);

        $.post(cfg.ajax_url, {
            action: 'hims_icd11_chapters',
            nonce:  cfg.nonce,
            lang:   getLang(),
        }, function(res){
            if(!res.success){ $grid.html(renderAlert('error', res.data)); return; }
            var children = res.data.child || [];
            if(!children.length){ $grid.html('<p>No chapters found.</p>'); return; }

            // Fetch each chapter detail
            var promises = [];
            var chapterData = [];
            children.forEach(function(uri, idx){
                var id = entityIdFromUri(uri);
                promises.push(
                    $.post(cfg.ajax_url, { action:'hims_icd11_entity', nonce:cfg.nonce, entity_id:id, lang:getLang() })
                    .then(function(r){ if(r.success) chapterData[idx] = r.data; })
                );
            });

            $.when.apply($, promises).then(function(){
                var html = '';
                chapterData.forEach(function(ch, idx){
                    if(!ch) return;
                    var id    = entityIdFromUri(ch['@id'] || '');
                    var code  = ch.code || ch.blockId || String(idx+1);
                    var title = getLabel(ch.title);
                    html += '<div class="hims-chapter-card" data-id="' + escHtml(id) + '">';
                    html += '<div class="hims-chapter-num">' + escHtml(code) + '</div>';
                    html += '<div class="hims-chapter-title">' + escHtml(title) + '</div>';
                    html += '</div>';
                });
                $grid.html(html);

                // Populate chapter filter
                var $filter = $('#hims-filter-chapter');
                if($filter.length){
                    chapterData.forEach(function(ch){
                        if(!ch) return;
                        var code  = ch.code || ch.blockId || '';
                        var title = getLabel(ch.title);
                        if(code) $filter.append('<option value="' + escHtml(code) + '">' + escHtml(code + ' — ' + title.substring(0,40)) + '</option>');
                    });
                }
            });
        });

        /* Click chapter → browse children */
        $(document).on('click', '.hims-chapter-card', function(){
            var id = $(this).data('id');
            var $detail = $('#hims-browse-detail');
            var $content = $('#hims-browse-content');
            $detail.show();
            $('#hims-chapters-list').hide();
            $content.html('<div class="hims-loading">Loading…</div>');
            state.browseStack = [id];

            loadBrowseChildren(id, $content);
        });

        /* Back button */
        $(document).on('click', '#hims-browse-back', function(){
            state.browseStack.pop();
            if(!state.browseStack.length){
                $('#hims-browse-detail').hide();
                $('#hims-chapters-list').show();
            } else {
                var prevId = state.browseStack[state.browseStack.length-1];
                loadBrowseChildren(prevId, $('#hims-browse-content'));
            }
        });
    }

    function loadBrowseChildren(id, $container){
        $.post(cfg.ajax_url, {
            action: 'hims_icd11_entity', nonce: cfg.nonce, entity_id: id, lang: getLang()
        }, function(res){
            if(!res.success){ $container.html(renderAlert('error',res.data)); return; }
            var e = res.data;
            var title = getLabel(e.title);
            var code  = e.code || e.blockId || '';
            var children = e.child || [];

            var html = '<h3 style="margin:0 0 12px;color:#003580;">' + (code?'<code style="margin-right:8px;">'+escHtml(code)+'</code>':'') + escHtml(title) + '</h3>';

            if(children.length){
                html += '<div class="hims-browse-content-grid" id="hims-browse-items">';
                children.forEach(function(uri){
                    var cid = entityIdFromUri(uri);
                    html += '<div class="hims-browse-item" data-id="' + escHtml(cid) + '">';
                    html += '<span class="hims-browse-item-code">' + escHtml(cid) + '</span>';
                    html += '<span class="hims-browse-item-title">Loading…</span>';
                    html += '<span class="hims-browse-item-expand">›</span>';
                    html += '</div>';
                });
                html += '</div>';
            } else {
                // Leaf node → show full detail
                html += renderEntityDetail(e);
            }

            $container.html(html);

            // Load child titles
            children.forEach(function(uri){
                var cid = entityIdFromUri(uri);
                $.post(cfg.ajax_url, { action:'hims_icd11_entity', nonce:cfg.nonce, entity_id:cid, lang:getLang() }, function(r){
                    if(r.success){
                        var ct = getLabel(r.data.title);
                        var cc = r.data.code || r.data.blockId || '';
                        var $row = $container.find('.hims-browse-item[data-id="' + cid + '"]');
                        $row.find('.hims-browse-item-title').text(ct);
                        if(cc) $row.find('.hims-browse-item-code').text(cc);
                    }
                });
            });
        });

        /* Click browse item → drill down */
        $(document).off('click.browse').on('click.browse', '.hims-browse-item', function(){
            var cid = $(this).data('id');
            state.browseStack.push(cid);
            loadBrowseChildren(cid, $('#hims-browse-content'));
        });
    }

    function loadChapterFilter(){
        // Populated after chapters load
    }

    /* ════════════════════════════════════════
       HISTORY
    ════════════════════════════════════════ */
    function initHistory(){
        $(document).on('click', '#hims-history-clear-btn', function(){
            if(!confirm('Clear all your search history?')) return;
            $.post(cfg.ajax_url, { action:'hims_icd11_history_clear', nonce:cfg.nonce }, function(r){
                if(r.success) loadHistory();
            });
        });

        $(document).on('click', '.hims-history-del', function(){
            var id = $(this).data('id');
            $.post(cfg.ajax_url, { action:'hims_icd11_history_del', nonce:cfg.nonce, record_id:id }, function(r){
                if(r.success) loadHistory();
            });
        });

        $(document).on('click', '.hims-history-item-code', function(){
            var code = $(this).text();
            $('#hims-search-input').val(code);
            doSearch(code, 1);
            // Switch to search tab
            $('.hims-tab[data-tab="search"]').trigger('click');
        });

        $(document).on('click', '#hims-history-export-btn', function(){
            var records = [];
            $('#hims-history-list .hims-history-item').each(function(){
                records.push({
                    code:  $(this).find('.hims-history-item-code').text(),
                    title: $(this).find('.hims-history-item-title').text(),
                    date:  $(this).find('.hims-history-item-date').text(),
                });
            });
            var csv = 'Code,Title,Date\n' + records.map(function(r){
                return '"'+r.code+'","'+r.title.replace(/"/g,'""')+'","'+r.date+'"';
            }).join('\n');
            downloadFile(csv, 'icd11-history-' + dateStr() + '.csv', 'text/csv');
        });
    }

    function loadHistory(){
        var $list = $('#hims-history-list');
        if(!$list.length) return;
        $list.html('<div class="hims-loading">Loading…</div>');

        $.post(cfg.ajax_url, { action:'hims_icd11_history_get', nonce:cfg.nonce }, function(res){
            if(!res.success || !res.data.length){
                $list.html(renderEmpty('No search history yet.'));
                return;
            }
            var html = '';
            res.data.forEach(function(r){
                html += '<div class="hims-history-item">';
                html += '<span class="hims-history-item-code" title="Click to search">' + escHtml(r.code) + '</span>';
                html += '<span class="hims-history-item-title">' + escHtml(r.title) + '</span>';
                html += '<span class="hims-history-item-date">' + escHtml(r.date) + '</span>';
                html += '<button class="hims-history-del" data-id="' + r.id + '" title="Remove">✕</button>';
                html += '</div>';
            });
            $list.html(html);
        });
    }

    /* ════════════════════════════════════════
       GLOBAL HELPERS (exposed to inline onclick)
    ════════════════════════════════════════ */
    window.himsCopyText = function(text, btn){
        navigator.clipboard.writeText(text).then(function(){
            var orig = $(btn).text();
            $(btn).text('✔ Copied!');
            setTimeout(function(){ $(btn).text(orig); }, 1800);
        });
    };

    window.himsOpenWHO = function(code){
        window.open('https://icd.who.int/ct/' + cfg.release + '/icd11_mms/en/' + cfg.release + '#/' + code, '_blank');
    };

    window.himsLoadChildren = function(id){
        var $el = $('#hims-children-' + id);
        $el.html('<div class="hims-loading">Loading…</div>');
        $.post(cfg.ajax_url, { action:'hims_icd11_children', nonce:cfg.nonce, entity_id:id, lang:getLang() }, function(res){
            if(!res.success){ $el.html(renderAlert('error', res.data)); return; }
            var children = res.data.child || [];
            if(!children.length){ $el.html('<em>No children.</em>'); return; }
            var html = '';
            children.forEach(function(uri){
                var cid = entityIdFromUri(uri);
                html += '<span class="hims-tag hims-tag-synonym" style="cursor:pointer;" onclick="himsLoadChildren(\'' + cid + '\')">' + escHtml(cid) + '</span>';
            });
            $el.html(html);
        });
    };

    window.himsLoadPostcoor = function(id){
        var $detail = $('#hims-detail-panel');
        $.post(cfg.ajax_url, { action:'hims_icd11_entity', nonce:cfg.nonce, entity_id:id, lang:getLang() }, function(res){
            if(!res.success) return;
            var pc = res.data.postcoordinationScale || [];
            if(!pc.length){ alert('No postcoordination scales for this entity.'); return; }
            var html = '<div class="hims-detail-section"><div class="hims-detail-section-title">🔗 Postcoordination Scales</div>';
            html += '<table class="hims-postcoor-table"><thead><tr><th>Axis</th><th>Multiple Values</th><th>Required</th></tr></thead><tbody>';
            pc.forEach(function(scale){
                var axis = (scale.axisName||'').split('/').pop();
                var multi = scale.allowMultipleValues || '';
                var req   = scale.requiredPostcoordination || '';
                html += '<tr><td>' + escHtml(axis) + '</td><td>' + escHtml(multi) + '</td><td>' + escHtml(req) + '</td></tr>';
            });
            html += '</tbody></table></div>';
            $detail.find('.hims-detail-body').append(html);
        });
    };

    /* ════════════════════════════════════════
       EXPORT
    ════════════════════════════════════════ */
    function exportResults(type){
        var q = state.query;
        if(!q){ alert('No search results to export.'); return; }
        $.post(cfg.ajax_url, {
            action: 'hims_icd11_export_' + type,
            nonce:  cfg.nonce,
            q:      q,
            lang:   getLang(),
        }, function(res){
            if(!res.success){ alert(res.data); return; }
            var mime = type === 'csv' ? 'text/csv' : 'application/json';
            downloadFile(res.data[type], res.data.filename, mime);
        });
    }

    function downloadFile(content, filename, mime){
        var blob = new Blob([content], { type: mime });
        var url  = URL.createObjectURL(blob);
        var a    = document.createElement('a');
        a.href   = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        setTimeout(function(){ document.body.removeChild(a); URL.revokeObjectURL(url); }, 500);
    }

    /* ════════════════════════════════════════
       UTILITIES
    ════════════════════════════════════════ */
    function renderEmpty(msg){
        return '<div class="hims-empty"><div class="hims-empty-icon">🔍</div><div class="hims-empty-title">No Results</div><div class="hims-empty-desc">' + msg + '</div></div>';
    }

    function renderAlert(type, msg){
        return '<div class="hims-alert hims-alert-' + type + '">⚠ ' + escHtml(msg) + '</div>';
    }

    function getLang(){
        var $wrap = $('.hims-icd11-wrap');
        return $wrap.data('lang') || cfg.lang || 'en';
    }

    function entityIdFromUri(uri){
        if(!uri) return '';
        var parts = uri.replace(/\/$/, '').split('/');
        return parts[parts.length - 1];
    }

    function getLabel(field){
        if(!field) return '';
        if(typeof field === 'string') return field;
        if(field['@value']) return field['@value'];
        if(Array.isArray(field)){
            var en = field.find(function(f){ return f['@language'] === 'en'; });
            return en ? en['@value'] : (field[0] ? field[0]['@value'] || '' : '');
        }
        return '';
    }

    function escHtml(str){
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function dateStr(){
        return new Date().toISOString().slice(0,10);
    }

})(jQuery);
