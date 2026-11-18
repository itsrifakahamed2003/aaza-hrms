/**
 * AaZa HRMS - Login Interactive Controller
 * Password Visibility • Caps Lock Indicator • Submit Guard
 */
document.addEventListener('DOMContentLoaded', function () {
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
 * 1. Password Visibility Toggle
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
 * 2. Caps Lock Indicator
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
 * 3. Submit Loading State & Double-Click Guard
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
