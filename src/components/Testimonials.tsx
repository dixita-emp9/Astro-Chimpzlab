import type * as React from 'react';
import { useCallback, useEffect, useRef, useState } from 'react';

type Testimonial = {
  quote: string;
  author: string;
  title: string;
  image: string;
  letter: string;
};

const fallbackData: Testimonial[] = [
  {
    quote: "ChimpzLab team has delivered an exceptional UI/UX upgrade for CIRL. Your ability to blend sophisticated design with functional simplicity is remarkable. The intuitive interface they crafted has significantly enhanced the experience for our customers. I would highly recommended any organization looking to elevate their digital presence to try Chimpzlab.",
    author: "Latesh A Shetty",
    title: "MD & CEO, Centrico Insurance Repository Limited",
    image: "",
    letter: "LS",
  },
  {
    quote: "Partnering with Chimpzlab has been an excellent experience. Their strategic thinking, creative excellence, and commitment to quality have consistently elevated our brand communication. A dependable team that truly understands premium real estate marketing.",
    author: "Lachman Ludhani",
    title: "Chairman & Managing Director, Evershine Group",
    image: "",
    letter: "LL",
  },
  {
    quote: "Chimpzlab has been a commendable partner for us. Their creative approach and timely delivery of services make our collaboration seamless and highly effective. We truly appreciate their professionalism and the value they bring to our initiatives.",
    author: "Dr Sriram Birudavolu",
    title: "CEO, Cybersecurity Centre of Excellence, Hyderabad",
    image: "",
    letter: "SB",
  },
  {
    quote: "Working with the Chimpzlab team has been a seamless experience. They have consistently delivered high-quality work with professionalism and responsiveness. The team is proactive, easy to work with, and quick to understand our requirements, ensuring that updates are implemented efficiently. We value their commitment, reliability, and continued support, and would be happy to recommend them to any organization looking for a dependable digital partner.",
    author: "Palak Nanjani",
    title: "Director, Paradigm ARQ",
    image: "",
    letter: "PN",
  },
  {
    quote: "Our experience working with the Chimpzlab team has been nothing short of outstanding. They possess a unique ability to bridge the gap between complex financial communication and highly effective digital strategy, a truly dependable team.",
    author: "Ananya Deshmukh",
    title: "VP of Corporate Strategy, FinVantage Capital",
    image: "",
    letter: "AD",
  },
];

const AUTOPLAY_MS = 5000;

