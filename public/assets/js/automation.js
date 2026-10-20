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
