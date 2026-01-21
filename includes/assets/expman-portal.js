(function($){
  function runInlineScripts(container){
    var scripts = container.querySelectorAll('script');
    scripts.forEach(function(old){
      var s = document.createElement('script');
      for (var i=0;i<old.attributes.length;i++){
        var a = old.attributes[i];
        s.setAttribute(a.name, a.value);
      }
      if (old.src){
        s.src = old.src;
      } else {
        s.text = old.textContent || '';
      }
      old.parentNode.removeChild(old);
      document.head.appendChild(s);
      if (!s.src){
        document.head.removeChild(s);
      }
    });
  }

  function setActive(section){
    document.querySelectorAll('.expman-portal-tab').forEach(function(a){
      if (a.getAttribute('data-expman-section') === section){
        a.classList.add('is-active');
      } else {
        a.classList.remove('is-active');
      }
    });
  }

  function buildUrl(section){
    try{
      var url = new URL(window.location.href);
      url.searchParams.set('expman_section', section);
      return url.toString();
    }catch(e){
      return window.location.href;
    }
  }

  function loadSection(section, push){
    var wrap = document.querySelector('[data-expman-portal="1"]');
    if (!wrap) return;
    var content = wrap.querySelector('[data-expman-portal-content="1"]');
    if (!content) return;

    wrap.classList.add('is-loading');
    setActive(section);

    return $.ajax({
      url: (window.ExpmanPortal && ExpmanPortal.ajaxUrl) ? ExpmanPortal.ajaxUrl : (window.ajaxurl || '/wp-admin/admin-ajax.php'),
      method: 'POST',
      dataType: 'json',
      data: {
        action: 'expman_portal_load_section',
        section: section,
        nonce: (window.ExpmanPortal && ExpmanPortal.nonce) ? ExpmanPortal.nonce : ''
      }
    }).done(function(resp){
      if (!resp || !resp.success){
        content.innerHTML = '<div class="notice notice-error" style="margin:12px 0;"><p>Portal load failed.</p></div>';
        wrap.classList.remove('is-loading');
        return;
      }
      content.innerHTML = resp.data && resp.data.html ? resp.data.html : '';
      runInlineScripts(content);
      wrap.classList.remove('is-loading');

      if (push){
        try{
          window.history.pushState({expman_section: section}, '', buildUrl(section));
        }catch(e){}
      }
      document.dispatchEvent(new CustomEvent('expman:portalLoaded', { detail: { section: section } }));
    }).fail(function(){
      wrap.classList.remove('is-loading');
      content.innerHTML = '<div class="notice notice-error" style="margin:12px 0;"><p>Portal load failed.</p></div>';
    });
  }

  $(document).on('click', '.expman-portal-tab', function(e){
    e.preventDefault();
    var section = this.getAttribute('data-expman-section') || 'dashboard';
    loadSection(section, true);
  });

  window.addEventListener('popstate', function(e){
    var section = (e.state && e.state.expman_section) ? e.state.expman_section : null;
    if (!section){
      try{
        var url = new URL(window.location.href);
        section = url.searchParams.get('expman_section');
      }catch(err){}
    }
    if (section){
      loadSection(section, false);
    }
  });

})(jQuery);
