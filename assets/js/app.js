/**
 * AaZa HRMS - Core UI Interactions & Theme Controller
 * - Light / Dark Theme Switching & Persistence
 * - Responsive Desktop Collapsible & Mobile Drawer Sidebar
 * - Bootstrap Modal Confirmations (replacing window.confirm)
 * - Password Visibility Toggles
 * - Toast Flash Notifications Auto-Dismissal
 */
document.addEventListener('DOMContentLoaded', function () {
    initTheme();
    initSidebar();
    initSidebarCollapses();
    initCollapsedSidebarFlyouts();
    initSidebarScroll();
    initConfirmModal();
    initPasswordToggle();
    initFlashAutoDismiss();
    initStatCountUp();
    initKeyboardShortcuts();
    initGlobalSearch();
    initGenericSearchClear();
    initNotifications();
    initActionMenus();
    initClickableRows();
});

/**
 * 1. Light / Dark Theme Controller
 */
function initTheme() {
    const themeToggleBtns = document.querySelectorAll('.theme-toggle-btn');
    const htmlEl = document.documentElement;

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

    function applyTheme(theme) {
        const effective = getEffectiveTheme(theme);
        htmlEl.setAttribute('data-bs-theme', effective);
        updateButtons(effective);
        updateChartsTheme(effective);
    }

    function updateButtons(effectiveTheme) {
        const isDark = (effectiveTheme === 'dark');
        themeToggleBtns.forEach(function (btn) {
            btn.setAttribute('aria-pressed', isDark ? 'true' : 'false');
            const newLabel = isDark ? 'Switch to light mode' : 'Switch to dark mode';
            btn.setAttribute('title', newLabel);
            btn.setAttribute('aria-label', newLabel);

            const icon = btn.querySelector('i');
            if (icon) {
                icon.style.opacity = '0';
                icon.style.transform = 'rotate(20deg) scale(0.85)';
                setTimeout(function () {
                    icon.className = isDark ? 'bi bi-sun-fill text-warning' : 'bi bi-moon-stars-fill text-secondary';
                    icon.style.opacity = '1';
                    icon.style.transform = 'rotate(0deg) scale(1)';
                }, 100);
            }
        });
    }

    function updateChartsTheme(effectiveTheme) {
        if (typeof Chart === 'undefined') return;
        const isDark = (effectiveTheme === 'dark');
        const textColor = isDark ? '#cbd5e1' : '#64748b';
        const gridColor = isDark ? '#1e293b' : '#f1f5f9';
        const legendColor = isDark ? '#f8fafc' : '#1e293b';

        Chart.defaults.color = textColor;
        Chart.defaults.borderColor = gridColor;

        if (Chart.instances) {
            Object.values(Chart.instances).forEach(function (chart) {
                if (chart.options && chart.options.scales) {
                    if (chart.options.scales.x) {
                        if (chart.options.scales.x.grid) chart.options.scales.x.grid.color = gridColor;
                        if (chart.options.scales.x.ticks) chart.options.scales.x.ticks.color = textColor;
                    }
                    if (chart.options.scales.y) {
                        if (chart.options.scales.y.grid) chart.options.scales.y.grid.color = gridColor;
                        if (chart.options.scales.y.ticks) chart.options.scales.y.ticks.color = textColor;
                    }
                }
                if (chart.options && chart.options.plugins && chart.options.plugins.legend && chart.options.plugins.legend.labels) {
                    chart.options.plugins.legend.labels.color = legendColor;
                }
                chart.update();
            });
        }
    }

    // Toggle click handler
    themeToggleBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            const current = htmlEl.getAttribute('data-bs-theme') || 'light';
            const next = current === 'dark' ? 'light' : 'dark';
            try {
                localStorage.setItem('aaza-theme', next);
            } catch (e) {}
            applyTheme(next);
        });
    });

    // Listen for OS system theme changes
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
        if (getStoredTheme() === 'auto') {
            applyTheme('auto');
        }
    });

    // Initial button sync
    updateButtons(htmlEl.getAttribute('data-bs-theme') || 'light');
}

/**
 * 2. Sidebar Responsiveness & State Persistence
 */
function initSidebar() {
    const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
    const mobileSidebarToggleBtn = document.getElementById('mobileSidebarToggleBtn');
    const sidebar = document.querySelector('.app-sidebar');
    const body = document.body;

    // Restore desktop collapsed state
    if (window.innerWidth >= 992) {
        const isCollapsed = localStorage.getItem('aaza_sidebar_collapsed') === 'true';
        if (isCollapsed) {
            body.classList.add('sidebar-collapsed');
        }
    }

    function toggleSidebar() {
        if (window.innerWidth >= 992) {
            body.classList.toggle('sidebar-collapsed');
            const state = body.classList.contains('sidebar-collapsed');
            localStorage.setItem('aaza_sidebar_collapsed', state ? 'true' : 'false');
            if (!state) {
                restoreSidebarScroll();
            }
        } else {
            if (sidebar) {
                sidebar.classList.toggle('show');
                const isShown = sidebar.classList.contains('show');
                sidebar.classList.toggle('sidebar-open', isShown);
                body.classList.toggle('sidebar-open', isShown);
                toggleBackdrop(isShown);
                if (isShown) {
                    resetMobileSidebarToActive();
                }
            }
        }
    }

    if (sidebarToggleBtn) {
        sidebarToggleBtn.addEventListener('click', toggleSidebar);
    }
    if (mobileSidebarToggleBtn) {
        mobileSidebarToggleBtn.addEventListener('click', toggleSidebar);
    }

    // Backdrop for mobile offcanvas
    function toggleBackdrop(show) {
        let backdrop = document.getElementById('sidebarBackdrop');
        if (show) {
            if (!backdrop) {
                backdrop = document.createElement('div');
                backdrop.id = 'sidebarBackdrop';
                backdrop.className = 'modal-backdrop fade show sidebar-backdrop';
                backdrop.style.zIndex = '1040';
                backdrop.addEventListener('click', function () {
                    if (sidebar) {
                        sidebar.classList.remove('show');
                        sidebar.classList.remove('sidebar-open');
                    }
                    body.classList.remove('sidebar-open');
                    backdrop.remove();
                });
                document.body.appendChild(backdrop);
            }
        } else {
            if (backdrop) backdrop.remove();
        }
    }

    // Close on Escape key press
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' || e.key === 'Esc') {
            if (sidebar && (sidebar.classList.contains('show') || sidebar.classList.contains('sidebar-open') || body.classList.contains('sidebar-open'))) {
                sidebar.classList.remove('show');
                sidebar.classList.remove('sidebar-open');
                body.classList.remove('sidebar-open');
                const backdrop = document.getElementById('sidebarBackdrop');
                if (backdrop) backdrop.remove();
            }
        }
    });

    // Close mobile sidebar on window resize to desktop
    window.addEventListener('resize', function () {
        if (window.innerWidth >= 992) {
            if (sidebar) {
                sidebar.classList.remove('show');
                sidebar.classList.remove('sidebar-open');
            }
            body.classList.remove('sidebar-open');
            const backdrop = document.getElementById('sidebarBackdrop');
            if (backdrop) backdrop.remove();
        }
    });
}

