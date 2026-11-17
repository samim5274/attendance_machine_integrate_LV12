/*
 * Admin UI behaviour: Bootstrap JS, theme switcher, flash toasts, confirm dialogs.
 * Loaded as a deferred module, so page scripts should run on DOMContentLoaded
 * (window.bootstrap is available by then).
 */
import * as bootstrap from 'bootstrap';

window.bootstrap = bootstrap;

/* ---------------------------------------------------------------- theme */

const THEME_KEY = 'app.theme'; // 'light' | 'dark' | 'auto'
const media = window.matchMedia('(prefers-color-scheme: dark)');

function storedTheme() {
    try {
        const t = localStorage.getItem(THEME_KEY);
        return t === 'light' || t === 'dark' ? t : 'auto';
    } catch {
        return 'auto';
    }
}

function resolve(theme) {
    return theme === 'auto' ? (media.matches ? 'dark' : 'light') : theme;
}

function applyTheme(theme) {
    document.documentElement.setAttribute('data-bs-theme', resolve(theme));

    const icons = { light: 'bi-sun', dark: 'bi-moon-stars', auto: 'bi-circle-half' };
    document.querySelectorAll('[data-theme-icon]').forEach((el) => {
        el.className = `bi ${icons[theme]}`;
    });
    document.querySelectorAll('[data-theme-value]').forEach((btn) => {
        const active = btn.dataset.themeValue === theme;
        btn.classList.toggle('active', active);
        btn.setAttribute('aria-pressed', String(active));
    });
}

function setTheme(theme) {
    try {
        if (theme === 'auto') localStorage.removeItem(THEME_KEY);
        else localStorage.setItem(THEME_KEY, theme);
    } catch {
        /* storage unavailable (private mode): theme still applies for this page */
    }
    applyTheme(theme);
}

media.addEventListener('change', () => {
    if (storedTheme() === 'auto') applyTheme('auto');
});

// Other tabs changed the theme.
window.addEventListener('storage', (e) => {
    if (e.key === THEME_KEY) applyTheme(storedTheme());
});

/* ----------------------------------------------------------- page setup */

document.addEventListener('DOMContentLoaded', () => {
    applyTheme(storedTheme());
    document.querySelectorAll('[data-theme-value]').forEach((btn) => {
        btn.addEventListener('click', () => setTheme(btn.dataset.themeValue));
    });
    // Enable colour transitions only after the first paint.
    requestAnimationFrame(() => document.documentElement.classList.add('theme-ready'));

    // Flash messages.
    document.querySelectorAll('.js-flash').forEach((el) => bootstrap.Toast.getOrCreateInstance(el).show());

    // Tooltips.
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => bootstrap.Tooltip.getOrCreateInstance(el));

    // <form data-confirm="message" data-confirm-title="…" data-confirm-label="…" data-confirm-danger>
    const modalEl = document.getElementById('confirmModal');
    if (modalEl) {
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        const ok = modalEl.querySelector('.js-confirm-ok');
        let pending = null;

        document.addEventListener('submit', (e) => {
            const form = e.target;
            if (!(form instanceof HTMLFormElement) || !form.matches('form[data-confirm]') || form.dataset.confirmed === '1') return;
            e.preventDefault();
            pending = form;
            modalEl.querySelector('.modal-title').textContent = form.dataset.confirmTitle || 'Are you sure?';
            modalEl.querySelector('.js-confirm-message').textContent = form.dataset.confirm;
            ok.textContent = form.dataset.confirmLabel || 'Confirm';
            ok.className = `btn js-confirm-ok ${form.hasAttribute('data-confirm-danger') ? 'btn-danger' : 'btn-teal'}`;
            modal.show();
        });

        modalEl.addEventListener('shown.bs.modal', () => ok.focus());
        ok.addEventListener('click', () => {
            if (!pending) return;
            pending.dataset.confirmed = '1';
            modal.hide();
            pending.requestSubmit();
        });
    }

    // Prevent double submits on any POST form.
    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (e.defaultPrevented || !(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post') return;
        setTimeout(() => form.querySelectorAll('button[type="submit"]').forEach((b) => (b.disabled = true)), 0);
    });
});
