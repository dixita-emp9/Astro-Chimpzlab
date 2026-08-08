(function () {
    // Email Marketing featured projects - shared across the service page
    // carousel (Video-style image showcase) and the dedicated project page
    // grid. Cards open an image popup on click.

    function showImagePopup(src, title) {
        var overlay = document.createElement('div');
        overlay.className =
            'fixed inset-0 z-[100] bg-black/90 flex items-center justify-center';
        overlay.setAttribute('data-lenis-prevent', '');
        overlay.innerHTML =
            '<button class="fixed top-6 right-6 z-[110] w-10 h-10 rounded-full bg-white/10 hover:bg-white/30 text-white flex items-center justify-center text-2xl font-bold transition-colors" aria-label="Close">&times;</button>' +
            '<div class="rounded-2xl" style="width:90%;max-width:1200px;max-height:90vh;overflow-y:auto">' +
            '<img src="' + src + '" alt="' + title + '" class="w-full h-auto block rounded-2xl shadow-2xl">' +
            '</div>';

        function close() {
            overlay.remove();
            document.body.style.overflow = '';
            if (window.lenis && typeof window.lenis.start === 'function')
                window.lenis.start();
        }

        overlay.querySelector('button').addEventListener('click', close);
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) close();
        });
        document.body.style.overflow = 'hidden';
        if (window.lenis && typeof window.lenis.stop === 'function')
            window.lenis.stop();
        document.body.appendChild(overlay);
    }

    function makeCard(item) {
        var card = document.createElement('div');
        card.className = 'interactive group h-full cursor-pointer';
        card.innerHTML =
            '<div class="bg-white/5 border border-white/10 rounded-2xl md:rounded-3xl overflow-hidden h-full flex flex-col hover:bg-white/[0.08] transition-colors duration-300">' +
            '<div class="shrink-0 max-h-[400px] overflow-hidden relative">' +
            '<img src="' + item.image + '" alt="' + item.title + '" class="w-full block" loading="lazy">' +
            '</div>' +
            '<div class="p-5 sm:p-6 flex flex-col flex-1">' +
            '<h3 class="text-lg sm:text-xl font-extrabold text-white leading-tight tracking-tight">' + item.title + '</h3>' +
            (item.desc ? '<p class="text-gray-400 font-medium text-sm leading-relaxed flex-1 line-clamp-2 mt-2">' + item.desc + '</p>' : '') +
            '</div></div>';
        card.addEventListener('click', function () {
            showImagePopup(item.image, item.title);
        });
        return card;
    }

    function initCarousel(data) {
        var el = document.querySelector('.case-study-carousel');
        if (!el || el.swiper) return;
        var wrapper = el.querySelector('.swiper-wrapper');
        data.forEach(function (item) {
            var slide = document.createElement('div');
            slide.className = 'swiper-slide h-auto';
            slide.appendChild(makeCard(item));
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
                var card = makeCard(item);
                card.dataset.category = item.category || 'uncategorized';
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
                grid.querySelectorAll('[data-category]').forEach(function (card) {
                    var match =
                        f === 'all' || card.dataset.category === f;
                    card.classList.toggle('hidden', !match);
                });
            });
        });
    }

    function init() {
        fetch(
            '/services/demand-generation/email-marketing-case-studies.json',
            { cache: 'no-cache' },
        )
            .then(function (r) { return r.json(); })
            .then(function (data) {
                initCarousel(data);
                initGrid(data);
            })
            .catch(function (err) {
                console.warn('Failed to load email marketing projects:', err);
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