/**
 * 2b. Sidebar Collapsible Sections & State Persistence
 */
function initSidebarCollapses() {
    const collapseElements = document.querySelectorAll('.sidebar-nav-container .collapse');
    collapseElements.forEach(function (el) {
        const id = el.id;
        if (!id) return;

        // Auto-open if current page is inside this section
        const hasActiveChild = el.querySelector('.sidebar-nav-link.active') !== null;
        let shouldOpen = hasActiveChild;

        if (!shouldOpen) {
            try {
                const saved = localStorage.getItem('aaza_sidebar_section_' + id);
                if (saved === 'true') {
                    shouldOpen = true;
                } else if (saved === 'false') {
                    shouldOpen = false;
                }
            } catch (e) {}
        }

        const parentBtn = document.querySelector('[data-bs-target="#' + id + '"]');
        if (shouldOpen) {
            el.classList.add('show');
            if (parentBtn) {
                parentBtn.classList.remove('collapsed');
                parentBtn.setAttribute('aria-expanded', 'true');
            }
        } else {
            el.classList.remove('show');
            if (parentBtn) {
                parentBtn.classList.add('collapsed');
                parentBtn.setAttribute('aria-expanded', 'false');
            }
        }

        el.addEventListener('shown.bs.collapse', function () {
            try {
                localStorage.setItem('aaza_sidebar_section_' + id, 'true');
            } catch (e) {}
        });

        el.addEventListener('hidden.bs.collapse', function () {
            try {
                localStorage.setItem('aaza_sidebar_section_' + id, 'false');
            } catch (e) {}
        });
    });
}

/**
 * 2c. Sidebar Scroll Helpers & Active Link Tracking
 */
function isSidebarCollapsed() {
    return window.innerWidth >= 992 && (document.body.classList.contains('sidebar-collapsed') || localStorage.getItem('aaza_sidebar_collapsed') === 'true');
}

function isMobileView() {
    return window.innerWidth < 992;
}

function getActiveSidebarLink(container) {
    if (!container) return null;
    return container.querySelector('.sidebar-submenu a.active, .sidebar-submenu .sidebar-nav-link.active') ||
           container.querySelector('a.sidebar-nav-link.active') ||
           container.querySelector('.sidebar-nav-link.active:not(button)');
}

function restoreSidebarScroll() {
    try {
        if (isSidebarCollapsed() || isMobileView()) return;
        const el = document.querySelector('.sidebar-nav-container');
        if (!el) return;
        const saved = sessionStorage.getItem('aaza_sidebar_scroll');
        if (saved !== null) {
            const y = parseInt(saved, 10);
            if (!isNaN(y) && y > 0) {
                el.scrollTop = y;
            }
        }
    } catch (e) {}
}

function ensureActiveLinkVisible(el) {
    if (isSidebarCollapsed() || isMobileView()) return;
    const activeLink = getActiveSidebarLink(el);
    if (!activeLink) return;

    const cRect = el.getBoundingClientRect();
    const lRect = activeLink.getBoundingClientRect();
    if (cRect.height <= 0) return;

    // 1px tolerance for subpixel rounding
    const isAbove = lRect.top < cRect.top - 1;
    const isBelow = lRect.bottom > cRect.bottom + 1;

    if (isAbove || isBelow) {
        const linkCenter = lRect.top + (lRect.height / 2);
        const containerCenter = cRect.top + (cRect.height / 2);
        const targetScroll = el.scrollTop + (linkCenter - containerCenter);
        el.scrollTop = Math.max(0, Math.min(targetScroll, el.scrollHeight - el.clientHeight));
        try {
            sessionStorage.setItem('aaza_sidebar_scroll', String(Math.round(el.scrollTop)));
        } catch (e) {}
    }
}

function resetMobileSidebarToActive() {
    const el = document.querySelector('.sidebar-nav-container');
    if (!el) return;
    function doReset() {
        const activeLink = getActiveSidebarLink(el);
        if (activeLink) {
            const cRect = el.getBoundingClientRect();
            const lRect = activeLink.getBoundingClientRect();
            const linkCenter = lRect.top + (lRect.height / 2);
            const containerCenter = cRect.top + (cRect.height / 2);
            const targetScroll = el.scrollTop + (linkCenter - containerCenter);
            el.scrollTop = Math.max(0, Math.min(targetScroll, el.scrollHeight - el.clientHeight));
        } else {
            el.scrollTop = 0;
        }
    }
    doReset();
    window.requestAnimationFrame(doReset);
}

/**
 * 2d. Sidebar Scroll Position Persistence & Active Link Safety Net
 */
