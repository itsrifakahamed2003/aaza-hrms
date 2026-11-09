<?php
declare(strict_types=1);

/**
 * AaZa HRMS - Reusable UI Helper Components
 * Clean, consistent enterprise SaaS presentation helpers.
 */

/**
 * Render a standardized page header with breadcrumb, title, subtitle, optional badge, and action buttons.
 *
 * @param string $title Main page heading
 * @param array<string, string> $breadcrumbs Associative array ['Label' => 'path/or/url'] or empty string for active item
 * @param array<int, array{label: string, url: string, icon?: string, class?: string, permission?: bool}> $actions Action buttons
 * @param string $subtitle Optional descriptive subtext
 * @param string|null $badge Optional status or scope badge HTML/text
 */
function ui_page_header(
    string $title,
    array $breadcrumbs = [],
    array $actions = [],
    string $subtitle = '',
    ?string $badge = null
): string {
    $out = '<div class="page-header d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">';
    $out .= '<div>';

    if (!empty($breadcrumbs)) {
        $out .= '<nav aria-label="breadcrumb">';
        $out .= '<ol class="breadcrumb breadcrumb-clean mb-1">';
        $lastIdx = count($breadcrumbs) - 1;
        $i = 0;
        foreach ($breadcrumbs as $bLabel => $bUrl) {
            $isLast = ($i === $lastIdx);
            if ($isLast || empty($bUrl)) {
                $out .= '<li class="breadcrumb-item active" aria-current="page">' . e($bLabel) . '</li>';
            } else {
                $out .= '<li class="breadcrumb-item"><a href="' . e(url($bUrl)) . '">' . e($bLabel) . '</a></li>';
            }
            $i++;
        }
        $out .= '</ol>';
        $out .= '</nav>';
    }

    $out .= '<div class="d-flex align-items-center gap-2 flex-wrap">';
    $out .= '<h1 class="page-header-title mb-0">' . e($title) . '</h1>';
    if ($badge !== null) {
        $out .= $badge;
    }
    $out .= '</div>';

    if ($subtitle !== '') {
        $out .= '<p class="page-header-desc mb-0 mt-1">' . e($subtitle) . '</p>';
    }

    $out .= '</div>';

    if (!empty($actions)) {
        $out .= '<div class="page-header-actions d-flex gap-2 align-items-center flex-wrap">';
        foreach ($actions as $act) {
            if (isset($act['permission']) && !$act['permission']) {
                continue;
            }
            $actClass = $act['class'] ?? 'btn btn-sm btn-outline-secondary';
            $actIcon = !empty($act['icon']) ? '<i class="bi ' . e($act['icon']) . ' me-1"></i>' : '';
            $out .= '<a href="' . e(url($act['url'])) . '" class="' . e($actClass) . '">';
            $out .= $actIcon . e($act['label']);
            $out .= '</a>';
        }
        $out .= '</div>';
    }

    $out .= '</div>';
    return $out;
}

/**
 * Render a standardized KPI stat card.
 *
 * @param string $label Metric title
 * @param int|float|string $value Numeric or text display
 * @param string $icon Bootstrap icon class (e.g. 'bi-people')
 * @param string $color Semantic color: 'primary', 'success', 'warning', 'danger', 'info', 'secondary'
 * @param string|null $subtext Caption under value
 * @param string|null $link Optional URL for drilldown
 * @param string|null $linkLabel Optional text for drilldown link (default 'View &rarr;')
 * @param bool $isRestricted Whether the value is masked due to missing permissions
 */
function ui_stat_card(
    string $label,
    int|float|string $value,
    string $icon,
    string $color = 'primary',
    ?string $subtext = null,
    ?string $link = null,
    ?string $linkLabel = 'View &rarr;',
    bool $isRestricted = false
): string {
    $out = '<div class="stat-card h-100">';
    $out .= '<div class="stat-card-header d-flex justify-content-between align-items-start">';
    $out .= '<div>';
    $out .= '<span class="stat-label">' . e($label) . '</span>';
    if ($isRestricted) {
        $out .= '<div class="stat-number fs-6 text-muted"><i class="bi bi-lock me-1"></i>Restricted</div>';
    } else {
        $valFormatted = is_numeric($value) ? number_format((float)$value) : (string)$value;
        $out .= '<div class="stat-number text-' . e($color) . '">' . e($valFormatted) . '</div>';
    }
    $out .= '</div>';
    $out .= '<div class="stat-icon stat-icon-' . e($color) . '">';
    $out .= '<i class="bi ' . e($icon) . '"></i>';
    $out .= '</div>';
    $out .= '</div>';

    if ($subtext !== null || ($link !== null && !$isRestricted)) {
        $out .= '<div class="stat-card-footer mt-2 pt-2 border-top d-flex justify-content-between align-items-center">';
        $out .= '<span class="small text-muted">' . e($subtext ?? '') . '</span>';
        if ($link !== null && !$isRestricted) {
            $out .= '<a href="' . e(url($link)) . '" class="small fw-semibold text-' . e($color) . ' text-decoration-none">';
            $out .= $linkLabel . '</a>';
        }
        $out .= '</div>';
    }

    $out .= '</div>';
    return $out;
}