export default function Testimonials() {
  const trackRef = useRef<HTMLDivElement>(null);
  const carouselRef = useRef<HTMLDivElement>(null);
  const [data, setData] = useState<Testimonial[]>(fallbackData);
  const [currentSlide, setCurrentSlide] = useState(0);

  const currentSlideRef = useRef(0);
  const totalSlidesRef = useRef(fallbackData.length);
  const autoplayRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const dragRef = useRef<{ startX: number; deltaX: number; isDragging: boolean }>({
    startX: 0,
    deltaX: 0,
    isDragging: false,
  });

  const goToSlide = useCallback((index: number, noTransform = false) => {
    const total = totalSlidesRef.current;
    if (total === 0) return;
    let i = index;
    if (i < 0) i = total - 1;
    if (i >= total) i = 0;
    currentSlideRef.current = i;
    setCurrentSlide(i);
    if (trackRef.current && !noTransform) {
      trackRef.current.style.transform = `translateX(-${i * 100}%)`;
    }
  }, []);

  const stopAutoplay = useCallback(() => {
    if (autoplayRef.current) {
      clearInterval(autoplayRef.current);
      autoplayRef.current = null;
    }
  }, []);

  const startAutoplay = useCallback(() => {
    stopAutoplay();
    autoplayRef.current = setInterval(() => goToSlide(currentSlideRef.current + 1), AUTOPLAY_MS);
  }, [goToSlide, stopAutoplay]);

  // Load testimonial data
  useEffect(() => {
    let active = true;
    fetch('/data/testimonials.json')
      .then((res) => {
        if (!res.ok) throw new Error('bad response');
        return res.json();
      })
      .then((json: Testimonial[]) => {
        if (!active) return;
        const list = json && json.length ? json : fallbackData;
        setData(list);
        totalSlidesRef.current = list.length;
        requestAnimationFrame(() => goToSlide(0));
      })
      .catch((err) => {
        console.warn('Failed to fetch testimonials JSON, using fallback data:', err);
        if (!active) return;
        totalSlidesRef.current = fallbackData.length;
        requestAnimationFrame(() => goToSlide(0));
      });
    return () => {
      active = false;
      stopAutoplay();
    };
  }, [goToSlide, stopAutoplay]);

  // Animate the section header in on mount (replaces the gsap-reveal behaviour
  // which would otherwise leave a React-hydrated node hidden at opacity 0).
  useEffect(() => {
    const header = document.getElementById('testimonials-header');
    if (!header) return;
    header.style.opacity = '0';
    header.style.transform = 'translateY(40px)';
    const id = requestAnimationFrame(() => {
      header.style.transition = 'opacity 0.8s ease, transform 0.8s ease';
      header.style.opacity = '1';
      header.style.transform = 'translateY(0)';
    });
    return () => cancelAnimationFrame(id);
  }, []);

  // Start autoplay after first render
  useEffect(() => {
    startAutoplay();
    return () => stopAutoplay();
  }, [startAutoplay, stopAutoplay]);

  const setTransition = (v: boolean) => {
    if (trackRef.current) {
      trackRef.current.style.transition = v ? 'transform 0.5s ease' : 'none';
    }
  };

  const onDragStart = (e: React.MouseEvent | React.TouchEvent) => {
    if (e.type === 'mousedown' && (e as React.MouseEvent).button !== 0) return;
    dragRef.current.isDragging = true;
    dragRef.current.startX = 'touches' in e ? e.touches[0].clientX : (e as React.MouseEvent).clientX;
    dragRef.current.deltaX = 0;
    setTransition(false);
    stopAutoplay();
  };

  const onDragMove = (e: React.MouseEvent | React.TouchEvent) => {
    if (!dragRef.current.isDragging) return;
    const x = 'touches' in e ? e.touches[0].clientX : (e as React.MouseEvent).clientX;
    dragRef.current.deltaX = x - dragRef.current.startX;
    const width = carouselRef.current ? carouselRef.current.offsetWidth : 1;
    const offset = -(currentSlideRef.current * 100) + (dragRef.current.deltaX / width) * 100;
    if (trackRef.current) trackRef.current.style.transform = `translateX(${offset}%)`;
  };

  const onDragEnd = () => {
    if (!dragRef.current.isDragging) return;
    dragRef.current.isDragging = false;
    setTransition(true);
    if (dragRef.current.deltaX < -50) goToSlide(currentSlideRef.current + 1);
    else if (dragRef.current.deltaX > 50) goToSlide(currentSlideRef.current - 1);
    else goToSlide(currentSlideRef.current);
    startAutoplay();
  };

  const handlePrev = () => {
    stopAutoplay();
    goToSlide(currentSlideRef.current - 1);
    startAutoplay();
  };

  const handleNext = () => {
    stopAutoplay();
    goToSlide(currentSlideRef.current + 1);
    startAutoplay();
  };

  const handleDot = (index: number) => {
    stopAutoplay();
    goToSlide(index);
    startAutoplay();
  };

  return (
    <section
      className="py-16 md:py-32 px-6 md:px-12 bg-[#f5f5f5] text-brand-dark border-t border-gray-200 overflow-hidden"
    >
      <div className="max-w-[950px] mx-auto">
        <div className="flex items-center gap-4 mb-12" id="testimonials-header">
          <div className="w-12 h-[1px] bg-gray-300"></div>
          <span className="text-xs font-bold uppercase tracking-[0.2em] text-gray-500">What Our Clients Say</span>
        </div>

        <div className="relative">
          <button
            type="button"
            onClick={handlePrev}
            className="testimonial-prev absolute left-0 md:-left-16 bottom-[-40px] md:bottom-auto md:top-1/2 md:-translate-y-1/2 w-12 h-12 rounded-full bg-white border border-gray-200 shadow-md flex items-center justify-center hover:bg-gray-50 hover:border-gray-300 transition-all duration-300 group z-20"
            aria-label="Previous testimonial"
          >
            <svg className="w-5 h-5 text-gray-600 group-hover:text-black transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 19l-7-7 7-7"></path>
            </svg>
          </button>

          <button
            type="button"
            onClick={handleNext}
            className="testimonial-next absolute right-0 md:-right-16 bottom-[-40px] md:bottom-auto md:top-1/2 md:-translate-y-1/2 w-12 h-12 rounded-full bg-white border border-gray-200 shadow-md flex items-center justify-center hover:bg-gray-50 hover:border-gray-300 transition-all duration-300 group z-20"
            aria-label="Next testimonial"
          >
            <svg className="w-5 h-5 text-gray-600 group-hover:text-black transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 5l7 7-7 7"></path>
            </svg>
          </button>

          <div
            ref={carouselRef}
            className="testimonial-carousel overflow-hidden"
            onTouchStart={onDragStart}
            onTouchMove={onDragMove}
            onTouchEnd={onDragEnd}
            onMouseDown={onDragStart}
            onMouseMove={onDragMove}
            onMouseUp={onDragEnd}
            onMouseLeave={onDragEnd}
          >
            <div ref={trackRef} className="testimonial-track flex transition-transform duration-500 ease-in-out">
              {data.map((t, i) => (
                <div className="testimonial-slide min-w-full px-2" key={i}>
                  <div className="bg-white rounded-3xl p-6 md:p-14 relative shadow-sm h-full flex flex-col justify-center">
                    <div className="absolute top-8 right-10 text-[120px] leading-none font-serif text-gray-100 select-none pointer-events-none">&quot;</div>
                    <p className="text-sm sm:text-lg md:text-xl lg:text-2xl font-medium leading-relaxed text-gray-800 mb-10 relative z-10 italic max-w-[800px]">
                      &quot;{t.quote}&quot;
                    </p>
                    <div className="flex items-center gap-4 relative z-10 shrink-0">
                      {t.image ? (
                        <img src={`/${t.image}`} alt={t.author} className="w-14 h-14 rounded-full object-cover" />
                      ) : (
                        <div className="w-14 h-14 rounded-full bg-gray-200 flex items-center justify-center text-gray-600 font-bold text-lg shrink-0">
                          {t.letter}
                        </div>
                      )}
                      <div>
                        <p className="font-bold text-base text-brand-dark">{t.author}</p>
                        <p className="text-sm text-gray-500">{t.title}</p>
                      </div>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>

          <div className="flex items-center justify-center gap-2 mt-4 md:mt-6" id="testimonial-dots">
            {data.map((_, i) => (
              <button
                key={i}
                type="button"
                onClick={() => handleDot(i)}
                className={`testimonial-dot w-2.5 h-2.5 rounded-full transition-all duration-300 ${i === currentSlide ? 'bg-black' : 'bg-gray-300'
                  }`}
                data-index={i}
                aria-label={`Go to slide ${i + 1}`}
              ></button>
            ))}
          </div>
        </div>
      </div>
    </section>
  );
}