function initSidebarScroll() {
    const el = document.querySelector('.sidebar-nav-container');
    const sidebar = document.getElementById('appSidebar') || document.querySelector('.app-sidebar');
    if (!el) return;

    // 1. Restore scroll AFTER submenu section state is applied (height is final)
    if (!isSidebarCollapsed() && !isMobileView()) {
        try {
            const saved = sessionStorage.getItem('aaza_sidebar_scroll');
            if (saved !== null) {
                const y = parseInt(saved, 10);
                if (!isNaN(y) && y > 0) {
                    el.scrollTop = y;
                }
            }
        } catch (e) {}

        // 2. Safety net after DOMContentLoaded: center active link if outside visible area
        ensureActiveLinkVisible(el);
    }

    // Helper to write el.scrollTop to sessionStorage
    function saveScrollNow() {
        if (isSidebarCollapsed() || isMobileView()) return;
        try {
            sessionStorage.setItem('aaza_sidebar_scroll', String(Math.round(el.scrollTop)));
        } catch (e) {}
    }

    // 3. Save position on 'scroll' (throttled with requestAnimationFrame)
    let scrollRafId = null;
    el.addEventListener('scroll', function () {
        if (isSidebarCollapsed() || isMobileView()) return;
        if (scrollRafId !== null) return;
        scrollRafId = window.requestAnimationFrame(function () {
            scrollRafId = null;
            saveScrollNow();
        });
    }, { passive: true });

    // 4. Save position on click of any link inside the sidebar
    if (sidebar) {
        sidebar.addEventListener('click', function (e) {
            const link = e.target.closest('a');
            if (link && sidebar.contains(link)) {
                saveScrollNow();
            }
        });
    }

    // 5. Save position on 'pagehide' and 'beforeunload'
    window.addEventListener('pagehide', function () {
        saveScrollNow();
    });
    window.addEventListener('beforeunload', function () {
        saveScrollNow();
    });

    // 6. Back/forward cache: restore on 'pageshow' when event.persisted is true
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            restoreSidebarScroll();
        }
    });
}

/**
 * 2d. Collapsed Sidebar Flyout Submenus
 * Shows an unclipped floating popover for submenu items (Leave, Interviews) when desktop sidebar is collapsed.
 */
function initCollapsedSidebarFlyouts() {
    const submenuItems = document.querySelectorAll('.app-sidebar .sidebar-nav-item.has-submenu');
    if (submenuItems.length === 0) return;

    let activeFlyoutItem = null;
    let closeTimer = null;

    // Singleton floating container in document.body
    let flyoutEl = document.getElementById('sidebarCollapsedFlyout');
    if (!flyoutEl) {
        flyoutEl = document.createElement('div');
        flyoutEl.id = 'sidebarCollapsedFlyout';
        flyoutEl.className = 'sidebar-collapsed-flyout d-none';
        document.body.appendChild(flyoutEl);
    }

    function escapeFlyoutHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    flyoutEl.addEventListener('mouseenter', function () {
        if (closeTimer) {
            clearTimeout(closeTimer);
            closeTimer = null;
        }
    });

    flyoutEl.addEventListener('mouseleave', function () {
        scheduleClose();
    });

    function openFlyout(item) {
        if (!isSidebarCollapsed()) return;
        if (closeTimer) {
            clearTimeout(closeTimer);
            closeTimer = null;
        }

        const parentBtn = item.querySelector('.sidebar-nav-parent');
        const submenuUl = item.querySelector('.sidebar-submenu');
        if (!parentBtn || !submenuUl) return;

        const btnRect = parentBtn.getBoundingClientRect();
        const titleSpan = parentBtn.querySelector('span');
        const titleText = titleSpan ? titleSpan.textContent.trim() : 'Menu';
        const iconEl = parentBtn.querySelector('i');
        const iconClass = iconEl ? iconEl.className : 'bi bi-grid';

        const clonedUl = submenuUl.cloneNode(true);

        flyoutEl.innerHTML = `
            <div class="sidebar-flyout-header">
                <i class="${escapeFlyoutHtml(iconClass)} me-2"></i>
                <span>${escapeFlyoutHtml(titleText)}</span>
            </div>
            <div class="sidebar-flyout-body">
                ${clonedUl.outerHTML}
            </div>
        `;

        const sidebar = document.querySelector('.app-sidebar');
        const sidebarRect = sidebar ? sidebar.getBoundingClientRect() : { right: 64 };
        const left = Math.round(sidebarRect.right + 4);
        let top = Math.round(btnRect.top);

        flyoutEl.style.left = left + 'px';
        flyoutEl.style.top = top + 'px';
        flyoutEl.classList.remove('d-none');
        flyoutEl.classList.add('show');

        // Adjust vertical position if overflowing bottom of screen
        const flyoutRect = flyoutEl.getBoundingClientRect();
        if (flyoutRect.bottom > window.innerHeight - 10) {
            const overflow = flyoutRect.bottom - (window.innerHeight - 10);
            top = Math.max(10, top - overflow);
            flyoutEl.style.top = top + 'px';
        }

        activeFlyoutItem = item;
    }

    function scheduleClose() {
        if (closeTimer) clearTimeout(closeTimer);
        closeTimer = setTimeout(function () {
            hideFlyout();
        }, 180);
    }

    function hideFlyout() {
        if (flyoutEl) {
            flyoutEl.classList.remove('show');
            flyoutEl.classList.add('d-none');
        }
        activeFlyoutItem = null;
        if (closeTimer) {
            clearTimeout(closeTimer);
            closeTimer = null;
        }
    }

    submenuItems.forEach(function (item) {
        const parentBtn = item.querySelector('.sidebar-nav-parent');
        if (!parentBtn) return;

        item.addEventListener('mouseenter', function () {
            if (isSidebarCollapsed()) {
                openFlyout(item);
            }
        });

        item.addEventListener('mouseleave', function () {
            if (isSidebarCollapsed()) {
                scheduleClose();
            }
        });

        // Click on parent icon in collapsed mode toggles floating flyout
        parentBtn.addEventListener('click', function (e) {
            if (isSidebarCollapsed()) {
                e.preventDefault();
                e.stopPropagation();
                if (flyoutEl.classList.contains('show') && activeFlyoutItem === item) {
                    hideFlyout();
                } else {
                    openFlyout(item);
                }
            }
        }, true);
    });

    // Close on click outside
    document.addEventListener('click', function (e) {
        if (!isSidebarCollapsed()) return;
        if (flyoutEl && !flyoutEl.contains(e.target) && !e.target.closest('.sidebar-nav-item.has-submenu')) {
            hideFlyout();
        }
    });

    // Close on resize or scroll
    window.addEventListener('resize', hideFlyout);
    window.addEventListener('scroll', function () {
        if (isSidebarCollapsed()) {
            hideFlyout();
        }
    }, { passive: true });

    const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
    if (sidebarToggleBtn) {
        sidebarToggleBtn.addEventListener('click', hideFlyout);
    }
}