/**
 * ONE unified mapping function for badges across the entire system.
 *
 * @param string $domain Entity domain ('application', 'interview', 'offer', 'employee', 'leave', 'attendance', 'vacancy', 'candidate')
 * @param string $status Internal status key
 * @param string|null $label Optional label override
 */
function status_badge(string $domain, string $status, ?string $label = null): string {
    $domain = strtolower(trim($domain));
    $cleanStatus = strtolower(trim($status));

    // Custom label mappings if not provided
    if ($label === null) {
        $labels = match ($domain) {
            'vacancy' => function_exists('vacancy_status_list') ? vacancy_status_list() : [],
            'application', 'candidate' => function_exists('application_status_list') ? application_status_list() : [],
            'interview' => function_exists('interview_status_list') ? interview_status_list() : [],
            'offer' => function_exists('offer_status_list') ? offer_status_list() : [],
            'employee' => function_exists('employee_status_list') ? employee_status_list() : [],
            'leave' => function_exists('leave_status_list') ? leave_status_list() : [],
            'attendance' => function_exists('attendance_status_list') ? attendance_status_list() : [],
            default => [],
        };
        $displayLabel = $labels[$cleanStatus] ?? ucwords(str_replace('_', ' ', $cleanStatus));
    } else {
        $displayLabel = $label;
    }

    // Semantic color classification per specification (restrained, contrast >= 4.5:1)
    $colorClass = match ($cleanStatus) {
        // Success
        'active', 'open', 'published', 'selected', 'offer_accepted', 'accepted', 'approved', 'present', 'hired', 'completed', 'strong_hire'
            => 'badge-soft-success',

        // Primary / Info
        'hire', 'sent', 'applied', 'under_review', 'shortlisted', 'scheduled', 'interviewed', 'transferred', 'probation', 'notice_period'
            => 'badge-soft-info',

        // Warning
        'pending', 'on_hold', 'interview_scheduled', 'late', 'half_day', 'hold', 'rescheduled', 'on_leave', 'expiring'
            => 'badge-soft-warning',

        // Danger
        'inactive', 'cancelled', 'rejected', 'declined', 'offer_declined', 'terminated', 'absent', 'reject'
            => 'badge-soft-danger',

        // Secondary / Dark
        'draft', 'closed', 'expired', 'withdrawn', 'resigned', 'retired', 'no_show', 'archived', 'suspended', 'holiday', 'weekend'
            => 'badge-soft-secondary',

        default => 'badge-soft-secondary',
    };

    return '<span class="badge ' . $colorClass . '">' . e($displayLabel) . '</span>';
}

/**
 * Render a standardized 3-dot (kebab) action dropdown menu.
 *
 * @param array<int, array<string, mixed>> $items Menu items to render
 * @param array{class?: string, btn_class?: string, aria_label?: string, align?: string} $options
 * @return string Rendered HTML, or empty string if no allowed items
 */
