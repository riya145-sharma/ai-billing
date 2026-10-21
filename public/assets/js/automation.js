(() => {
    'use strict';
    // Confirmation for risky admin actions (no inline scripts under the CSP).
    document.querySelectorAll('form[data-confirm]').forEach(form => {
        form.addEventListener('submit', event => {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        });
    });

    // Message text: clamp long messages to a few lines with a Read more / Show less toggle.
    document.querySelectorAll('[data-clamp]').forEach(text => {
        const btn = text.parentElement.querySelector('[data-clamp-toggle]');
        text.classList.add('is-clamped');
        if (!btn || text.scrollHeight <= text.clientHeight + 2) {
            text.classList.remove('is-clamped');
            return;
        }
        btn.classList.remove('d-none');
        btn.addEventListener('click', () => {
            const open = text.classList.toggle('is-clamped') === false;
            btn.textContent = open ? 'Show less' : 'Read more';
            btn.setAttribute('aria-expanded', String(open));
        });
    });

    // Compact audio players; the native <audio controls> remains the no-JS fallback.
    const fmt = s => (Number.isFinite(s) ? Math.floor(s / 60) + ':' + String(Math.floor(s % 60)).padStart(2, '0') : '—');
    const players = [...document.querySelectorAll('[data-player]')];
    players.forEach(p => {
        const audio = p.querySelector('audio');
        const play = p.querySelector('[data-play]');
        const seek = p.querySelector('[data-seek]');
        const cur = p.querySelector('[data-cur]');
        const dur = p.querySelector('[data-dur]');
        play.hidden = false;
        seek.closest('.msg-track').hidden = false;
        p.classList.add('is-custom');

        const paint = () => {
            const pct = audio.duration ? (audio.currentTime / audio.duration) * 100 : 0;
            seek.value = pct;
            seek.style.setProperty('--pct', pct + '%');
            cur.textContent = fmt(audio.currentTime);
        };
        const icon = playing => {
            play.innerHTML = '<i class="bi bi-' + (playing ? 'pause-fill' : 'play-fill') + '" aria-hidden="true"></i>';
            play.setAttribute('aria-label', playing ? 'Pause audio' : 'Play audio');
        };
        play.addEventListener('click', () => {
            if (audio.paused) {
                players.forEach(o => { const a = o.querySelector('audio'); if (a !== audio) { a.pause(); } });
                audio.play();
            } else {
                audio.pause();
            }
        });
        audio.addEventListener('play', () => icon(true));
        audio.addEventListener('pause', () => icon(false));
        audio.addEventListener('ended', () => { icon(false); audio.currentTime = 0; paint(); });
        audio.addEventListener('timeupdate', paint);
        audio.addEventListener('loadedmetadata', () => { dur.textContent = fmt(audio.duration); });
        seek.addEventListener('input', () => {
            if (audio.duration) {
                audio.currentTime = (seek.value / 100) * audio.duration;
            }
            seek.style.setProperty('--pct', seek.value + '%');
        });
    });

    // Message sliders: arrows + page dots over a scroll-snap rail (swipe/trackpad/keyboard scroll natively).
    document.querySelectorAll('[data-slider]').forEach(slider => {
        const rail = slider.querySelector('[data-slider-rail]');
        const nav = slider.querySelector('[data-slider-nav]');
        const prev = slider.querySelector('[data-slider-prev]');
        const next = slider.querySelector('[data-slider-next]');
        const dotsBox = slider.querySelector('[data-slider-dots]');
        const cards = [...rail.children];
        let pages = 0;

        const step = () => (cards[1] ? cards[1].offsetLeft - cards[0].offsetLeft : rail.clientWidth);
        const perView = () => Math.max(1, Math.floor((rail.clientWidth + 4) / step()));
        const maxScroll = () => rail.scrollWidth - rail.clientWidth;
        const goTo = page => rail.scrollTo({ left: Math.min(page * perView() * step(), maxScroll()) });

        const update = () => {
            const overflow = maxScroll() > 4;
            nav.hidden = !overflow;
            dotsBox.hidden = !overflow;
            prev.disabled = rail.scrollLeft <= 4;
            next.disabled = rail.scrollLeft >= maxScroll() - 4;
            const active = next.disabled ? pages - 1 : Math.round(rail.scrollLeft / (perView() * step()));
            [...dotsBox.children].forEach((d, i) => {
                d.classList.toggle('is-active', i === active);
                d.setAttribute('aria-current', i === active ? 'true' : 'false');
            });
        };
        const build = () => {
            const count = Math.ceil(cards.length / perView());
            if (count !== pages) {
                pages = count;
                dotsBox.replaceChildren(...Array.from({ length: pages }, (_, i) => {
                    const dot = document.createElement('button');
                    dot.type = 'button';
                    dot.className = 'slider-dot';
                    dot.setAttribute('aria-label', 'Show page ' + (i + 1) + ' of ' + pages);
                    dot.addEventListener('click', () => goTo(i));
                    return dot;
                }));
            }
            update();
        };

        prev.addEventListener('click', () => rail.scrollBy({ left: -perView() * step() }));
        next.addEventListener('click', () => rail.scrollBy({ left: perView() * step() }));
        let frame = 0;
        rail.addEventListener('scroll', () => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(update);
        }, { passive: true });
        new ResizeObserver(build).observe(rail);
        build();
    });

    // Admin tabs: open the tab named in the URL hash (saves redirect to #users, #settings, #jobs)
    // and keep the hash in sync so a reload stays on the same tab.
    const tabs = document.querySelectorAll('.admin-tabs [data-bs-toggle="tab"]');
    if (!tabs.length || !window.bootstrap) {
        return;
    }
    const show = hash => {
        const btn = document.querySelector('.admin-tabs [data-bs-target="' + hash + '"]');
        if (btn) {
            bootstrap.Tab.getOrCreateInstance(btn).show();
            btn.closest('section').scrollIntoView({ block: 'start' });
        }
    };
    if (location.hash) {
        show(location.hash);
    }
    tabs.forEach(btn => btn.addEventListener('shown.bs.tab', () => {
        history.replaceState(null, '', btn.dataset.bsTarget);
    }));
    window.addEventListener('hashchange', () => show(location.hash));
})();