/**
 * 3. Bootstrap Modal Confirmation Dialog
 */
/**
 * 3. Bootstrap Modal Confirmation Dialog (Replaces native browser confirm())
 */
function initConfirmModal() {
    const modalEl = document.getElementById('globalConfirmModal');
    if (!modalEl) return;

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const modalMessage = document.getElementById('globalConfirmMessage');
    const modalTitle   = document.getElementById('globalConfirmTitle');
    const iconEl       = document.getElementById('globalConfirmIcon');
    const iconWrapper  = document.getElementById('globalConfirmIconWrapper');
    const confirmBtn   = document.getElementById('globalConfirmBtn');
    const confirmBtnText = document.getElementById('globalConfirmBtnText');

    let pendingAction = null;
    let pendingCancel = null;

    function parseConfirmConfig(message, element) {
        const text = (message || '').toLowerCase();
        let variant = 'primary';
        let iconClass = 'bi-question-circle-fill';
        let title = 'Confirm Action';
        let btnText = 'Confirm';
        let btnClass = 'btn-primary';

        const explicitType = element ? element.getAttribute('data-confirm-type') : null;
        const explicitTitle = element ? element.getAttribute('data-confirm-title') : null;
        const explicitBtn = element ? element.getAttribute('data-confirm-btn') : null;

        if (explicitType === 'danger' || (!explicitType && (
            text.includes('deactivate') || text.includes('delete') || text.includes('remove') ||
            text.includes('reject') || text.includes('cancel') || text.includes('revoke') || text.includes('lock')
        ))) {
            variant = 'danger';
            iconClass = 'bi-exclamation-triangle-fill';
            title = 'Confirm Action';
            btnText = 'Confirm';
            btnClass = 'btn-danger';

            if (text.includes('deactivate')) {
                title = 'Deactivate Account';
                btnText = 'Deactivate';
            } else if (text.includes('delete')) {
                title = 'Delete Confirmation';
                btnText = 'Delete';
            } else if (text.includes('remove')) {
                title = 'Remove Role';
                btnText = 'Remove';
            } else if (text.includes('reject')) {
                title = 'Reject Request';
                btnText = 'Reject';
            } else if (text.includes('cancel')) {
                title = 'Cancel Application';
                btnText = 'Cancel';
            }
        } else if (explicitType === 'success' || (!explicitType && (
            text.includes('activate') || text.includes('approve') || text.includes('accept') || text.includes('convert')
        ))) {
            variant = 'success';
            iconClass = 'bi-check-circle-fill';
            title = 'Confirm Action';
            btnText = 'Approve';
            btnClass = 'btn-success';

            if (text.includes('activate')) {
                title = 'Activate Account';
                btnText = 'Activate';
            } else if (text.includes('convert')) {
                title = 'Convert to Employee';
                btnText = 'Convert';
            }
        } else if (explicitType === 'warning' || (!explicitType && (
            text.includes('transfer') || text.includes('reset') || text.includes('group administrator')
        ))) {
            variant = 'warning';
            iconClass = 'bi-shield-exclamation';
            title = 'Confirm Privilege Update';
            btnText = 'Update';
            btnClass = 'btn-warning text-dark';
        }

        if (explicitTitle) title = explicitTitle;
        if (explicitBtn) btnText = explicitBtn;

        return { variant, iconClass, title, btnText, btnClass };
    }

    window.showConfirmModal = function (options) {
        options = options || {};
        const config = parseConfirmConfig(options.message, options.element);

        if (modalMessage) modalMessage.textContent = options.message || 'Are you sure you want to proceed with this action?';
        if (modalTitle) modalTitle.textContent = options.title || config.title;
        if (iconEl) iconEl.className = 'bi ' + (options.icon || config.iconClass) + ' fs-2';
        if (iconWrapper) iconWrapper.className = 'confirm-icon-wrapper mb-3 mx-auto variant-' + (options.variant || config.variant);
        if (confirmBtn) {
            confirmBtn.className = 'btn px-4 py-2 fw-semibold rounded-3 flex-fill d-inline-flex align-items-center justify-content-center gap-2 ' + (options.btnClass || config.btnClass);
        }
        if (confirmBtnText) confirmBtnText.textContent = options.btnText || config.btnText;

        pendingAction = options.onConfirm || null;
        pendingCancel = options.onCancel || null;

        modal.show();
    };

    // Auto-upgrade legacy inline confirm(...) attributes
    function autoUpgradeConfirmAttributes() {
        document.querySelectorAll('form[onsubmit*="confirm("]').forEach(function (form) {
            const raw = form.getAttribute('onsubmit') || '';
            const match = raw.match(/confirm\(\s*(['"])(.*?)\1\s*\)/s);
            if (match) {
                form.removeAttribute('onsubmit');
                form.setAttribute('data-confirm', match[2].replace(/\\'/g, "'").replace(/\\"/g, '"'));
            }
        });

        document.querySelectorAll('[onclick*="confirm("]').forEach(function (el) {
            const raw = el.getAttribute('onclick') || '';
            const match = raw.match(/confirm\(\s*(['"])(.*?)\1\s*\)/s);
            if (match) {
                el.removeAttribute('onclick');
                el.setAttribute('data-confirm', match[2].replace(/\\'/g, "'").replace(/\\"/g, '"'));
            }
        });
    }

    autoUpgradeConfirmAttributes();

    // Confirm button click
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function () {
            const act = pendingAction;
            pendingAction = null;
            pendingCancel = null;
            modal.hide();
            if (typeof act === 'function') {
                act();
            }
        });
    }

    // Modal hidden / cancelled
    modalEl.addEventListener('hidden.bs.modal', function () {
        if (typeof pendingCancel === 'function') {
            const cancelAct = pendingCancel;
            pendingCancel = null;
            cancelAct();
        }
        pendingAction = null;
    });

    // Form submission interception (Capture phase to run before any bubble listeners)
    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (!form) return;

        const confirmMsg = form.getAttribute('data-confirm');
        if (confirmMsg && !form.dataset.confirmed) {
            e.preventDefault();
            e.stopPropagation();

            const submitter = form._lastSubmitter || null;
            form._lastSubmitter = null;

            window.showConfirmModal({
                message: confirmMsg,
                element: form,
                onConfirm: function () {
                    form.dataset.confirmed = 'true';
                    if (submitter && form.requestSubmit) {
                        form.requestSubmit(submitter);
                    } else if (form.requestSubmit) {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                    setTimeout(function () {
                        delete form.dataset.confirmed;
                    }, 500);
                }
            });
        }
    }, true);

    // Track which submit button was clicked in forms
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('button[type="submit"], input[type="submit"]');
        if (btn && btn.form) {
            btn.form._lastSubmitter = btn;
        }

        const target = e.target.closest('[data-confirm]');
        if (!target) return;

        // If it's a submit button with its own specific data-confirm
        if ((target.type === 'submit' || target.getAttribute('type') === 'submit') && target.form) {
            const form = target.form;
            if (!form.dataset.confirmed && target.getAttribute('data-confirm')) {
                e.preventDefault();
                e.stopPropagation();
                const confirmMsg = target.getAttribute('data-confirm');
                window.showConfirmModal({
                    message: confirmMsg,
                    element: target,
                    onConfirm: function () {
                        form.dataset.confirmed = 'true';
                        if (form.requestSubmit) {
                            form.requestSubmit(target);
                        } else {
                            form.submit();
                        }
                        setTimeout(function () {
                            delete form.dataset.confirmed;
                        }, 500);
                    }
                });
            }
            return;
        }

        // If it's a regular link or anchor
        if (target.tagName === 'A' && !target.dataset.confirmed) {
            e.preventDefault();
            e.stopPropagation();
            const confirmMsg = target.getAttribute('data-confirm');
            window.showConfirmModal({
                message: confirmMsg,
                element: target,
                onConfirm: function () {
                    target.dataset.confirmed = 'true';
                    window.location.href = target.href;
                }
            });
        }
    }, true);
}

/**
 * 4. Password Show / Hide Toggle
 */
function initPasswordToggle() {
    document.addEventListener('click', function (e) {
        const toggleBtn = e.target.closest('.btn-toggle-password');
        if (!toggleBtn) return;

        const targetId = toggleBtn.getAttribute('data-target') || 'password';
        const input = document.getElementById(targetId) || toggleBtn.parentElement.querySelector('input');
        if (!input) return;

        const isPassword = (input.type === 'password');
        const icon = toggleBtn.querySelector('i');
        if (isPassword) {
            input.type = 'text';
            toggleBtn.setAttribute('aria-pressed', 'true');
            toggleBtn.setAttribute('aria-label', 'Hide password');
            toggleBtn.setAttribute('title', 'Hide password');
            if (icon) {
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            }
        } else {
            input.type = 'password';
            toggleBtn.setAttribute('aria-pressed', 'false');
            toggleBtn.setAttribute('aria-label', 'Show password');
            toggleBtn.setAttribute('title', 'Show password');
            if (icon) {
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        }
        input.focus();
    });
}

/**
 * 5. Toast Flash Notifications Auto-Dismiss (5s for success/info, sticky for danger/warning)
 */
function initFlashAutoDismiss() {
    const flashAlerts = document.querySelectorAll('.toast-flash, .alert-dismissible');
    flashAlerts.forEach(function (alert) {
        // Sticky for danger/warning per design specification
        if (alert.classList.contains('alert-danger') || alert.classList.contains('alert-warning')) {
            return;
        }
        setTimeout(function () {
            if (alert && alert.parentElement) {
                alert.classList.remove('show');
                setTimeout(function () {
                    alert.remove();
                }, 250);
            }
        }, 5000);
    });
}

/**
 * 6. Accessibility & Keyboard Shortcuts
 */
function initKeyboardShortcuts() {
    document.addEventListener('keydown', function (e) {
        // Escape closes mobile sidebar
        if (e.key === 'Escape') {
            const sidebar = document.querySelector('.app-sidebar.show');
            if (sidebar || document.body.classList.contains('sidebar-open')) {
                if (sidebar) sidebar.classList.remove('show');
                document.body.classList.remove('sidebar-open');
                const backdrop = document.getElementById('sidebarBackdrop');
                if (backdrop) backdrop.remove();
            }
        }
    });
}

/**
 * 7. Stat Numbers Count-Up Animation (800ms, skipped on 0 or reduced motion)
 */
function initStatCountUp() {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const statElements = document.querySelectorAll('.stat-number');
    statElements.forEach(function (el) {
        const rawText = el.textContent.trim().replace(/,/g, '');
        const targetValue = parseFloat(rawText);
        if (isNaN(targetValue) || targetValue <= 0) return;

        const isDecimal = rawText.includes('.');
        const duration = 800;
        const startTime = performance.now();

        function step(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            // Ease-out cubic
            const ease = 1 - Math.pow(1 - progress, 3);
            const current = targetValue * ease;

            el.textContent = isDecimal ? current.toFixed(1) : Math.round(current).toLocaleString();

            if (progress < 1) {
                requestAnimationFrame(step);
            } else {
                el.textContent = isDecimal ? targetValue.toFixed(1) : Math.round(targetValue).toLocaleString();
            }
        }

        requestAnimationFrame(step);
    });
}

/**
 * 8. Unified Global Search (Dropdown preview, debounced API, keyboard shortcuts)
 */
function initGlobalSearch() {
    const searchContainer = document.getElementById('topbarSearchContainer');
    const searchInput = document.getElementById('globalSearchInput');
    const dropdown = document.getElementById('globalSearchResultsDropdown');
    const dropdownBody = document.getElementById('globalSearchDropdownBody');
    const dropdownLoading = document.getElementById('globalSearchLoading');
    const clearBtn = document.getElementById('globalSearchClearBtn');
    const mobileOpenBtn = document.getElementById('mobileSearchOpenBtn');
    const mobileCloseBtn = document.getElementById('mobileSearchCloseBtn');
    const fullLink = document.getElementById('globalSearchFullLink');
    const countHint = document.getElementById('globalSearchCountHint');

    if (!searchInput) return;

    let debounceTimer = null;
    let abortController = null;
    let activeIndex = -1;

    const baseUrl = (typeof window.__AAZA_BASE_URL__ === 'string') ? window.__AAZA_BASE_URL__ : '';
    const cleanBase = baseUrl.replace(/\/+$/, '');
    const apiEndpoint = cleanBase + '/modules/search/api.php';
    const fullSearchUrl = cleanBase + '/modules/search/index.php';

    // Intercept form submit if active dropdown item is highlighted
    const searchForm = document.getElementById('topbarSearchBox');
    if (searchForm && searchForm.tagName.toLowerCase() === 'form') {
        searchForm.addEventListener('submit', function (e) {
            const items = dropdownBody ? dropdownBody.querySelectorAll('.search-dropdown-item') : [];
            if (activeIndex >= 0 && items[activeIndex]) {
                e.preventDefault();
                window.location.href = items[activeIndex].href;
                return;
            }
            const val = searchInput.value.trim();
            if (val.length === 0) {
                e.preventDefault();
            }
        });
    }

    // 1. Keyboard Shortcuts: "/" or "Ctrl+K" / "Cmd+K"
    document.addEventListener('keydown', function (e) {
        const activeTag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
        const isEditable = document.activeElement && document.activeElement.isContentEditable;
        const inInput = ['input', 'textarea', 'select'].includes(activeTag) || isEditable;

        // "/" shortcut (only outside form inputs)
        if (e.key === '/' && !inInput && !e.ctrlKey && !e.metaKey && !e.altKey) {
            e.preventDefault();
            openAndFocus();
            return;
        }

        // Ctrl+K / Cmd+K (everywhere)
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            openAndFocus();
            return;
        }
    });

    function openAndFocus() {
        if (window.innerWidth < 576 && searchContainer) {
            searchContainer.classList.add('search-mobile-active');
            document.body.classList.add('search-open-mobile');
        }
        searchInput.focus();
        searchInput.select();
    }

    if (mobileOpenBtn) {
        mobileOpenBtn.addEventListener('click', function () {
            openAndFocus();
        });
    }
    if (mobileCloseBtn) {
        mobileCloseBtn.addEventListener('click', function () {
            closeMobileSearch();
        });
    }

    function closeMobileSearch() {
        if (searchContainer) {
            searchContainer.classList.remove('search-mobile-active');
            document.body.classList.remove('search-open-mobile');
        }
        hideDropdown();
        searchInput.blur();
    }

    if (clearBtn) {
        if (searchInput.value.trim().length > 0) {
            clearBtn.classList.remove('d-none');
        } else {
            clearBtn.classList.add('d-none');
        }

        clearBtn.addEventListener('click', function () {
            searchInput.value = '';
            clearBtn.classList.add('d-none');
            hideDropdown();
            searchInput.focus();
        });
    }

    // Debounced input handler (250ms)
    searchInput.addEventListener('input', function () {
        const val = searchInput.value.trim();
        if (clearBtn) {
            if (val.length > 0) clearBtn.classList.remove('d-none');
            else clearBtn.classList.add('d-none');
        }

        if (debounceTimer) clearTimeout(debounceTimer);

        if (val.length < 2) {
            hideDropdown();
            return;
        }

        debounceTimer = setTimeout(function () {
            fetchSearchResults(val);
        }, 250);
    });

    function fetchSearchResults(term) {
        if (abortController) {
            abortController.abort();
        }
        abortController = new AbortController();

        showLoading();

        fetch(apiEndpoint + '?q=' + encodeURIComponent(term), {
            signal: abortController.signal,
            headers: { 'Accept': 'application/json' }
        })
        .then(function (res) {
            if (!res.ok) throw new Error('Status: ' + res.status);
            return res.json();
        })
        .then(function (data) {
            renderDropdown(term, data);
        })
        .catch(function (err) {
            if (err.name === 'AbortError') return;
            renderError();
        });
    }

    function showLoading() {
        if (dropdown) dropdown.classList.remove('d-none');
        if (dropdownLoading) dropdownLoading.classList.remove('d-none');
        if (dropdownBody) dropdownBody.innerHTML = '';
        activeIndex = -1;
    }

    function hideDropdown() {
        if (dropdown) dropdown.classList.add('d-none');
        activeIndex = -1;
    }

    function renderDropdown(term, data) {
        if (dropdownLoading) dropdownLoading.classList.add('d-none');
        if (!dropdownBody) return;

        const groups = data.groups || [];
        const total = data.total_count || 0;

        if (fullLink) {
            fullLink.href = fullSearchUrl + '?q=' + encodeURIComponent(term);
        }
        if (countHint) {
            countHint.textContent = total > 0
                ? total + ' matching record' + (total === 1 ? '' : 's')
                : 'No matches found';
        }

        if (groups.length === 0 || total === 0) {
            dropdownBody.innerHTML =
                '<div class="px-3 py-4 text-center text-muted">' +
                    '<i class="bi bi-search fs-3 d-block mb-1 opacity-50"></i>' +
                    '<div class="small fw-semibold text-body">No matching records</div>' +
                    '<div class="small text-muted">Try searching with a different keyword or code</div>' +
                '</div>';
            if (dropdown) dropdown.classList.remove('d-none');
            return;
        }

        let html = '';
        groups.forEach(function (group) {
            html += '<div class="search-dropdown-group">';
            html += '<div class="search-dropdown-group-header">';
            html += '<span><i class="bi ' + escapeHtml(group.icon) + ' me-1"></i>' + escapeHtml(group.label) + '</span>';
            html += '<span class="badge badge-soft-secondary rounded-pill">' + group.total + '</span>';
            html += '</div>';

            (group.items || []).forEach(function (item) {
                html += '<a href="' + escapeHtml(item.url) + '" class="search-dropdown-item" role="option">';
                html += '<div class="search-dropdown-item-icon"><i class="bi ' + escapeHtml(item.icon) + '"></i></div>';
                html += '<div class="search-dropdown-item-text">';
                html += '<div class="search-dropdown-item-title">' + (item.highlighted_title || escapeHtml(item.title)) + '</div>';
                html += '<div class="search-dropdown-item-sub">' + (item.subtitle || '') + '</div>';
                html += '</div>';
                html += '<i class="bi bi-chevron-right search-dropdown-item-arrow"></i>';
                html += '</a>';
            });
            html += '</div>';
        });

        dropdownBody.innerHTML = html;
        if (dropdown) dropdown.classList.remove('d-none');
        activeIndex = -1;
    }

    function renderError() {
        if (dropdownLoading) dropdownLoading.classList.add('d-none');
        if (!dropdownBody) return;
        dropdownBody.innerHTML =
            '<div class="px-3 py-3 text-center text-danger small">' +
                '<i class="bi bi-exclamation-circle me-1"></i>Unable to load search results. Please try again.' +
            '</div>';
        if (dropdown) dropdown.classList.remove('d-none');
    }

    // Keyboard navigation (Arrow keys + Enter + Escape)
    searchInput.addEventListener('keydown', function (e) {
        const items = dropdownBody ? dropdownBody.querySelectorAll('.search-dropdown-item') : [];

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (dropdown && dropdown.classList.contains('d-none')) {
                const val = searchInput.value.trim();
                if (val.length >= 2) fetchSearchResults(val);
                return;
            }
            if (items.length > 0) {
                activeIndex = (activeIndex + 1) % items.length;
                updateActiveItem(items);
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (items.length > 0) {
                activeIndex = (activeIndex - 1 + items.length) % items.length;
                updateActiveItem(items);
            }
        } else if (e.key === 'Enter') {
            const val = searchInput.value.trim();
            if (activeIndex >= 0 && items[activeIndex]) {
                e.preventDefault();
                window.location.href = items[activeIndex].href;
            } else if (val.length > 0) {
                e.preventDefault();
                window.location.href = fullSearchUrl + '?q=' + encodeURIComponent(val);
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            if (dropdown && !dropdown.classList.contains('d-none')) {
                hideDropdown();
            } else if (window.innerWidth < 576 && searchContainer && searchContainer.classList.contains('search-mobile-active')) {
                closeMobileSearch();
            } else {
                searchInput.blur();
            }
        }
    });

    function updateActiveItem(items) {
        items.forEach(function (el, idx) {
            if (idx === activeIndex) {
                el.classList.add('active');
                el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            } else {
                el.classList.remove('active');
            }
        });
    }

    document.addEventListener('click', function (e) {
        if (searchContainer && !searchContainer.contains(e.target)) {
            hideDropdown();
        }
    });

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
}

/**
 * 9. Generic Search Clear Buttons (.btn-input-clear)
 */
function initGenericSearchClear() {
    document.addEventListener('input', function (e) {
        const input = e.target;
        if (!input || input.type !== 'search') return;
        const container = input.closest('.input-group') || input.parentElement;
        if (!container) return;
        const clearBtn = container.querySelector('.btn-input-clear');
        if (!clearBtn) return;
        if (input.value.trim().length > 0) {
            clearBtn.classList.remove('d-none');
        } else {
            clearBtn.classList.add('d-none');
        }
    });

    document.addEventListener('click', function (e) {
        const clearBtn = e.target.closest('.btn-input-clear');
        if (!clearBtn) return;
        const container = clearBtn.closest('.input-group') || clearBtn.parentElement;
        if (!container) return;
        const input = container.querySelector('input[type="search"], input[type="text"]');
        if (!input) return;
        input.value = '';
        clearBtn.classList.add('d-none');
        input.focus();
        if (input.form) {
            input.form.submit();
        }
    });
}


/**
 * 11. Topbar Notifications Controller
 * - Polls modules/notifications/api.php every 60s
 * - Updates unread count badge
 * - Renders recent 8 notifications in the dropdown
 * - Mark as read on click and redirects to link_url
 * - Mark all as read action
 */
function initNotifications() {
    const bellBtn = document.getElementById('topbarNotificationsBtn');
    const badge = document.getElementById('topbarNotifBadge');
    const list = document.getElementById('topbarNotifList');
    const markAllBtn = document.getElementById('topbarNotifMarkAllReadBtn');
    if (!bellBtn || !badge || !list) return;

    const rawBase = (typeof window.__AAZA_BASE_URL__ === 'string') ? window.__AAZA_BASE_URL__ : '';
    const cleanBase = rawBase.replace(/\/+$/, '');
    const apiEndpoint = cleanBase + '/modules/notifications/api.php';

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    let isPolling = false;

    function renderNotifications(items, unreadCount) {
        // Update badge
        if (unreadCount > 0) {
            badge.textContent = unreadCount > 99 ? '99+' : unreadCount;
            badge.classList.remove('d-none');
        } else {
            badge.classList.add('d-none');
            badge.textContent = '0';
        }

        // Render list
        if (!items || items.length === 0) {
            list.innerHTML = `
                <div class="p-4 text-center text-muted small notif-empty">
                    <i class="bi bi-bell-slash fs-3 d-block mb-1 text-secondary opacity-50"></i>
                    No notifications
                </div>
            `;
            return;
        }

        let html = '';
        items.forEach(function (item) {
            const isUnread = !item.is_read;
            const bgOpacity = '18';
            html += `
                <a class="notif-dropdown-item ${isUnread ? 'is-unread' : ''}" data-id="${item.id}" data-href="${escapeHtml(item.link_url || '')}" href="${escapeHtml(item.link_url || '#')}">
                    <div class="notif-icon-circle" style="background-color: ${escapeHtml(item.color)}${bgOpacity}; color: ${escapeHtml(item.color)};">
                        <i class="bi ${escapeHtml(item.icon)}"></i>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex align-items-center justify-content-between gap-1">
                            <span class="notif-title text-truncate">${escapeHtml(item.title)}</span>
                            <span class="notif-time flex-shrink-0">${escapeHtml(item.time_ago)}</span>
                        </div>
                        <div class="notif-message">${escapeHtml(item.message)}</div>
                    </div>
                </a>
            `;
        });

        list.innerHTML = html;
        attachItemClickHandlers();
    }

    function attachItemClickHandlers() {
        const renderedItems = list.querySelectorAll('.notif-dropdown-item');
        renderedItems.forEach(function (el) {
            if (el.dataset.hasNotifHandler) return;
            el.dataset.hasNotifHandler = 'true';

            el.addEventListener('click', function (e) {
                e.preventDefault();
                const notifId = this.getAttribute('data-id');
                const targetHref = this.getAttribute('data-href');

                // If already read, navigate immediately
                if (!this.classList.contains('is-unread') && targetHref && targetHref !== '#' && targetHref !== '') {
                    window.location.href = targetHref;
                    return;
                }

                // Visually mark read immediately for snappy feedback
                this.classList.remove('is-unread');

                // Mark read via API
                fetch(apiEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-Token': getCsrfToken()
                    },
                    body: 'action=mark_read&id=' + encodeURIComponent(notifId) + '&csrf_token=' + encodeURIComponent(getCsrfToken())
                }).then(function (res) {
                    return res.json();
                }).then(function (data) {
                    if (data && data.success) {
                        if (typeof data.unread_count === 'number') {
                            if (data.unread_count > 0) {
                                badge.textContent = data.unread_count > 99 ? '99+' : data.unread_count;
                                badge.classList.remove('d-none');
                            } else {
                                badge.classList.add('d-none');
                                badge.textContent = '0';
                            }
                        }
                    }
                }).catch(function () {}).finally(function () {
                    if (targetHref && targetHref !== '' && targetHref !== '#') {
                        window.location.href = targetHref;
                    }
                });
            });
        });
    }

    // Attach click handlers to any pre-rendered server items
    attachItemClickHandlers();

    function fetchNotifications() {
        if (isPolling) return;
        isPolling = true;

        fetch(apiEndpoint + '?action=poll&limit=8', {
            method: 'GET',
            headers: { 'Accept': 'application/json' }
        })
        .then(function (res) {
            if (!res.ok) throw new Error('Network error');
            return res.json();
        })
        .then(function (data) {
            if (data && data.success) {
                renderNotifications(data.notifications, data.unread_count);
            }
        })
        .catch(function () {})
        .finally(function () {
            isPolling = false;
        });
    }

    // Mark all as read button handler
    if (markAllBtn) {
        markAllBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            fetch(apiEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': getCsrfToken()
                },
                body: 'action=mark_read&mark_all=1&csrf_token=' + encodeURIComponent(getCsrfToken())
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.success) {
                    badge.classList.add('d-none');
                    badge.textContent = '0';
                    const items = list.querySelectorAll('.notif-dropdown-item.is-unread');
                    items.forEach(function (el) { el.classList.remove('is-unread'); });
                }
            })
            .catch(function () {});
        });
    }

    // Refresh when user clicks the bell button
    bellBtn.addEventListener('click', function () {
        fetchNotifications();
    });

    // Initial fetch
    fetchNotifications();

    // Poll every 60 seconds
    setInterval(fetchNotifications, 60000);

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
}