function action_menu(array $items, array $options = []): string {
    // 1. Filter out items where permission or visible is explicitly false
    $validItems = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (isset($item['permission']) && !$item['permission']) {
            continue;
        }
        if (isset($item['visible']) && !$item['visible']) {
            continue;
        }
        $validItems[] = $item;
    }

    if (empty($validItems)) {
        return '';
    }

    // Check if there are actionable items (not just dividers)
    $hasActionable = false;
    foreach ($validItems as $it) {
        if (($it['type'] ?? 'link') !== 'divider') {
            $hasActionable = true;
            break;
        }
    }
    if (!$hasActionable) {
        return '';
    }

    // 2. Partition into standard and danger items unless explicit dividers were provided
    $hasExplicitDivider = false;
    foreach ($validItems as $it) {
        if (($it['type'] ?? '') === 'divider') {
            $hasExplicitDivider = true;
            break;
        }
    }

    $orderedItems = [];
    if ($hasExplicitDivider) {
        $orderedItems = $validItems;
    } else {
        $standardItems = [];
        $dangerItems = [];
        foreach ($validItems as $it) {
            $isDanger = !empty($it['danger'])
                || (isset($it['confirm_type']) && $it['confirm_type'] === 'danger')
                || str_contains(strtolower((string)($it['label'] ?? '')), 'delete')
                || str_contains(strtolower((string)($it['label'] ?? '')), 'deactivate');
            if ($isDanger) {
                $it['danger'] = true;
                $dangerItems[] = $it;
            } else {
                $standardItems[] = $it;
            }
        }

        $orderedItems = $standardItems;
        if (!empty($standardItems) && !empty($dangerItems)) {
            $orderedItems[] = ['type' => 'divider'];
        }
        foreach ($dangerItems as $dit) {
            $orderedItems[] = $dit;
        }
    }

    // 3. Render HTML
    $menuAlign = $options['align'] ?? 'dropdown-menu-end';
    $btnAria = $options['aria_label'] ?? 'Actions';
    $extraMenuClass = !empty($options['class']) ? ' ' . $options['class'] : '';
    $btnClass = $options['btn_class'] ?? 'btn btn-light btn-sm action-menu-btn';

    $out = '<div class="dropdown action-menu d-inline-block' . e($extraMenuClass) . '">';
    $out .= '<button type="button" class="' . e($btnClass) . '" data-bs-toggle="dropdown" data-bs-popper-config=\'{"strategy":"fixed"}\' aria-expanded="false" aria-label="' . e($btnAria) . '" title="' . e($btnAria) . '">';
    $out .= '<i class="bi bi-three-dots-vertical"></i>';
    $out .= '</button>';
    $out .= '<ul class="dropdown-menu ' . e($menuAlign) . ' shadow-sm">';

    foreach ($orderedItems as $item) {
        $type = $item['type'] ?? 'link';
        if ($type === 'divider') {
            $out .= '<li><hr class="dropdown-divider my-1"></li>';
            continue;
        }

        $label = (string)($item['label'] ?? '');
        $icon = !empty($item['icon']) ? '<i class="bi ' . e($item['icon']) . ' me-2"></i>' : '';
        $isDanger = !empty($item['danger']);
        $itemClass = 'dropdown-item d-flex align-items-center' . ($isDanger ? ' text-danger' : '');
        if (!empty($item['class'])) {
            $itemClass .= ' ' . $item['class'];
        }

        if ($type === 'link') {
            $url = !empty($item['url']) ? url((string)$item['url']) : '#';
            $attrs = '';
            if (!empty($item['attributes']) && is_array($item['attributes'])) {
                foreach ($item['attributes'] as $ak => $av) {
                    $attrs .= ' ' . e($ak) . '="' . e((string)$av) . '"';
                }
            }
            if (!empty($item['target'])) {
                $attrs .= ' target="' . e($item['target']) . '"';
            }
            if (!empty($item['confirm'])) {
                $attrs .= ' data-confirm="' . e($item['confirm']) . '"';
                if (!empty($item['confirm_type'])) {
                    $attrs .= ' data-confirm-type="' . e($item['confirm_type']) . '"';
                }
            }
            $out .= '<li>';
            $out .= '<a class="' . e($itemClass) . '" href="' . e($url) . '"' . $attrs . '>';
            $out .= $icon . '<span>' . e($label) . '</span>';
            $out .= '</a>';
            $out .= '</li>';
        } elseif ($type === 'post' || $type === 'form') {
            $url = !empty($item['url']) ? url((string)$item['url']) : '';
            $confirmAttr = '';
            if (!empty($item['confirm'])) {
                $confirmAttr .= ' data-confirm="' . e($item['confirm']) . '"';
                if (!empty($item['confirm_type'])) {
                    $confirmAttr .= ' data-confirm-type="' . e($item['confirm_type']) . '"';
                }
                if (!empty($item['confirm_title'])) {
                    $confirmAttr .= ' data-confirm-title="' . e($item['confirm_title']) . '"';
                }
                if (!empty($item['confirm_btn'])) {
                    $confirmAttr .= ' data-confirm-btn="' . e($item['confirm_btn']) . '"';
                }
            }

            $out .= '<li>';
            $out .= '<form method="post" action="' . e($url) . '" class="m-0 p-0"' . $confirmAttr . '>';
            $out .= csrf_field();
            if (!empty($item['params']) && is_array($item['params'])) {
                foreach ($item['params'] as $pk => $pv) {
                    $out .= '<input type="hidden" name="' . e((string)$pk) . '" value="' . e((string)$pv) . '">';
                }
            }
            $out .= '<button type="submit" class="' . e($itemClass) . '">';
            $out .= $icon . '<span>' . e($label) . '</span>';
            $out .= '</button>';
            $out .= '</form>';
            $out .= '</li>';
        } elseif ($type === 'button') {
            $attrs = '';
            if (!empty($item['modal'])) {
                $attrs .= ' data-bs-toggle="modal" data-bs-target="' . e($item['modal']) . '"';
            }
            if (!empty($item['onclick'])) {
                $attrs .= ' onclick="' . e($item['onclick']) . '"';
            }
            if (!empty($item['attributes']) && is_array($item['attributes'])) {
                foreach ($item['attributes'] as $ak => $av) {
                    $attrs .= ' ' . e($ak) . '="' . e((string)$av) . '"';
                }
            }
            if (!empty($item['confirm'])) {
                $attrs .= ' data-confirm="' . e($item['confirm']) . '"';
                if (!empty($item['confirm_type'])) {
                    $attrs .= ' data-confirm-type="' . e($item['confirm_type']) . '"';
                }
            }
            $out .= '<li>';
            $out .= '<button type="button" class="' . e($itemClass) . '"' . $attrs . '>';
            $out .= $icon . '<span>' . e($label) . '</span>';
            $out .= '</button>';
            $out .= '</li>';
        }
    }

    $out .= '</ul>';
    $out .= '</div>';
    return $out;
}

