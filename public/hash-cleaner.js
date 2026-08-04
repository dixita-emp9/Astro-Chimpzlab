(function() {
  // Map of known section paths → element IDs on the home page
  var PATH_MAP = {
    '/services': 'services-section',
    '/contact': 'booking-form',
    '/booking-form': 'booking-form'
  };

  // Set of section IDs that are on the home page (for hash link detection)
  var HOME_SECTION_IDS = {'services-section': 1, 'booking-form': 1};

  function scrollToId(id) {
    var el = document.getElementById(id);
    if (!el) return;
    if (window.lenis && typeof window.lenis.scrollTo === 'function') {
      window.lenis.scrollTo(el);
    } else {
      el.scrollIntoView({ behavior: 'smooth' });
    }
  }

  // On page load: restore saved navigation target from another page
  var savedTarget = sessionStorage.getItem('scrollSection');
  var savedPath = sessionStorage.getItem('scrollPath');
  if (savedTarget) {
    sessionStorage.removeItem('scrollSection');
    sessionStorage.removeItem('scrollPath');
    if (document.getElementById(savedTarget)) {
      if (savedPath) history.replaceState(null, '', savedPath);
      setTimeout(function() { scrollToId(savedTarget); }, 300);
    }
  } else {
    // Check if current path maps to a section
    var path = window.location.pathname.replace(/\/+$/, '') || '/';
    var targetId = PATH_MAP[path];
    if (targetId && document.getElementById(targetId)) {
      setTimeout(function() { scrollToId(targetId); }, 300);
    } else if (window.location.hash) {
      var id = window.location.hash.slice(1);
      if (document.getElementById(id)) {
        setTimeout(function() {
          scrollToId(id);
          history.replaceState(null, '', window.location.pathname + window.location.search);
        }, 300);
      }
    }
  }

  // Handle hash links on the current page (clean URL)
  document.addEventListener('click', function(e) {
    var a = e.target.closest('a[href^="#"]');
    if (a && a.getAttribute('href') !== '#') {
      var id = a.getAttribute('href').slice(1);
      if (document.getElementById(id)) {
        e.preventDefault();
        scrollToId(id);
        history.replaceState(null, '', window.location.pathname + window.location.search);
      }
    }
  });

  // Handle links to home-page sections (e.g., /services, /booking-form, index.html#services-section)
  document.addEventListener('click', function(e) {
    var a = e.target.closest('a');
    if (!a) return;
    var href = a.getAttribute('href');
    if (!href) return;

    // Check if link targets a known home-page section (by path or hash)
    var absolute = href.replace(/\/+$/, '') || '/';
    var sections = PATH_MAP[absolute];
    if (sections) {
      e.preventDefault();
      if (document.getElementById(sections)) {
        history.pushState(null, '', href);
        scrollToId(sections);
      } else {
        sessionStorage.setItem('scrollSection', sections);
        sessionStorage.setItem('scrollPath', href);
        window.location.href = '/';
      }
      return;
    }

    // Check if link contains a hash pointing to a home-page section
    var hashIndex = href.indexOf('#');
    if (hashIndex !== -1) {
      var hashId = href.slice(hashIndex + 1);
      if (HOME_SECTION_IDS[hashId]) {
        e.preventDefault();
        sessionStorage.setItem('scrollSection', hashId);
        sessionStorage.setItem('scrollPath', hashId === 'services-section' ? '/services' : '/booking-form');
        window.location.href = '/';
      }
    }
  });

  // Handle browser back/forward
  window.addEventListener('popstate', function() {
    var path = window.location.pathname.replace(/\/+$/, '') || '/';
    var targetId = PATH_MAP[path];
    if (targetId && document.getElementById(targetId)) {
      scrollToId(targetId);
    }
  });
})();