/**
 * 14. Action Menus (Kebab dropdowns inside tables/cards)
 * Configures fixed Popper positioning so dropdowns are never clipped by .table-responsive or cards.
 */
function initActionMenus() {
    if (typeof bootstrap === 'undefined' || !bootstrap.Dropdown) return;

    function setupDropdown(el) {
        if (!el || el.dataset.actionMenuInit) return;
        el.dataset.actionMenuInit = 'true';
        new bootstrap.Dropdown(el, {
            popperConfig: function (defaultBsPopperConfig) {
                return Object.assign({}, defaultBsPopperConfig, {
                    strategy: 'fixed',
                    modifiers: [
                        {
                            name: 'preventOverflow',
                            options: {
                                boundary: 'viewport',
                                padding: 8
                            }
                        }
                    ]
                });
            }
        });
    }

    document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(setupDropdown);

    // Also support dynamically added dropdowns or lazy init on click
    document.addEventListener('show.bs.dropdown', function (e) {
        const toggle = e.target;
        if (toggle && !toggle.dataset.actionMenuInit) {
            setupDropdown(toggle);
        }
    });
}

/**
 * 15. Global Double-Click (desktop) & Double-Tap (mobile) Row Navigation
 * Automatically handles any table row or element with class .clickable-row or [data-href]
 */
