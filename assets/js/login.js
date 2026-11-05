/**
 * AaZa HRMS - Login Interactive Controller
 * Theme Toggle • Password Visibility • Caps Lock Indicator • Submit Guard
 */
document.addEventListener('DOMContentLoaded', function () {
    initThemeToggle();
    initPasswordToggle();
    initCapsLockHint();
    initFormSubmitLoading();
});

// Restore button state on page restore (e.g. browser back button)
window.addEventListener('pageshow', function () {
    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<span>Sign in</span><i class="bi bi-arrow-right btn-arrow" aria-hidden="true"></i>';
    }
});

/**
 * 1. Fixed Theme Toggle Controller
 */
function initThemeToggle() {
    const btn = document.getElementById('themeToggleBtn');
    const htmlEl = document.documentElement;
    if (!btn) return;

    function getStoredTheme() {
        try {
            return localStorage.getItem('aaza-theme') || 'auto';
        } catch (e) {
            return 'auto';
        }
    }

    function getEffectiveTheme(stored) {
        if (!stored || stored === 'auto') {
            return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        return stored;
    }

    function updateButton(effective) {
        const isDark = (effective === 'dark');
        btn.setAttribute('aria-pressed', isDark ? 'true' : 'false');
        const newLabel = isDark ? 'Switch to light mode' : 'Switch to dark mode';
        btn.setAttribute('title', newLabel);
        btn.setAttribute('aria-label', newLabel);

        const icon = btn.querySelector('i');
        if (icon) {
            icon.style.opacity = '0';
            icon.style.transform = 'rotate(25deg) scale(0.85)';
            setTimeout(function () {
                icon.className = isDark ? 'bi bi-sun' : 'bi bi-moon-stars';
                icon.style.opacity = '1';
                icon.style.transform = 'rotate(0deg) scale(1)';
            }, 100);
        }
    }

    btn.addEventListener('click', function () {
        const current = htmlEl.getAttribute('data-bs-theme') || 'light';
        const next = current === 'dark' ? 'light' : 'dark';
        try {
            localStorage.setItem('aaza-theme', next);
        } catch (e) {}
        htmlEl.setAttribute('data-bs-theme', next);
        updateButton(next);
    });

    // React live to OS theme change when no explicit choice stored
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
        if (getStoredTheme() === 'auto') {
            const next = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            htmlEl.setAttribute('data-bs-theme', next);
            updateButton(next);
        }
    });

    updateButton(htmlEl.getAttribute('data-bs-theme') || 'light');
}

/**
 * 2. Password Visibility Toggle
 */
function initPasswordToggle() {
    const toggleBtn = document.getElementById('passwordToggleBtn');
    const passwordInput = document.getElementById('password');
    if (!toggleBtn || !passwordInput) return;

    toggleBtn.addEventListener('click', function () {
        const isPassword = passwordInput.getAttribute('type') === 'password';
        const newType = isPassword ? 'text' : 'password';
        passwordInput.setAttribute('type', newType);
        toggleBtn.setAttribute('aria-pressed', isPassword ? 'true' : 'false');

        const newLabel = isPassword ? 'Hide password' : 'Show password';
        toggleBtn.setAttribute('aria-label', newLabel);
        toggleBtn.setAttribute('title', newLabel);

        const icon = toggleBtn.querySelector('i');
        if (icon) {
            icon.className = isPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
        }
        passwordInput.focus();
    });
}

/**
 * 3. Caps Lock Indicator
 */
function initCapsLockHint() {
    const passwordInput = document.getElementById('password');
    const hint = document.getElementById('capsLockHint');
    if (!passwordInput || !hint) return;

    function checkCaps(e) {
        if (e.getModifierState && e.getModifierState('CapsLock')) {
            hint.style.display = 'inline-flex';
        } else {
            hint.style.display = 'none';
        }
    }

    passwordInput.addEventListener('keydown', checkCaps);
    passwordInput.addEventListener('keyup', checkCaps);
    passwordInput.addEventListener('blur', function () {
        hint.style.display = 'none';
    });
}

/**
 * 4. Submit Loading State & Double-Click Guard
 */
function initFormSubmitLoading() {
    const loginForm = document.getElementById('loginForm');
    const submitBtn = document.getElementById('submitBtn');
    if (!loginForm || !submitBtn) return;

    loginForm.addEventListener('submit', function () {
        if (loginForm.checkValidity && !loginForm.checkValidity()) {
            return;
        }
        submitBtn.innerHTML = '<span class="login-spinner me-2" aria-hidden="true"></span><span>Signing in…</span>';
        setTimeout(function () {
            submitBtn.disabled = true;
        }, 0);
    });
}
