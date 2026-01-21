(function(){
  function __expmanSslInit(){
  var tbody = document.getElementById('expman-ssl-body');
  if(!tbody){
    // Allow customer search and form logic to work even when the list table is not rendered on this view.
    tbody = document.createElement('tbody');
  }

  // CSS.escape is used for selecting detail rows; add a safe fallback for older environments.
  if(!(window.CSS && CSS.escape)){
    window.CSS = window.CSS || {};
    CSS.escape = CSS.escape || function(s){
      return String(s).replace(/[^a-zA-Z0-9_\-]/g, function(ch){ return '\\' + ch; });
    };
  }

  // Load row data safely from a non-executed JSON script tag (prevents JS syntax errors on special Unicode like U+2028).
  try {
    var __rd = document.getElementById("expman-ssl-rowdata-json");
    if(__rd){ window.expmanSslRowData = JSON.parse(__rd.textContent || "{}"); }
    else { window.expmanSslRowData = window.expmanSslRowData || {}; }
  } catch(__e){
    window.expmanSslRowData = window.expmanSslRowData || {};
  }


  var colFilters = Array.prototype.slice.call(document.querySelectorAll('.expman-ssl-colfilter'));
  var clearBtn = document.getElementById('expman-ssl-clear-filters');
  var groupChecks = Array.prototype.slice.call(document.querySelectorAll('.expman-ssl-groupchk'));

  // Client-side paging controls (instant, no reload)
  var perPageSel = document.getElementById('expman-ssl-per-page');
  var pagePrevBtn = document.getElementById('expman-ssl-page-prev');
  var pageNextBtn = document.getElementById('expman-ssl-page-next');
  var pageInfoEl  = document.getElementById('expman-ssl-page-info');
  var perPage = 25;
  var currentPage = 1;
  if(perPageSel){
    var ppv = parseInt(perPageSel.value, 10);
    if(!isNaN(ppv) && ppv > 0){ perPage = ppv; }
  }

  var table = document.querySelector('.expman-ssl-table');
  var headerRow = table ? table.querySelector('thead tr:first-child') : null;

  var sortCol = 'days_left';
  var sortDir = 1; // 1 asc, -1 desc
  var collapsed = new Set();
  // Record group filter from tabs (main/trash)
  var recordGroupWanted = 'main';
  // Status filter from summary cards (all/nodate/green/yellow/red)
  var statusFilter = 'all';

  function getSummaryRoot(){
    return (recordGroupWanted === 'trash') ? document.getElementById('expman-ssl-summary-trash') : document.getElementById('expman-ssl-summary-main');
  }
  function setStatusFilter(st){
    statusFilter = st || 'all';
    // mark active card
    var root = getSummaryRoot() || document;
    Array.prototype.slice.call(root.querySelectorAll('.expman-summary-card, .expman-summary-meta')).forEach(function(el){
      var s = el.getAttribute('data-expman-status') || 'all';
      if(s === statusFilter){ el.classList.add('active'); el.setAttribute('data-active','1'); }
      else { el.classList.remove('active'); el.setAttribute('data-active','0'); }
    });
  }

  document.addEventListener('click', function(e){
    var btn = e.target.closest('[data-expman-status] button, [data-expman-status].expman-summary-card');
    if(!btn){ return; }
    var host = btn.closest('[data-expman-status]');
    if(!host){ return; }
    var st = host.getAttribute('data-expman-status') || 'all';
    setStatusFilter(st);
    rebuild();
  });

  // Tabs (match M365 behavior): switch instantly without reload
  var tabButtons = Array.prototype.slice.call(document.querySelectorAll('.expman-ssl-tab-btn'));
  var paneList   = document.getElementById('expman-ssl-tab-list');
  var paneSettings = document.getElementById('expman-ssl-tab-settings');
  var paneLog      = document.getElementById('expman-ssl-tab-log');
  var logContainer = document.getElementById('expman-ssl-log-container');

  function showPane(which){
    if(paneList){ paneList.style.display = (which === 'list') ? 'block' : 'none'; }
    if(paneSettings){ paneSettings.style.display = (which === 'settings') ? 'block' : 'none'; }
    if(paneLog){ paneLog.style.display = (which === 'log') ? 'block' : 'none'; }
  }

  function updateTabButtons(active){
    tabButtons.forEach(function(b){
      var t = b.getAttribute('data-tab') || '';
      if(t === active){ b.classList.add('button-primary','is-active'); }
      else { b.classList.remove('button-primary','is-active'); }
    });
  }

  function toggleSummary(){
    var mainWrap = document.getElementById('expman-ssl-summary-main');
    var trashWrap = document.getElementById('expman-ssl-summary-trash');
    if(mainWrap){ mainWrap.style.display = (recordGroupWanted === 'trash') ? 'none' : 'block'; }
    if(trashWrap){ trashWrap.style.display = (recordGroupWanted === 'trash') ? 'block' : 'none'; }
  }

  function ajaxurl(){
    return (window.expmanSslCfg && window.expmanSslCfg.ajaxurl) ? window.expmanSslCfg.ajaxurl : (window.ajaxurl || '');
  }

  function loadLogsOnce(){
    if(!logContainer){ return; }
    if(logContainer.getAttribute('data-loaded') === '1'){ return; }
    logContainer.setAttribute('data-loaded','1');
    logContainer.innerHTML = '<div style="padding:12px;">טוען לוג...</div>';
    var fd = new FormData();
    fd.append('action','expman_ssl_render_logs');
    fd.append('nonce', (window.expmanSslCfg && window.expmanSslCfg.log_nonce) ? window.expmanSslCfg.log_nonce : '');
    fetch(ajaxurl(), { method:'POST', credentials:'same-origin', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if(j && j.success && j.data && typeof j.data.html === 'string'){
          logContainer.innerHTML = j.data.html;
        } else {
          logContainer.innerHTML = '<div style="padding:12px;">שגיאה בטעינת לוג</div>';
        }
      })
      .catch(function(){
        logContainer.innerHTML = '<div style="padding:12px;">שגיאה בטעינת לוג</div>';
      });
  }

  function setActiveTab(tab){
    tab = tab || 'main';
    // Persist selection
    try{ localStorage.setItem('expman_ssl_active_tab', tab); }catch(e){}
    updateTabButtons(tab);

    if(tab === 'settings'){
      showPane('settings');
      return;
    }
    if(tab === 'log'){
      showPane('log');
      loadLogsOnce();
      return;
    }

    // main / trash => list pane
    showPane('list');
    recordGroupWanted = (tab === 'trash') ? 'trash' : 'main';
    toggleSummary();
    // Reset visual filter to all when switching tab
    setStatusFilter('all');
    currentPage = 1;
    rebuild();
  }

  // Init active tab: querystring -> saved -> server default
  function getQueryVar(k){
    try{ var u = new URL(window.location.href); return u.searchParams.get(k); }catch(e){ return null; }
  }
  var qTab = getQueryVar('expman_tab');
  var savedTab = null;
  try{ savedTab = localStorage.getItem('expman_ssl_active_tab'); }catch(e){}
  var serverTab = (window.expmanSslCfg && window.expmanSslCfg.initial_tab) ? window.expmanSslCfg.initial_tab : null;
  var initialTab = qTab || savedTab || serverTab || 'main';
  tabButtons.forEach(function(b){
    b.addEventListener('click', function(){ setActiveTab(this.getAttribute('data-tab')); });
  });
  var __expmanSslInitialTab = initialTab;


  function groupColsCurrent(){
    var cols = [];
    groupChecks.forEach(function(c){ if(c.checked){ cols.push(c.getAttribute('data-col')); } });
    return cols;
  }

  function ensureHeaderControls(){
    if(!headerRow){ return; }
    Array.prototype.slice.call(headerRow.querySelectorAll('th')).forEach(function(th, i){
      if(i===0){ return; }
      var input = th.querySelector('input.expman-ssl-groupchk');
      if(!input){ return; }
      var col = input.getAttribute('data-col') || '';
      if(!col){ return; }

      th.classList.add('expman-sortable');

      if(th.querySelector('.expman-sort-ind')){ return; }
      var ind = document.createElement('span');
      ind.className = 'expman-sort-ind';
      ind.textContent = '';
      // Insert indicator before label content
      var label = th.querySelector('label.expman-group-toggle');
      if(label){ label.insertBefore(ind, label.firstChild); }

      if(th.querySelector('.expman-col-controls')){ return; }
      var controls = document.createElement('span');
      controls.className = 'expman-col-controls';
      controls.setAttribute('data-col', col);
      controls.innerHTML = '<button type="button" class="expman-col-btn expman-col-expand" title="הרחב הכל">+</button>' +
                           '<button type="button" class="expman-col-btn expman-col-collapse" title="כווץ הכל">-</button>';
      if(label){ label.appendChild(controls); }
    });
  }

  function updateSortIndicators(){
    if(!headerRow){ return; }
    Array.prototype.slice.call(headerRow.querySelectorAll('th')).forEach(function(th){
      var input = th.querySelector('input.expman-ssl-groupchk');
      var col = input ? (input.getAttribute('data-col')||'') : '';
      var ind = th.querySelector('.expman-sort-ind');
      if(!ind){ return; }
      if(!sortCol || sortCol !== col){ ind.textContent = ''; return; }
      ind.textContent = (sortDir === 1 ? '▲' : '▼');
    });
  }

  ensureHeaderControls();

  // Sorting on header click (ignore clicks on inputs/buttons/links)
  if(headerRow){
    headerRow.addEventListener('click', function(e){
      var t = e.target;
      if(t.closest('input') || t.closest('button') || t.closest('a')){ return; }
      var th = t.closest('th');
      if(!th || th.cellIndex === 0){ return; }
      var input = th.querySelector('input.expman-ssl-groupchk');
      var col = input ? input.getAttribute('data-col') : null;
      if(!col){ return; }
      if(sortCol === col){ sortDir = -sortDir; } else { sortCol = col; sortDir = 1; }
      rebuild();
    });
  }

  // Expand/collapse all groups for a given column level
  function expandAllForCol(col){
    var cols = groupColsCurrent();
    var level = cols.indexOf(col);
    if(level < 0){ return; }
    Array.prototype.slice.call(tbody.querySelectorAll('tr.expman-group-header[data-level="'+level+'"][data-gcol="'+col+'"]')).forEach(function(tr){
      collapsed.delete(tr.getAttribute('data-gpath') || '');
    });
    applyCollapse();
    updateGroupSigns();
  }

  function collapseAllForCol(col){
    var cols = groupColsCurrent();
    var level = cols.indexOf(col);
    if(level < 0){ return; }
    Array.prototype.slice.call(tbody.querySelectorAll('tr.expman-group-header[data-level="'+level+'"][data-gcol="'+col+'"]')).forEach(function(tr){
      collapsed.add(tr.getAttribute('data-gpath') || '');
    });
    applyCollapse();
    updateGroupSigns();
  }

  document.addEventListener('click', function(e){
    var btn = e.target.closest('.expman-col-btn');
    if(!btn){ return; }
    e.preventDefault();
    e.stopPropagation();
    var th = btn.closest('th');
    if(!th){ return; }
    var input = th.querySelector('input.expman-ssl-groupchk');
    var col = input ? input.getAttribute('data-col') : null;
    if(!col){ return; }
    if(btn.classList.contains('expman-col-expand')){ expandAllForCol(col); }
    if(btn.classList.contains('expman-col-collapse')){ collapseAllForCol(col); }
  });



  function norm(v){ return (v==null? '' : String(v)).trim().toLowerCase(); }
  function val(tr, col){ return tr.getAttribute('data-'+col) || ''; }

  // Build base pairs (main row + detail row)
  var basePairs = [];
  Array.prototype.slice.call(tbody.querySelectorAll('tr.expman-ssl-row')).forEach(function(r){
    var id = r.getAttribute('data-row-id') || '';
    var detail = tbody.querySelector('tr.expman-ssl-detail[data-detail-for=\"'+ CSS.escape(id) +'\"]');
    basePairs.push({id:id, row:r, detail:detail});
  });

  function removeGroupHeaders(){
    Array.prototype.slice.call(tbody.querySelectorAll('tr.expman-group-header')).forEach(function(x){ x.remove(); });
  }


  function updateGroupSigns(){
    Array.prototype.slice.call(tbody.querySelectorAll('tr.expman-group-header')).forEach(function(tr){
      var path = tr.getAttribute('data-gpath') || '';
      var btn = tr.querySelector('.expman-group-btn');
      if(!btn){ return; }
      btn.textContent = collapsed.has(path) ? '+' : '-';
    });
  }

  function applyCollapse(){
    var stack = [];
    Array.prototype.slice.call(tbody.children).forEach(function(tr){
      if(tr.classList.contains('expman-group-header')){
        var level = parseInt(tr.getAttribute('data-level') || '0', 10);
        var path = tr.getAttribute('data-gpath') || '';
        stack = stack.slice(0, level);
        // determine if any parent is collapsed
        var parentCollapsed = false;
        for(var i=0;i<stack.length;i++){
          if(stack[i] && stack[i].collapsed){ parentCollapsed = true; break; }
        }
        var isCollapsed = collapsed.has(path);
        stack[level] = {path:path, collapsed:isCollapsed};
        tr.style.display = parentCollapsed ? 'none' : '';
      } else if(tr.classList.contains('expman-ssl-row') || tr.classList.contains('expman-ssl-detail')){
        var full = tr.getAttribute('data-gpath') || '';
        var hidden = false;
        for(var i=0;i<stack.length;i++){
          var s = stack[i];
          if(s && s.collapsed && full && full.indexOf(s.path) === 0){
            hidden = true; break;
          }
        }
        if(hidden){
          tr.style.display = 'none';
        } else {
          if(tr.classList.contains('expman-ssl-row')){
            tr.style.display = '';
          } else if(tr.classList.contains('expman-ssl-detail')){
            var isOpen = tr.getAttribute('data-open') === '1';
            tr.style.display = isOpen ? 'table-row' : 'none';
          }
        }
      }
    });
  }

  function passesFilters(pair){
    var r = pair.row;
    // Tabs filter (main/trash) - applies before other filters
    var rg = (r.getAttribute('data-record-group') || 'main').toLowerCase();
    if(rg !== recordGroupWanted){ return false; }
    if(statusFilter && statusFilter !== 'all'){
      var st = (r.getAttribute('data-status') || '').toLowerCase();
      if(st !== statusFilter){ return false; }
    }
    for(var i=0;i<colFilters.length;i++){
      var f = colFilters[i];
      var col = f.getAttribute('data-col');
      var q = norm(f.value);
      if(!q){ continue; }
      var rv = norm(val(r, col));
      if(col === 'expiry_date'){
        rv = norm((val(r,'expiry_display')||'') + ' ' + (val(r,'expiry_date')||''));
      }
      if(rv.indexOf(q) === -1){ return false; }
    }
    return true;
  }

  function getGroupCols(){
    var cols = [];
    groupChecks.forEach(function(c){
      if(c.checked){
        cols.push(c.getAttribute('data-col'));
      }
    });
    return cols;
  }

  function comparePairs(a,b, cols){
    for(var i=0;i<cols.length;i++){
      var c = cols[i];
      var av = val(a.row, c);
      var bv = val(b.row, c);

      var dir = (sortCol && c === sortCol) ? sortDir : 1;

      // numeric compare for days_left if possible
      if(c === 'days_left'){
        var an = parseInt(av,10); var bn = parseInt(bv,10);
        if(isNaN(an) && isNaN(bn)){
          // fall through
        } else if(isNaN(an)){
          return 1; // no date last
        } else if(isNaN(bn)){
          return -1;
        } else if(an !== bn){
          return (an - bn) * dir;
        }
      }

      // date compare for expiry_date if formatted as YYYY-MM-DD
      if(c === 'expiry_date'){
        var ad = Date.parse(av); var bd = Date.parse(bv);
        if(isNaN(ad) && isNaN(bd)){
          // fall through
        } else if(isNaN(ad)){
          return 1;
        } else if(isNaN(bd)){
          return -1;
        } else if(ad !== bd){
          return (ad - bd) * dir;
        }
      }

      var cmp = String(av).localeCompare(String(bv), 'he', {numeric:true, sensitivity:'base'});
      if(cmp !== 0){ return cmp * dir; }
    }
    return 0;
  }

  function rebuild(){
    removeGroupHeaders();

    var visible = [];
    basePairs.forEach(function(p){
      if(passesFilters(p)){
        p.row.style.display = '';
        if(p.detail){ p.detail.style.display = 'none'; p.detail.setAttribute('data-open','0'); }
        visible.push(p);
      } else {
        p.row.style.display = 'none';
        if(p.detail){ p.detail.style.display = 'none'; p.detail.setAttribute('data-open','0'); }
      }
    });

    // Empty state
    if(visible.length === 0){
      tbody.innerHTML = '<tr><td colspan=\"9\" style=\"padding:14px;\">אין נתונים</td></tr>';
      return;
    }

    var groupCols = getGroupCols();
    var list = visible.slice();

    // if grouping is off, clear collapse state
    if(!groupCols.length){
      collapsed.clear();
    }

    // sort: group columns first, then selected sort column
    var sortCols = groupCols.slice();
    if(sortCol && sortCols.indexOf(sortCol) === -1){
      sortCols.push(sortCol);
    }
    if(sortCols.length){
      list.sort(function(a,b){ return comparePairs(a,b, sortCols); });
    }

    // Client-side paging (works on the *filtered* list, across the whole DB)
    var totalItems = list.length;
    var totalPages = Math.max(1, Math.ceil(totalItems / Math.max(1, perPage)));
    if(currentPage > totalPages){ currentPage = totalPages; }
    if(currentPage < 1){ currentPage = 1; }
    var start = (currentPage - 1) * perPage;
    var end = start + perPage;
    var pageList = list.slice(start, end);

    if(pageInfoEl){
      pageInfoEl.textContent = 'עמוד ' + currentPage + ' מתוך ' + totalPages + ' (סה"כ ' + totalItems + ')';
    }
    if(pagePrevBtn){ pagePrevBtn.disabled = (currentPage <= 1); }
    if(pageNextBtn){ pageNextBtn.disabled = (currentPage >= totalPages); }

    var frag = document.createDocumentFragment();
    var lastKeys = [];

    pageList.forEach(function(p){
      if(groupCols.length){
        for(var level=0; level<groupCols.length; level++){
          var col = groupCols[level];
          var key = norm(val(p.row, col));
          if(lastKeys[level] !== key){
            lastKeys = lastKeys.slice(0, level);
            var pathParts = [];
            for(var x=0; x<=level; x++){
              var ccol = groupCols[x];
              var raw = val(p.row, ccol) || '(ריק)';
              pathParts.push(ccol + '=' + norm(raw));
            }
            var gpath = pathParts.join('||');

            var tr = document.createElement('tr');
            tr.className = 'expman-group-header';
            tr.setAttribute('data-level', String(level));
            tr.setAttribute('data-gcol', col);
            tr.setAttribute('data-gpath', gpath);

            var td = document.createElement('td');
            td.colSpan = 9;

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'expman-group-btn';
            btn.setAttribute('data-gpath', gpath);
            btn.textContent = collapsed.has(gpath) ? '+' : '-';

            var title = document.createElement('span');
            title.className = 'expman-group-title';
            title.textContent = val(p.row, col) || '(ריק)';

            td.appendChild(btn);
            td.appendChild(title);
            tr.appendChild(td);
            frag.appendChild(tr);

            lastKeys[level] = key;
          }
        }
      }
      // set full group path for collapse logic
      if(groupCols.length){
        var fp = [];
        for(var gi=0; gi<groupCols.length; gi++){
          var gcol = groupCols[gi];
          var graw = val(p.row, gcol) || '(ריק)';
          fp.push(gcol + '=' + norm(graw));
        }
        var fullPath = fp.join('||');
        p.row.setAttribute('data-gpath', fullPath);
        if(p.detail){ p.detail.setAttribute('data-gpath', fullPath); }
      } else {
        p.row.removeAttribute('data-gpath');
        if(p.detail){ p.detail.removeAttribute('data-gpath'); }
      }

      frag.appendChild(p.row);
      if(p.detail){ frag.appendChild(p.detail); }
    });

    tbody.innerHTML = '';
    tbody.appendChild(frag);

    applyCollapse();
    updateGroupSigns();
    updateSortIndicators();

  }

  // Detail toggle (event delegation)
  tbody.addEventListener('click', function(e){
    var t = e.target;

    // Group header collapse/expand
    var gb = t ? t.closest('.expman-group-btn') : null;
    if(gb){
      e.preventDefault();
      e.stopPropagation();
      var path = gb.getAttribute('data-gpath') || '';
      if(!path){ return; }
      if(collapsed.has(path)){ collapsed.delete(path); } else { collapsed.add(path); }
      applyCollapse();
      updateGroupSigns();
      return;
    }

    if(!t){ return; }
    if(t.closest('a,button,input,select,textarea,label')){ return; }
    var tr = t.closest('tr.expman-ssl-row');
    if(!tr){ return; }
    var id = tr.getAttribute('data-row-id') || '';
    if(!id){ return; }
    var detail = tbody.querySelector('tr.expman-ssl-detail[data-detail-for=\"'+ CSS.escape(id) +'\"]');
    if(!detail){ return; }
    var openNow = (detail.style.display === 'none' || detail.style.display === '');
    detail.setAttribute('data-open', openNow ? '1' : '0');
    detail.style.display = openNow ? 'table-row' : 'none';
  });

  // Wire filters and grouping
  colFilters.forEach(function(f){
    f.addEventListener('input', function(){
      currentPage = 1;
      rebuild();
    });
  });
  groupChecks.forEach(function(c){
    c.addEventListener('change', function(){
      currentPage = 1;
      rebuild();
    });
  });
  if(clearBtn){
    clearBtn.addEventListener('click', function(){
      colFilters.forEach(function(f){ f.value=''; });
      groupChecks.forEach(function(c){ c.checked=false; });
      sortCol = null; sortDir = 1;
      collapsed.clear();
      currentPage = 1;
      rebuild();
    });
  }

  // Paging controls
  if(perPageSel){
    perPageSel.addEventListener('change', function(){
      var v = parseInt(perPageSel.value, 10);
      if(!isNaN(v) && v > 0){ perPage = v; }
      currentPage = 1;
      rebuild();
    });
  }
  if(pagePrevBtn){
    pagePrevBtn.addEventListener('click', function(){
      if(currentPage > 1){ currentPage -= 1; rebuild(); }
    });
  }
  if(pageNextBtn){
    pageNextBtn.addEventListener('click', function(){
      currentPage += 1;
      rebuild();
    });
  }

  // Modal add/edit
  var modal = document.getElementById('expman-ssl-modal');
  var modalClose = document.getElementById('expman-ssl-modal-close');
  var cancelBtn = document.getElementById('expman-ssl-form-cancel');
  var modalDelete = document.getElementById('expman-ssl-modal-delete');
  var addBtn = document.getElementById('expman-ssl-add-new');
  var form = document.getElementById('expman-ssl-modal-form');

  // Move the form to the top of the page (under the top controls), so Add/Edit always opens at the top
  var topControls = document.getElementById('expman-ssl-top-controls');
  if(topControls && modal){
    // Insert right after the top controls
    if(topControls.parentNode){
      topControls.parentNode.insertBefore(modal, topControls.nextSibling);
    }
  }

  // Customer search (copy the proven Firewalls model: admin-ajax.php?action=expman_customer_search&nonce=...)
  var custSearchEl = document.getElementById('expman-ssl-customer-search');
  var custNameEl   = document.getElementById('expman-ssl-customer-name');
  var custNumEl    = document.getElementById('expman-ssl-customer-number');
  var custDD       = document.getElementById('expman-ssl-cust-dd-inline');

  function cfgAjax(){
    return (window.expmanSslCfg && window.expmanSslCfg.ajaxurl) ? window.expmanSslCfg.ajaxurl : (window.ajaxurl || '');
  }
  function cfgNonce(){
    return (window.expmanSslCfg && window.expmanSslCfg.customer_nonce) ? window.expmanSslCfg.customer_nonce : '';
  }

  function ensureCustDD(){
    if(custDD){ return custDD; }
    custDD = document.createElement('div');
    custDD.id = 'expman-ssl-cust-dd-inline';
    custDD.className = 'expman-ssl-cust-dd';
    custDD.style.display = 'none';
    if(custSearchEl && custSearchEl.parentNode){ custSearchEl.parentNode.appendChild(custDD); }
    else { document.body.appendChild(custDD); }
    document.addEventListener('click', function(ev){
      var t = ev.target;
      if(!custDD){ return; }
      if(t === custDD || custDD.contains(t)){ return; }
      if(custSearchEl && (t === custSearchEl || custSearchEl.contains(t))){ return; }
      hideCustDD();
    });
    return custDD;
  }

  function hideCustDD(){
    if(!custDD){ return; }
    custDD.style.display = 'none';
    custDD.innerHTML = '';
  }

  function renderCustomers(list){
    var dd = ensureCustDD();
    dd.innerHTML = '';
    if(!list || !list.length){ dd.style.display='none'; return; }
    dd.style.display = 'block';
    var wrap = document.createElement('div');
    wrap.style.border = '1px solid #dcdcde';
    wrap.style.borderRadius = '6px';
    wrap.style.background = '#fff';
    wrap.style.maxWidth = '520px';
    list.forEach(function(it){
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = String(it.customer_number||'') + ' - ' + String(it.customer_name||'');
      btn.style.display = 'block';
      btn.style.width = '100%';
      btn.style.textAlign = 'right';
      btn.style.padding = '6px 10px';
      btn.style.border = '0';
      btn.style.borderBottom = '1px solid #eee';
      btn.style.background = 'transparent';
      btn.addEventListener('click', function(){
        var num = String(it.customer_number||'');
        var name = String(it.customer_name||'');
        if(custNumEl){ custNumEl.value = num; }
        if(custNameEl){ custNameEl.value = name; }
        if(custSearchEl){ custSearchEl.value = (num ? (num + ' - ') : '') + name; }
        hideCustDD();
      });
      wrap.appendChild(btn);
    });
    dd.appendChild(wrap);
  }

  // Performance: cancel in-flight searches and cache recent queries
  var custAbort = null;
  var custCache = Object.create(null);

  async function searchCustomers(q){
    var ajax = cfgAjax();
    var nonce = cfgNonce();
    if(!ajax || !nonce){ return; }
    var key = q.toLowerCase();
    if(custCache[key]){ renderCustomers(custCache[key]); return; }
    if(custAbort){ try{ custAbort.abort(); }catch(e){} }
    custAbort = (window.AbortController ? new AbortController() : null);
    var url = ajax + '?action=expman_customer_search&nonce=' + encodeURIComponent(nonce) + '&q=' + encodeURIComponent(q);
    var res = await fetch(url, {credentials:'same-origin', signal: custAbort ? custAbort.signal : undefined});
    if(!res.ok){ return; }
    var data = await res.json();
    var items = (data && data.items) ? data.items : [];
    // Keep cache bounded
    custCache[key] = items;
    var keys = Object.keys(custCache);
    if(keys.length > 25){ delete custCache[keys[0]]; }
    renderCustomers(items);
  }

  function bindCustomerInput(el){
    if(!el){ return; }
    var t = null;
    el.addEventListener('input', function(){
      var q = (el.value||'').trim();
      if(q.length < 2){ hideCustDD(); return; }
      clearTimeout(t);
      t = setTimeout(function(){ searchCustomers(q); }, 250);
    });
    el.addEventListener('focus', function(){
      var q = (el.value||'').trim();
      if(q.length >= 2){ searchCustomers(q); }
    });
  }

  bindCustomerInput(custSearchEl);
  bindCustomerInput(custNumEl);

  var fillBtn = document.getElementById('expman-ssl-fill-admin-email');
  if(fillBtn){
    fillBtn.addEventListener('click', function(){
      var em = form ? form.querySelector('[name="admin_email"]') : null;
      if(em){ em.value = 'admin@macomp.co.il'; }
    });
  }

  // Manual expiry helper: +1 year
  var manualChk = form ? form.querySelector('input[name="manual_mode"]') : null;
  var addYearBtn = document.getElementById('expman-ssl-add-year');
  var expiryInput = document.getElementById('expman-ssl-expiry-date');

  function toggleAddYear(){
    if(!addYearBtn){ return; }
    if(manualChk && manualChk.checked){
      addYearBtn.style.display = '';
    } else {
      addYearBtn.style.display = 'none';
    }
  }

  if(manualChk){
    manualChk.addEventListener('change', toggleAddYear);
  }

  if(addYearBtn && expiryInput){
    addYearBtn.addEventListener('click', function(){
      var v = (expiryInput.value||'').trim();
      if(!v){ return; }
      var parts = v.split(/[-\/]/).filter(Boolean);
      if(parts.length < 3){ return; }
      var y,m,d;
      var outFmt = 'ymd';
      if(parts[0].length === 4){
        y = parseInt(parts[0],10); m = parseInt(parts[1],10); d = parseInt(parts[2],10);
        outFmt = 'ymd';
      } else {
        d = parseInt(parts[0],10); m = parseInt(parts[1],10); y = parseInt(parts[2],10);
        outFmt = 'dmy';
      }
      if(!y || !m || !d){ return; }
      var dt = new Date(Date.UTC(y, m-1, d));
      if(isNaN(dt.getTime())){ return; }
      dt.setUTCFullYear(dt.getUTCFullYear() + 1);
      var yyyy = dt.getUTCFullYear();
      var mm = String(dt.getUTCMonth()+1).padStart(2,'0');
      var dd = String(dt.getUTCDate()).padStart(2,'0');
      expiryInput.value = (outFmt === 'dmy') ? (dd+'-'+mm+'-'+yyyy) : (yyyy+'-'+mm+'-'+dd);
    });
  }


  function openModal(mode, rowId){
  if(!modal || !form){ return; }
  var title = modal.querySelector('[data-expman-modal-title]');
  if(title){ title.textContent = (mode==='edit' ? 'עריכת רשומה' : 'הוספת רשומה חדשה'); }

  function elByName(name){
    return form.querySelector('[name="'+name+'"]');
  }
  function setVal(name, val){
    var el = elByName(name);
    if(!el){ return; }
    if(el.type === 'checkbox'){
      el.checked = (String(val) === '1' || val === 1 || val === true);
    } else if(el.type === 'file'){
      // cannot set file inputs programmatically
    } else {
      el.value = (val == null ? '' : String(val));
    }
  }

  form.reset();
  setVal('row_id','');
  setVal('post_id','');
  if(modalDelete){ modalDelete.style.display='none'; modalDelete.onclick = null; }

  if(mode==='edit' && rowId){
    var data = (window.expmanSslRowData && window.expmanSslRowData[rowId]) ? window.expmanSslRowData[rowId] : null;
    if(data){
      setVal('row_id', rowId);
      setVal('post_id', data.post_id || '');

      if(modalDelete){
        modalDelete.style.display='';
        modalDelete.onclick = function(){
          if(!confirm('להעביר לסל המחזור?')){ return; }
          var pid = (data.post_id || '');
          var url = form.getAttribute('action') + '?action=expman_ssl_trash&row_id=' + encodeURIComponent(rowId) +
                    '&post_id=' + encodeURIComponent(pid) +
                    '&redirect_to=' + encodeURIComponent(window.location.href);
          window.location.href = url;
        };
      }

      setVal('client_name', data.client_name || '');
      setVal('customer_number_snapshot', data.customer_number_snapshot || '');
      setVal('site_url', data.site_url || '');
      setVal('common_name', data.common_name || '');
      setVal('issuer_name', data.issuer_name || '');
      setVal('cert_type', data.cert_type || '');
      setVal('guide_url', data.guide_url || '');
      setVal('management_owner', data.management_owner || '');
      setVal('agent_token', data.agent_token || '');
      setVal('admin_email', data.admin_email || '');
      setVal('admin_contact_name', data.admin_contact_name || '');
      setVal('notes', data.notes || '');
      setVal('temporary_note', data.temporary_note || '');

      setVal('temporary_enabled', data.temporary_enabled || 0);
      setVal('manual_mode', data.manual_mode || 0);
      setVal('allow_duplicate_site', data.allow_duplicate_site || 0);
      setVal('follow_up', data.follow_up || 0);

      if(data.expiry_ts){
        var d = new Date(parseInt(data.expiry_ts,10)*1000);
        if(!isNaN(d.getTime())){
          var yyyy=d.getUTCFullYear();
          var mm=String(d.getUTCMonth()+1).padStart(2,'0');
          var dd=String(d.getUTCDate()).padStart(2,'0');
          // dd-mm-yyyy (matches UI and parsing)
          setVal('expiry_date', dd+'-'+mm+'-'+yyyy);
        }
      }
    }
  }

  if(typeof toggleAddYear==='function'){ toggleAddYear(); }

    modal.style.display = 'block';
  try{ modal.scrollIntoView({behavior:'smooth', block:'start'}); }catch(e){ window.scrollTo(0,0); }
  if(modal && modal.scrollIntoView){ try{ modal.scrollIntoView({behavior:'smooth', block:'start'}); }catch(e){ modal.scrollIntoView(true); } }

  // After clicking "הוספה", focus customer search immediately
  if(mode==="add"){
    setTimeout(function(){
      if(custSearchEl){
        try{ custSearchEl.focus(); if(custSearchEl.select){ custSearchEl.select(); } }catch(e){}
      }
    }, 50);
  }
}

// Expose stable public functions for inline onclick handlers (works even if admin injects HTML after listeners are bound).
window.expmanSslOpenAdd = function(){ openModal('add'); };
window.expmanSslOpenEdit = function(id){ openModal('edit', id); };
  function closeModal(){
    if(modal){ modal.style.display='none'; }
  }

  if(addBtn){
    addBtn.addEventListener('click', function(){ openModal('add'); });
  }
  tbody.addEventListener('click', function(e){
    var btn = e.target.closest('.expman-ssl-edit');
    if(!btn){ return; }
    e.preventDefault();
    var rowId = btn.getAttribute('data-row-id');
    openModal('edit', rowId);
  });

  if(modalClose){ modalClose.addEventListener('click', closeModal); }
  if(cancelBtn){ cancelBtn.addEventListener('click', closeModal); }
  setStatusFilter('all');
  rebuild();
  updateSortIndicators();
  // Apply initial tab after full init (basePairs + rebuild are ready)
  if(typeof __expmanSslInitialTab !== 'undefined'){
    setTimeout(function(){ setActiveTab(__expmanSslInitialTab); }, 0);
  }
  }
  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', __expmanSslInit);
  } else {
    __expmanSslInit();
  }
})();