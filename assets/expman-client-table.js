(function(){
  function initWrap(wrap){
    if(!wrap){ return; }
    var rows = Array.prototype.slice.call(wrap.querySelectorAll('.expman-row'));
    var filterInput = wrap.querySelector('.expman-filter');
    var perPageSelect = wrap.querySelector('.expman-per-page');
    var pager = wrap.querySelector('.expman-pager');
    var currentPage = 1;
    var perPage = 25;

    if(perPageSelect){
      var pp = parseInt(perPageSelect.value, 10);
      if(!isNaN(pp) && pp > 0){ perPage = pp; }
    }

    var rowData = rows.map(function(row){
      return {
        row: row,
        id: row.getAttribute('data-id') || '',
        status: (row.getAttribute('data-status') || '').toLowerCase(),
        any: (row.getAttribute('data-any') || '').toLowerCase(),
        fields: row.dataset
      };
    });

    function matchesFilter(item, query, field){
      if(!query){ return true; }
      var hay = '';
      if(field && field !== 'any'){
        hay = (item.row.getAttribute('data-' + field) || '').toLowerCase();
      } else {
        hay = item.any || '';
      }
      return hay.indexOf(query) !== -1;
    }

    function applyFilters(){
      var query = '';
      var field = 'any';
      if(filterInput){
        query = (filterInput.value || '').trim().toLowerCase();
        field = filterInput.getAttribute('data-field') || 'any';
      }
      var statusFilter = (wrap.dataset.expmanStatusFilter || '').toLowerCase();
      var filtered = rowData.filter(function(item){
        if(statusFilter && statusFilter !== 'all'){
          if(item.status !== statusFilter){ return false; }
        }
        return matchesFilter(item, query, field);
      });
      return filtered;
    }

    function showRelatedRows(id, show){
      if(!id){ return; }
      var related = wrap.querySelectorAll('[data-for="' + CSS.escape(id) + '"],[data-detail-for="' + CSS.escape(id) + '"]');
      Array.prototype.slice.call(related).forEach(function(el){
        el.style.display = show ? '' : 'none';
      });
    }

    function renderPage(page){
      var filtered = applyFilters();
      var total = filtered.length;
      var pages = Math.max(1, Math.ceil(total / perPage));
      if(page < 1){ page = 1; }
      if(page > pages){ page = pages; }
      currentPage = page;

      rows.forEach(function(row){
        row.style.display = 'none';
        showRelatedRows(row.getAttribute('data-id') || '', false);
      });

      var start = (page - 1) * perPage;
      var end = start + perPage;
      filtered.slice(start, end).forEach(function(item){
        item.row.style.display = '';
        showRelatedRows(item.id, true);
      });

      renderPager(page, pages, total);
    }

    function renderPager(page, pages, total){
      if(!pager){ return; }
      pager.innerHTML = '';
      var prev = document.createElement('button');
      prev.type = 'button';
      prev.className = 'button expman-pager-prev';
      prev.textContent = 'Prev';
      prev.disabled = page <= 1;
      prev.addEventListener('click', function(){ renderPage(page - 1); dispatchUpdate(); });

      var next = document.createElement('button');
      next.type = 'button';
      next.className = 'button expman-pager-next';
      next.textContent = 'Next';
      next.disabled = page >= pages;
      next.addEventListener('click', function(){ renderPage(page + 1); dispatchUpdate(); });

      var info = document.createElement('span');
      info.className = 'expman-pager-info';
      info.textContent = 'עמוד ' + page + ' מתוך ' + pages + ' (' + total + ')';

      pager.appendChild(prev);
      pager.appendChild(info);
      pager.appendChild(next);
    }

    function dispatchUpdate(){
      document.dispatchEvent(new CustomEvent('expman:tableUpdate', { detail: { wrap: wrap } }));
    }

    if(filterInput){
      filterInput.addEventListener('input', function(){
        renderPage(1);
        dispatchUpdate();
      });
    }

    if(perPageSelect){
      perPageSelect.addEventListener('change', function(){
        var val = parseInt(perPageSelect.value, 10);
        if(!isNaN(val) && val > 0){ perPage = val; }
        renderPage(1);
        dispatchUpdate();
      });
    }

    wrap.addEventListener('submit', function(e){
      e.preventDefault();
    });

    renderPage(currentPage);
  }

  document.addEventListener('DOMContentLoaded', function(){
    if(!(window.CSS && CSS.escape)){
      window.CSS = window.CSS || {};
      CSS.escape = CSS.escape || function(s){
        return String(s).replace(/[^a-zA-Z0-9_\-]/g, function(ch){ return '\\' + ch; });
      };
    }
    Array.prototype.slice.call(document.querySelectorAll('.expman-client-wrap')).forEach(initWrap);
  });
})();
