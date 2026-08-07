(function () {
    // Featured Case Study - JSON-driven carousel (shared across service pages)
    function initCarousel() {
        var el = document.querySelector('.case-study-carousel');
        if (!el) return;
        var wrapper = el.querySelector('.swiper-wrapper');
        if (!wrapper || !wrapper.dataset.slugs) return;

        var slugs = wrapper.dataset.slugs.split(',').map(function (s) { return s.trim(); });
        var prefix = wrapper.dataset.linkPrefix || '../case-studies/';
        var coverPrefix = wrapper.dataset.coverPrefix || '';

        // Resolve the shared services case-study index. Using an absolute path
        // avoids the broken page-relative resolution that occurred on nested
        // service routes (the previous depth-based prefix resolved to 404s).
        var jsonPath = '/services/case-studies.json';

        fetch(jsonPath, { cache: 'no-cache' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var items = [];
                var uniqueSlugs = slugs.filter(function (s, i) { return slugs.indexOf(s) === i; });
                uniqueSlugs.forEach(function (slug) {
                    var item = data.find(function (d) { return d.slug === slug; });
                    if (item && !item.draft) items.push(item);
                });
                if (items.length === 1) {
                    var item = items[0];
                    el.closest('.case-study-carousel-outer').classList.add('featured-single-mode');
                    var slide = document.createElement('div');
                    slide.innerHTML = '<a href="' + prefix + item.slug + '" class="block group featured-single-link h-full"><div class="featured-single-card bg-white/5 border border-white/10 rounded-2xl md:rounded-3xl overflow-hidden h-full flex flex-col md:flex-row"><div class="md:w-[45%] lg:w-[50%] relative h-[320px] sm:h-[400px] md:h-auto min-h-[380px] overflow-hidden shrink-0"><img src="' + (item.cover.indexOf('http') === 0 ? item.cover : coverPrefix + item.cover) + '" alt="' + item.title + '" class="w-full h-full object-cover transition-transform duration-700 ease-out will-change-transform group-hover:scale-110"></div><div class="p-8 sm:p-10 md:p-14 flex flex-col justify-center flex-1"><h3 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-white leading-tight tracking-tight">' + item.title + '</h3>' + (item.cats ? '<div class="flex flex-wrap gap-2 mt-4 mb-4"><span class="cs-tag text-xs">' + item.cats + '</span></div>' : '') + '<span class="inline-flex items-center gap-2 text-white font-bold text-sm sm:text-base group-hover:gap-3 transition-all duration-300">Read More<svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg></span></div></div></a>';
                    wrapper.appendChild(slide);
                } else if (items.length > 1) {
                    items.forEach(function (item) {
                        var slide = document.createElement('div');
                        slide.className = 'swiper-slide h-auto';
                        slide.innerHTML = '<a href="' + prefix + item.slug + '" class="block group interactive h-full"><div class="bg-white/5 border border-white/10 rounded-2xl md:rounded-3xl overflow-hidden h-full flex flex-col"><div class="relative h-[220px] sm:h-[280px] md:h-[340px] overflow-hidden shrink-0"><img src="' + (item.cover.indexOf('http') === 0 ? item.cover : coverPrefix + item.cover) + '" alt="' + item.title + '" class="w-full h-full object-cover transition-transform duration-700 ease-out will-change-transform group-hover:scale-110"></div><div class="p-5 sm:p-6 md:p-8 flex flex-col flex-1"><h3 class="text-lg sm:text-xl md:text-2xl font-extrabold text-white leading-tight tracking-tight">' + item.title + '</h3>' + (item.cats ? '<div class="flex flex-wrap gap-2 mt-3 mb-2"><span class="cs-tag text-xs">' + item.cats + '</span></div>' : '') + '<span class="inline-flex items-center gap-2 text-white font-bold text-xs sm:text-sm group-hover:gap-3 transition-all duration-300">Read More<svg class="w-3 h-3 sm:w-4 sm:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg></span></div></div></a>';
                        wrapper.appendChild(slide);
                    });
                    if (window.Swiper) {
                        new Swiper('.case-study-carousel', {
                            slidesPerView: 1,
                            spaceBetween: 16,
                            loop: true,
                            speed: 600,
                            pagination: { el: '.swiper-pagination', clickable: true },
                            navigation: { nextEl: '.swiper-button-next', prevEl: '.swiper-button-prev' },
                            breakpoints: {
                                640: { slidesPerView: 1.5, spaceBetween: 20 },
                                768: { slidesPerView: 2, spaceBetween: 24 },
                                1024: { slidesPerView: 2.5, spaceBetween: 28 },
                                1280: { slidesPerView: 3, spaceBetween: 32 },
                            },
                        });
                    }
                }
            })
            .catch(function (err) {
                console.warn('Failed to load case studies:', err);
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCarousel);
    } else {
        initCarousel();
    }
})();
