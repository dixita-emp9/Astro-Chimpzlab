(function () {
    // Website Design & Landing Page featured projects - shared across the
    // service page carousel and the dedicated project page grid. Cards link
    // out to the live site in a new tab.

    function absImagePath(p) {
        if (/^(https?:)?\/\//i.test(p) || p.charAt(0) === '/') return p;
        return '/' + String(p).replace(/^(?:\.\.?\/)+/, '');
    }

    function cardInner(item) {
        return '<div class="bg-white/5 border border-white/10 rounded-2xl md:rounded-3xl overflow-hidden h-full flex flex-col hover:bg-white/[0.08] transition-colors duration-300">'
            + '<div class="shrink-0">'
            + '<img src="' + absImagePath(item.image) + '" alt="' + item.title + '" class="w-full block" loading="lazy">'
            + '</div>'
            + '<div class="p-5 sm:p-6 md:p-8 flex flex-col flex-1">'
            + '<h3 class="text-lg sm:text-xl md:text-2xl font-extrabold text-white mb-2 sm:mb-3 leading-tight tracking-tight">' + item.title + '</h3>'
            + '<p class="text-gray-400 font-medium text-sm leading-relaxed flex-1 line-clamp-2">' + (item.desc || '') + '</p>'
            + '<a href="' + (item.link || '#') + '" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 text-white font-bold text-xs sm:text-sm hover:gap-3 transition-all duration-300 mt-4">'
            + 'Visit Site'
            + '<svg class="w-3 h-3 sm:w-4 sm:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>'
            + '</a>'
            + '</div></div>';
    }

    function initCarousel(data) {
        var el = document.querySelector('.case-study-carousel');
        if (!el || el.swiper) return;
        var wrapper = el.querySelector('.swiper-wrapper');
        data.forEach(function (item) {
            var slide = document.createElement('div');
            slide.className = 'swiper-slide h-auto';
            slide.innerHTML = '<div class="interactive h-full">' + cardInner(item) + '</div>';
            wrapper.appendChild(slide);
        });
        if (window.Swiper) {
            new Swiper(el, {
                slidesPerView: 1,
                spaceBetween: 16,
                loop: true,
                speed: 600,
                grabCursor: true,
                pagination: { el: '.swiper-pagination', clickable: true },
                navigation: {
                    nextEl: '.swiper-button-next',
                    prevEl: '.swiper-button-prev',
                },
                breakpoints: {
                    640: { slidesPerView: 1.5, spaceBetween: 20 },
                    768: { slidesPerView: 2, spaceBetween: 24 },
                    1024: { slidesPerView: 2.5, spaceBetween: 28 },
                    1280: { slidesPerView: 3, spaceBetween: 32 },
                },
            });
        }
    }

    function initGrid(data) {
        var grid = document.getElementById('project-grid');
        if (!grid) return;
        var filterBar = document.getElementById('filter-bar');
        var emptyMsg = document.getElementById('cs-empty');

        var cats = [];
        data.forEach(function (d) {
            if (d.category && cats.indexOf(d.category) === -1)
                cats.push(d.category);
        });
        cats.sort();

        var filterHtml =
            '<button data-filter="all" class="cs-filter active interactive px-2 sm:px-3 py-1 md:px-5 md:py-2.5 rounded-full text-sm font-semibold tracking-wide transition-all duration-300 bg-white text-brand-dark">All</button>';
        cats.forEach(function (cat) {
            filterHtml +=
                '<button data-filter="' + cat + '" class="cs-filter interactive px-2 sm:px-3 py-1 md:px-5 md:py-2.5 rounded-full text-sm font-semibold tracking-wide transition-all duration-300 bg-white/10 text-white/70 hover:bg-white/20 hover:text-white">' + cat + '</button>';
        });
        if (filterBar) filterBar.innerHTML = filterHtml;

        function renderCards(items) {
            grid.innerHTML = '';
            items.forEach(function (item) {
                var card = document.createElement('div');
                card.className = 'h-full';
                card.dataset.category = item.category || 'uncategorized';
                card.innerHTML = cardInner(item);
                grid.appendChild(card);
            });
            if (emptyMsg) emptyMsg.classList.add('hidden');
        }
        renderCards(data);

        var filterBtns = filterBar
            ? filterBar.querySelectorAll('.cs-filter')
            : [];
        filterBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (btn.classList.contains('active')) return;
                filterBtns.forEach(function (b) {
                    b.classList.remove('active', 'bg-white', 'text-brand-dark');
                    b.classList.add('bg-white/10', 'text-white/70');
                });
                btn.classList.add('active');
                btn.classList.remove('bg-white/10', 'text-white/70');
                btn.classList.add('bg-white', 'text-brand-dark');
                var f = btn.dataset.filter;
                var visible = 0;
                grid.querySelectorAll('[data-category]').forEach(function (card) {
                    var match = f === 'all' || card.dataset.category === f;
                    card.classList.toggle('hidden', !match);
                    if (match) visible++;
                });
                if (emptyMsg)
                    emptyMsg.classList.toggle('hidden', visible > 0);
            });
        });
    }

    function init() {
        var carouselEl = document.querySelector('.case-study-carousel');
        var grid = document.getElementById('project-grid');
        if (!carouselEl && !grid) return;
        var jsonPath =
            (carouselEl && carouselEl.getAttribute('data-projects-json')) ||
            (grid && grid.getAttribute('data-projects-json')) ||
            '/services/digital-experiences/website-design-development-case-studies.json';

        fetch(jsonPath)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                initCarousel(data);
                initGrid(data);
            })
            .catch(function (err) {
                console.warn('Failed to load website design projects:', err);
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