function initClickableRows() {
    const rowSelector = '.clickable-row, [data-href], .offer-row, .employee-row, .candidate-row, .vacancy-row, .interview-row, .company-row, .leave-row';

    // Desktop double-click
    document.addEventListener('dblclick', function (e) {
        const row = e.target.closest(rowSelector);
        if (!row) return;

        // Ignore clicks on buttons, inputs, form elements, dropdown toggles, and action column buttons
        if (e.target.closest('button, select, input, textarea, .dropdown-menu, .btn, .action-col, [data-bs-toggle], form, a.btn, a.dropdown-item')) {
            return;
        }

        const href = row.getAttribute('data-href');
        if (href) {
            row.classList.add('row-activating');
            window.location.href = href;
        }
    });

    // Mobile / Touch double-tap detection (within 400ms, non-scroll taps only)
    let lastTapTime = 0;
    let lastTapTarget = null;
    let touchStartX = 0;
    let touchStartY = 0;
    let touchMoved = false;

    document.addEventListener('touchstart', function (e) {
        if (e.touches && e.touches.length === 1) {
            touchStartX = e.touches[0].clientX;
            touchStartY = e.touches[0].clientY;
            touchMoved = false;
        }
    }, { passive: true });

    document.addEventListener('touchmove', function (e) {
        if (e.touches && e.touches.length === 1) {
            const dx = Math.abs(e.touches[0].clientX - touchStartX);
            const dy = Math.abs(e.touches[0].clientY - touchStartY);
            if (dx > 10 || dy > 10) {
                touchMoved = true;
            }
        }
    }, { passive: true });

    document.addEventListener('touchend', function (e) {
        if (touchMoved) return; // User was scrolling, not tapping

        const row = e.target.closest(rowSelector);
        if (!row) return;

        if (e.target.closest('button, select, input, textarea, .dropdown-menu, .btn, .action-col, [data-bs-toggle], form, a.btn, a.dropdown-item')) {
            return;
        }

        const href = row.getAttribute('data-href');
        if (!href) return;

        const now = Date.now();
        const tapLength = now - lastTapTime;
        if (tapLength > 50 && tapLength < 400 && lastTapTarget === row) {
            e.preventDefault();
            row.classList.add('row-activating');
            window.location.href = href;
        }
        lastTapTime = now;
        lastTapTarget = row;
    });
}

