<?php
declare(strict_types=1);

/**
 * AaZa HRMS - Standardized Page Header Include (includes/page_header.php)
 * Renders breadcrumb, H1, short description, and right-aligned primary action.
 *
 * Supported variables:
 * - $pageTitle (string, required or fallback to APP_NAME)
 * - $pageBreadcrumbs (array<string, string>, optional)
 * - $pageSubtitle or $pageDescription (string, optional)
 * - $pageActions or $pageHeaderActions (array, optional)
 * - $pageHeaderBadge (string|null, optional)
 */

$phTitle = $pageTitle ?? APP_NAME;
$phBreadcrumbs = $pageBreadcrumbs ?? [];
$phSubtitle = $pageSubtitle ?? ($pageDescription ?? '');
$phActions = $pageActions ?? ($pageHeaderActions ?? []);
$phBadge = $pageHeaderBadge ?? null;

echo ui_page_header($phTitle, $phBreadcrumbs, $phActions, $phSubtitle, $phBadge);
