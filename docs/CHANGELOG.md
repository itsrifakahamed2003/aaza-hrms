# AaZa HRMS - Release Changelog

All notable changes to the AaZa Multi-Company HR & Recruitment Management System are documented here chronologically by project phase.

---

## Phase 1 - Database Architecture & Core Bootstrap
- **Database Schema:** Created base relational schema in `database/schema.sql` supporting multi-tenant companies, RBAC, master organizational data, candidates, applications, interviews, offers, attendance, leave, and activity logs.
- **Core Bootstrap:** Established `includes/bootstrap.php` with PHP `strict_types=1`, session configuration, autoloader, and PDO database abstraction.
- **Helper Utilities:** Implemented security helpers (`e()`, `csrf_token()`, `csrf_field()`, `csrf_require()`), flash messaging (`flash_set()`), and pagination calculation.

---

## Phase 2 - RBAC, Authentication & Multi-Company Tenancy
- **RBAC System:** Seeded system roles (`group_admin`, `group_hr`, `company_admin`, `hr_manager`, `hr_officer`, `employee`) and granular permissions in `database/phase2_roles_seed.sql`.
- **Authentication Engine:** Built secure session-based authentication (`includes/auth.php`) using `password_hash()` and `password_verify()` with constant-time dummy checking.
- **Company Access Scoping:** Built tenant isolation helpers (`accessible_company_ids()`, `require_company_access()`, `company_in_sql()`).
- **User Management:** Implemented administrative modules for user creation, role assignment, and multi-company access mapping.

---

## Phase 3 - Core HR: Company & Employee Master Data
- **Company Management:** Implemented multi-company administrative interfaces for viewing and configuring subsidiaries.
- **Departments & Designations:** Built organizational structure management modules with per-company partitioning.
- **Employee Directory:** Developed employee onboarding and record maintenance (`modules/employees/`), supporting biographical data, compensation records, emergency contacts, and direct report hierarchy.
- **Employee Documents:** Added secure document repository for identity scans, contracts, and educational credentials.

---

## Phase 4 - Employee Lifecycle & Transfers
- **Employment History:** Implemented automatic tracking of salary increments, designation promotions, and department moves in `employment_history`.
- **Inter-Company Transfers:** Created formal transfer workflow (`modules/employees/transfer.php`) moving employees between group entities, reassigning direct subordinates, and updating document ownership while preserving historical records.
- **Status Transitions:** Added handling for active, probation, suspended, and terminated states without hard deletes.

---

## Phase 5 - Recruitment: Vacancies, Candidates & Applications
- **Job Requisitions:** Implemented job vacancies module (`modules/vacancies/`) with department links, open positions count, and application deadlines.
- **Candidate Profiles:** Created deduplicated candidate database (`modules/candidates/`) keyed by email and identity cards.
- **Application Pipeline:** Built recruitment Kanban and pipeline status management (`applied`, `under_review`, `shortlisted`, `rejected`, `withdrawn`).
- **Document Attachments:** Integrated candidate resume and portfolio uploads with strict MIME verification.

---

## Phase 6 - Interview Management & Evaluations
- **Interview Scheduling:** Built `modules/interviews/` supporting single/multi-round interviews across online, phone, and in-person modes.
- **Panel Assignment:** Introduced `interview_panel` junction allowing multiple interviewers per candidate session.
- **Structured Evaluations:** Implemented scorecards (`modules/interviews/evaluate.php`) scoring candidates across technical, communication, and attitude dimensions (1-5 scale) with recommendations (`strong_hire`, `hire`, `hold`, `reject`).
- **Pipeline Auto-Guard:** Linked interview outcomes to candidate application status progressions.

---

## Phase 7 - Job Offers & Candidate-to-Employee Conversion
- **Job Offers:** Created formal offer management (`modules/offers/`) with draft, sent, accepted, declined, expired, and withdrawn lifecycle.
- **Salary Confidentiality:** Enforced `salary.view` permission checks to protect sensitive compensation data across offers and employee profiles.
- **Candidate Conversion:** Built automated onboarding converter transforming accepted candidate offers into active employee records with prefilled biographical data and initial employment history.
- **Status Guards:** Added protections preventing regression of applications in `interview_scheduled`, `offered`, or `hired` states.

---

## Phase 8 - Attendance & Leave Management
- **Daily Attendance:** Implemented daily time tracking (`modules/attendance/`) with present, absent, late, half-day, and leave statuses.
- **Leave Policies:** Built leave types with annual quota definitions and document requirement flags (`modules/leave/types.php`).
- **Leave Workflow:** Created leave request submission with date boundary validation (`parse_ymd()`), overlap prevention, and multi-stage manager approval.
- **Automatic Attendance Synchronization:** Approved leaves automatically insert corresponding attendance records; cancellations safely remove synchronized leave entries.

---

## Phase 9 - Group Dashboard & Analytical Reports
- **Executive Group Dashboard:** Built multi-company dashboard (`modules/dashboard/`) displaying real-time metrics for headcount, attendance, open requisitions, and pending approvals.
- **Comprehensive Reports:** Implemented analytical reports across Headcount, Turnover, Attendance Trends, Leave Utilization, Vacancy Aging, and Interview Evaluation Results.
- **Secure CSV Streaming:** Added high-capacity CSV export endpoints with formula injection protection (neutralizing `=`, `+`, `-`, `@`, `\t`, `\r`).

---

## Phase 10 - Audit Viewer, Security Hardening, Backup System & Test Suite
- **Audit Log Viewer (`modules/audit/`):**
  - Developed full-featured audit viewer with advanced filters (user, company, action prefix, entity, date range).
  - Masked sensitive payload keys (`password`, `salary`, `nic`, `token`, `session`, `card`) with `[hidden]`.
  - Added company-admin scoped audit isolation and group-level CSV export.
- **Security Hardening:**
  - Enforced 10-character minimum password policy with alphanumeric requirements and username/email rejection.
  - Implemented `must_change_password` mandatory first-login password change flow.
  - Dual brute-force lockout: 5 failed attempts per account AND per client IP over 15 minutes.
  - Hardened file download headers with RFC 5987 `filename*` encoding and `X-Content-Type-Options: nosniff`.
  - Configured Content Security Policy (CSP), anti-clickjacking, and strict referrer headers.
  - Masked unhandled exceptions with generic error screens and incident reference codes (`ERR-XXXXXXX`).
  - Added honeypot and IP rate limiting (5 submissions/hour) to public job application portal.
  - Added production warning if `setup.php` remains accessible.
- **Backup & Recovery (`bin/backup.php`):**
  - Built CLI-only automated backup tool utilizing `mysqldump` with secure temporary credentials.
  - Automated `.sql.gz` generation and uploaded assets archiving with automatic 14-day retention rotation.
  - Created complete disaster recovery manual in `docs/BACKUP_RECOVERY.md`.
- **Automated Test Suite (`tests/run_tests.php`):**
  - Engineered standalone 48-assertion test suite verifying company isolation, recruitment flow, status guards, employee transfers, leave balance/overlap logic, uploads, and security helpers against isolated `aaza_hrms_test` database.
- **Documentation:**
  - Published `docs/SECURITY.md`, `docs/ROLES_PERMISSIONS.md`, `docs/DATABASE.md`, `docs/BACKUP_RECOVERY.md`, and complete `README.md`.

---

## Phase 11 - Master UI Redesign, Bug Fixes & Performance Polish
- **100% Offline Asset Suite (`assets/vendor/`):**
  - Downloaded and integrated official offline releases for Bootstrap 5.3.3, Bootstrap Icons 1.11.3 (with `woff`/`woff2` fonts), Chart.js 4.4.7 (`chart.umd.min.js`), and Inter variable font (`inter.css`).
  - Purged all external CDN links from `includes/header.php`, `includes/footer.php`, `public/login.php`, `public/apply.php`, `public/setup.php`, `public/index.php`, and `includes/functions.php`.
  - Hardened `Content-Security-Policy` header to strictly enforce `'self'` without CDN origins.
- **Enterprise Design System (`assets/css/tokens.css`, `assets/css/components.css`):**
  - Designed consistent office software aesthetic with neutral slate scales, standard 4px/8px spacing, Inter typography, 8px border radii, and accessible high-contrast focus rings.
  - Built dark mode with dark navy `#0F1E3A` sidebar in both themes, primary `#2457C5` (light) / `#5B8CFF` (dark), and accent `#C8202F`.
  - Added subtle micro-interactions (<= 250ms), active button states (`transform: scale(0.98)`), and `prefers-reduced-motion` compliance.
- **Dashboard Layout Rebuild & Squashed Bug Resolution (`public/index.php`):**
  - Diagnosed and resolved the squashed dashboard layout bug by replacing unconstrained flex rows with a 12-column CSS Grid (`.dashboard-grid`) having `min-width: 0` child bounds.
  - Enclosed all Chart.js canvas elements within dedicated fixed-height `.chart-canvas-container` wrappers to prevent flex blowout.
  - Replaced legacy row/col wrappers with responsive `.stat-cards-grid` and unified `.stat-card` styling.
  - Connected live theme switching to Chart.js default grid and label colors with real-time chart instance re-renders.
  - Added numeric count-up animation (`initStatCountUp`, 800ms ease-out cubic) for dashboard KPI counters.
- **Unified Status Badges & Component Standardization (`includes/ui.php`):**
  - Introduced centralized `status_badge($domain, $status, $label)` covering `employee`, `vacancy`, `application`, `interview`, `offer`, `leave`, `attendance`, `company`, and `user`.
  - Refactored legacy badge functions across `includes/recruitment.php`, `includes/offers.php`, `includes/employees.php`, and `includes/hr_ops.php` as thin delegators to maintain 100% backward compatibility.
  - Created standardized `includes/page_header.php` component.
- **MySQL Liveness & Resilience (`config/database.php`):**
  - Configured non-persistent PDO connections (`PDO::ATTR_PERSISTENT => false`).
  - Implemented automated single-reconnection resilience on MySQL server disconnect error codes 2006/2013 ("server has gone away").
- **Error Shielding & Production Hardening (`includes/bootstrap.php`):**
  - Added `display_startup_errors` handling based on `APP_DEBUG`.
  - Added custom `set_error_handler` to log warnings/notices to `storage/logs/app.log` while preventing any error/warning leakage in production.
- **Verification:**
  - Automated test suite (`php tests/run_tests.php`) passes cleanly. All PHP files validated with `php -l`.

---

## Phase 12 - Employee Login Account Linking & Leave Guards (`database/phase11_link_user.sql`)
- **Employee Account Linking:**
  - Added `employees.user_id` foreign key with unique constraint `uq_employees_user_id` preventing duplicate employee-user mapping.
  - Implemented company-scoped account linking in employee profiles and user management.
- **Navigation & Leave Guards:**
  - Added navigation guard (`$navHasLinkedEmp`): hides "Apply for Leave" button and employee-specific navigation items for administrative accounts that lack a linked employee record.
  - Added server-side validation rejecting leave requests if the acting user is not linked to an employee profile.

---

## Phase 13 - Granular Permission Overrides, Company Logos & Login Branding (`database/phase12_*.sql`)
- **User Permission Overrides (`database/phase12_user_overrides.sql`):**
  - Created `user_permission_overrides` table for per-user, per-company granular access rules (`allow` / `deny`).
  - Implemented precedence hierarchy: explicit `deny` > explicit `allow` > role defaults.
  - Added user permission management and audit trail tracking in `modules/users/view.php`.
- **Company Branding & Logo Uploads (`database/phase12_company_logo.sql`):**
  - Added `companies.logo_path` and `company.logo` permission for Group Administrators.
  - Added secure logo upload handling with MIME validation, dimension scaling, and fallback initials circle (`render_company_logo()`).
  - Added `company.delete` permission for safe removal of inactive companies.
- **Login Branding (`database/phase12_login_logo.sql`):**
  - Configurable login screen branding modes: default AaZa branding, custom uploaded logo, or synchronized sidebar logo.
  - Cache-busting hash query parameters for instant visual updates across themes.
- **Role Management UI (`modules/roles/`):**
  - Built interactive role index and permission matrix editor (`modules/roles/edit.php`) with lockout prevention for core administrative permissions.

---

## Phase 14 - Safe Deletion Guards for Users & Master Organizational Data (`database/phase13_*.sql`, `database/phase14_*.sql`)
- **User Deletion Safety (`database/phase13_user_delete.sql`):**
  - Added `user.delete` permission granted exclusively to Group Administrators.
  - Implemented `check_user_deletable()` blocking deletion of active accounts, self-deletion, the last group administrator, or accounts linked to employee records.
  - Transactional user deletion removing assignments and permission overrides with full audit logging.
- **Master Organizational Data Deletion Safety (`database/phase14_delete_permissions.sql`):**
  - Introduced granular permissions: `department.delete`, `designation.delete`, `leave_type.delete`, and `role.delete`.
  - Implemented dependency checks:
    - Active departments/designations/leave types must be deactivated first.
    - Inactive departments/designations with employee assignments cannot be deleted.
    - Inactive leave types with historical leave applications cannot be deleted.
    - System built-in roles cannot be deleted; custom roles must have zero assigned users.
  - Added interactive Bootstrap confirmation modals across all management interfaces.
- **Automated Test Suite Expansion:**
  - Expanded test suite from 48 assertions to 105 automated assertions across 15 test suites in `tests/run_tests.php`.

---

## Phase 15 - Unified Global Search & Performance Indexing (`database/phase15_search_indexes.sql`)
- **Top-Bar Instant Search (`includes/header.php`, `assets/js/app.js`):**
  - Integrated sticky top-bar search box with accessible keyboard shortcuts (`/` outside form inputs and `Ctrl+K` / `Cmd+K` globally).
  - Responsive mobile overlay: search transforms into an icon button below 576px, expanding full-width on demand with an intuitive back/close button.
  - 250ms debounced live search queries `modules/search/api.php` with AbortController for smooth request cancellation.
  - Interactive results dropdown grouped by entity (max 5 previews each) with full keyboard navigation (Up/Down arrow selection, Enter to navigate or open full results, Esc to dismiss).
- **Comprehensive Search Results Hub (`modules/search/index.php`):**
  - Full-page search view with 10 entity tabs: All Results, Employees, Candidates, Vacancies, Applications, Interviews, Job Offers, Leave Requests, Companies, and Users.
  - 20 records per page pagination with query parameter retention across tabs.
  - Advanced search filters: Department, Designation, Job Position / Vacancy, Employment Status, Recruitment Status, and Interview Date Range.
  - Clear empty states with helpful search tips for blank queries, zero matches, or filtered searches.
- **Security & Multi-Tenancy Scoping (`includes/search.php`):**
  - Strict prepared statements with parameter binding and LIKE wildcard neutralization (`escape_like()`).
  - Company isolation via `scoped_company_ids()` and active company context.
  - Role-based entity gating: each group is rendered only if the user holds the matching view permission.
  - Confidentiality protection: sensitive remuneration fields (salary, offered salary) and bank info are never returned; identity card (NIC/Passport) matching is gated exclusively behind `document.confidential`.
  - Rate limiting: enforced 60 requests per minute per user via `check_rate_limit()`.
- **Database Optimization (`database/phase15_search_indexes.sql`):**
  - Idempotent migration adding composite and single-column indexes on `job_vacancies(title)`, `employees(first_name, last_name)`, `employees(employee_code)`, `candidates(first_name, last_name)`, and `users(full_name)`.
- **Automated Test Suite Expansion:**
  - Added Test Suite 16 with 8 assertions verifying tenant isolation, permission gating, confidential NIC search rules, wildcard escaping, input sanitization, and rate limits (total 113 tests passing).

---

## Phase 16 - In-App Notifications & Scheduled Reminders (`database/phase16_notifications.sql`)
- **Database Schema Extension (`database/phase16_notifications.sql`):**
  - Extended `notifications` table idempotently with `title VARCHAR(150)`, `read_at TIMESTAMP NULL`, `dedupe_key VARCHAR(100) NULL`, unique constraint `uq_notif_user_dedupe (user_id, dedupe_key)`, and performance index `idx_notif_user_read_created (user_id, is_read, created_at)`.
- **Notification Helpers (`includes/notifications.php`):**
  - `notify_user()`: Dispatches notifications with deduplication via `UNIQUE(user_id, dedupe_key)`.
  - `notify_permission()`: Fanout dispatch to active users holding a specific permission in a target company, strictly excluding the actor and users not assigned to the company.
  - `get_unread_notification_count()`, `get_recent_notifications()`, `mark_notification_as_read()`, `mark_notification_as_unread()`, `mark_all_notifications_as_read()`, `delete_notification()`, and `get_notifications_paginated()`.
  - Sensitive data protection: Remuneration and identification cards are strictly excluded from notification payloads.
- **Top-Bar Notification Bell (`includes/header.php`, `assets/css/components.css`, `assets/js/app.js`):**
  - Interactive top-bar bell with unread count badge, 60-second polling against `modules/notifications/api.php`, live dropdown previewing the latest 8 notifications with time-ago formatting, click-to-mark-read, and "Mark all as read" action.
- **Dedicated Notifications Center (`modules/notifications/index.php`):**
  - Full-page notifications inbox with All/Unread tabs, type filter, mark read/unread toggles, delete action, pagination (20/page), and responsive empty states for light and dark themes.
- **JSON API (`modules/notifications/api.php`):**
  - Polling and mutation API strictly scoped to authenticated user's own rows (`WHERE id = ? AND user_id = ?`) with CSRF protection and 60 req/min rate limiting.
- **Domain Event Triggers Across Modules:**
  - Leave lifecycle: submitted -> approvers; approved/rejected/cancelled -> applicant employee.
  - Recruitment & Applications: new application -> HR; candidate status change (shortlisted/selected/rejected) -> vacancy owner.
  - Interviews: scheduled/rescheduled/cancelled -> panel members; evaluation submitted -> HR.
  - Job Offers: created/sent/accepted/declined -> offer creator + HR; candidate converted to employee -> HR.
  - Security & Account Administration: user deactivation & admin password reset -> target user account.
- **Scheduled Reminders CLI Engine (`bin/send_reminders.php`):**
  - CLI utility for automated daily reminders: upcoming interviews (today/tomorrow), pending interview evaluations, candidates selected awaiting hiring decisions, approaching vacancy deadlines, stale leave requests (>3 days), and expiring employee documents (<30 days).
  - Formatted dedupe keys (`event:reminder:{id}:{Y-m-d}`) guaranteeing exactly one notification per event per day.
  - Fully documented for Windows Task Scheduler and Linux crontab.
- **Automated Test Suite Expansion (`tests/run_tests.php`):**
  - Added Test Suite 17 with 4 comprehensive assertions covering tenant and actor exclusion, deduplication, unauthorized API access prevention, and reminder daily uniqueness (total 117 assertions passing cleanly).

---

## Phase 17 - Company Settings Management & Multi-Tenant Scoping (`database/phase17_company_settings.sql`)
- **Database Schema & Permission Registration (`database/phase17_company_settings.sql`):**
  - Ensured `company_settings` table exists with `(id, company_id, setting_key, setting_value, updated_at)` and unique key `uq_company_setting (company_id, setting_key)`.
  - Registered permission `company.settings` and mapped to `group_admin` and `company_admin` roles.
- **Settings Helpers & In-Memory Request Cache (`includes/companies.php`):**
  - Implemented `get_company_setting()`, `set_company_setting()`, `delete_company_setting()`, `get_all_company_settings()`, `reset_company_settings()`, and `company_settings_definitions()`.
  - Added request-level in-memory cache with `clear_company_settings_cache()` to prevent redundant SQL queries.
- **Dedicated Company Settings Interface (`modules/companies/settings.php`):**
  - Built tabbed configuration interface (`?id=X&tab=...`) restricted strictly to `company_admin` of that company and `group_admin`.
  - 6 configuration tabs:
    - **General:** Currency code (default LKR), display date format, operational time zone, and financial year start date.
    - **Work & Attendance:** Weekly off days, standard work start time, late grace minutes, and half-day hours.
    - **Leave:** Maximum request range days, allowed back-dated leave days, and annual leave carry forward toggle.
    - **Recruitment:** Application tracking prefix, interview evaluation score scale (1-5 or 1-10), and default offer validity days.
    - **Employee:** Employee code prefix (custom or company code) and numeric code padding (3 to 8 digits).
    - **Notifications:** Active domain notification channels toggle and reminder lead days.
  - Server-side validation on every setting value, CSRF protection, and audit logging via `log_activity('company.settings_update', ...)`.
  - Contextual UI badges: "Company override" for customized rows and "Using group default" with inherited fallback value when unconfigured.
  - "Reset to Defaults" button (`action=reset_defaults`) with confirmation dialog and audit logging (`company.settings_reset`).
- **Codebase Integration with Safe Fallbacks:**
  - `format_currency()` (`includes/offers.php`): Supports company-specific currency code override via `$companyId`.
  - `generate_employee_code()` (`includes/employees.php`): Respects company prefix and padding settings with graceful defaults.
  - `calculate_working_days()` & `get_working_days_in_range()` (`includes/hr_ops.php`): Respects company weekly off days.
  - `modules/attendance/daily.php` & `modules/attendance/employee_attendance.php`: Enforces company work start time and late grace minutes.
  - `modules/leave/request.php`: Enforces company maximum request range and back-dated leave policy.
  - `modules/offers/form.php`: Applies company default offer validity days when drafting new job offers.
  - `modules/interviews/evaluate.php`: Configures evaluation score scale dynamically (1-5 standard or 1-10 granular).
  - `includes/notifications.php`: Silences disabled notification channels based on company preferences.
- **Navigation Shortcuts (`modules/companies/index.php`, `modules/companies/view.php`):**
  - Added Settings buttons gated by `user_can('company.settings', $companyId)`.
- **Automated Test Suite Expansion (`tests/run_tests.php`):**
  - Added Test Suite 18 with 5 comprehensive assertions: missing setting falls back to constants, setting overrides constants (currency, employee code prefix/padding, work start time, score scale), custom weekly off days override weekend defaults, company A admin cannot access company B settings (multi-tenant scoping), and reset to defaults clears overrides (total 122 assertions passing cleanly).

---

## Phase 18 - Confidential Employee Performance Reviews & Goals (`database/phase18_performance.sql`)
- **Database Schema & Permission Registration (`database/phase18_performance.sql`):**
  - Created `performance_reviews` table: `id`, `company_id`, `employee_id`, `reviewer_user_id`, `period_label`, `period_start`, `period_end`, `review_date`, `overall_rating` (TINYINT 1-5), `manager_feedback` (TEXT), `improvement_notes` (TEXT), `training_requirements` (TEXT), `status` ENUM('draft','submitted','acknowledged'), `acknowledged_at`, `reopened_at`, `reopen_reason`, `created_at`, `updated_at`.
  - Created `performance_goals` table: `id`, `review_id` (NULLABLE), `employee_id`, `company_id`, `title`, `description`, `target_date`, `progress` (TINYINT 0-100), `status` ENUM('open','achieved','missed'), `created_at`, `updated_at`.
  - Added performance indexes: `idx_perf_rev_comp_emp (company_id, employee_id)`, `idx_perf_rev_period (company_id, period_start, period_end)`, `idx_perf_goals_rev (review_id)`, `idx_perf_goals_emp (company_id, employee_id)`.
  - Seeded permissions: `performance.view`, `performance.manage`, `performance.view_own`. Mapped `performance.manage` to `hr_manager`, `company_admin`, `group_admin`. `hr_officer` has no access by default.
- **Performance Helpers & Fine-Grained Authorization (`includes/performance.php`):**
  - Defined rating scale labels (1: Unsatisfactory, 2: Needs Improvement, 3: Meets Expectations, 4: Exceeds Expectations, 5: Outstanding), rating badges, star rating renderer, workflow status badges, and goal status badges.
  - Implemented direct reporting hierarchy authorization: `is_reporting_manager_of_any()`, `get_direct_report_employee_ids()`.
  - Authorization guards:
    - `can_user_manage_employee_performance()`: Allows HR managers, company admins, group admins, and the employee's direct reporting manager (`employees.reporting_manager_id`) to draft and submit reviews.
    - `can_user_view_performance_review()`: Enforces company tenant isolation, reporting manager visibility, and own-only visibility for regular employees (`performance.view_own`), while strictly shielding `draft` reviews from the employee until formally submitted.
    - `can_user_edit_performance_review()`: Checks manage permissions and strictly locks review once in `acknowledged` status.
    - `can_user_acknowledge_performance_review()`: Restricts acknowledgment exclusively to the reviewed employee when status is `submitted`.
    - `can_user_reopen_performance_review()`: Allows HR management / group admin to reopen an acknowledged review with an audit-logged reason.
    - `build_performance_scope_filter()`: Generates scoped SQL queries for multi-tenant listings.
- **Performance Module UI & Workflows (`modules/performance/`):**
  - **Review Directory (`modules/performance/index.php`):** Multi-tenant list with KPI summary cards (Total Reviews, Outstanding 5★, Needs Attention 1-2★, Awaiting Acknowledgement), filters (company, department, period, status, rating, search), rating star display, and pagination.
  - **Appraisal Form (`modules/performance/form.php`):** Create and edit reviews with scoped employee selection (reporting managers only see their direct reports), period date pickers, interactive 1-5 rating selector cards, qualitative feedback text areas, and dynamic repeatable goals with vanilla JavaScript add/remove and progress range sliders. Supports saving as 'draft' or 'submitted'.
  - **Review Detail & Acknowledgement (`modules/performance/view.php`):** Comprehensive appraisal overview, performance summary card, qualitative commentary callouts, goals progress indicators, one-click Employee Acknowledge button with confirmation modal, and HR Reopen Appraisal modal capturing mandatory reason. Also includes clean print stylesheet support.
- **Employee Profile Integration (`modules/employees/view.php`):**
  - Integrated confidential "Performance" tab (Tab 5) visible only to HR administrators, the employee's direct reporting manager, or the employee themselves.
  - Displays reviews scorecard, period history, and active performance goals directly within the employee dossier.
- **Navigation Integration (`includes/header.php`):**
  - Added "Performance" menu item under the "People" dropdown in the main navigation, displayed conditionally when the user has `performance.view`, `performance.manage`, or is an active reporting manager of direct reports.
- **Appraisal Reports & Analytics (`modules/reports/`):**
  - `modules/reports/performance_ratings.php`: Rating distribution breakdown by department and company, showing employee review counts, average score, and rating buckets (1 to 5), with CSV export and audit logging (`report.performance_ratings.export`).
  - `modules/reports/performance_due.php`: Reviews due and pending appraisals tracking active employees without a review in the last 12 months or awaiting acknowledgment, with direct action links, CSV export, and audit logging (`report.performance_due.export`).
  - `modules/reports/index.php`: Added "Performance & Appraisal Intelligence" catalog section linking both reports.
- **Automated Test Suite Expansion (`tests/run_tests.php`):**
  - Added Test Suite 19 with 5 comprehensive assertions: reporting manager direct report access vs non-reports without HR role, confidential draft shielding from employee, own-only view and acknowledgment security, post-acknowledgment lock and HR reopen workflow, and strict multi-tenant company isolation + HR Officer default permission exclusion (total 127 assertions passing cleanly).






---

## Phase 19 - Company Holidays, Leave Working Days Integration & Full Delete Lifecycle (`database/phase19_holidays.sql`, `database/phase14_delete_permissions.sql`)
- **Database Schema & Permission Registration (`database/phase19_holidays.sql`, `database/phase14_delete_permissions.sql`):**
  - Created `company_holidays` table: `id`, `company_id` (NULL for group-wide, FK to companies), `holiday_date` (DATE), `name` (VARCHAR 100), `is_active` (TINYINT 1), `created_at`, `updated_at`, with unique constraint `uq_comp_holiday (company_id, holiday_date)`.
  - Registered permission `leave.manage` and mapped to `group_admin`, `group_hr`, `company_admin`, `hr_manager`.
  - Added fine-grained deletion permissions in `database/phase14_delete_permissions.sql`: `candidate.delete`, `vacancy.delete`, `interview.delete`, `offer.delete`, `employee.delete` granted exclusively to `group_admin`.
- **Leave Working Days, Holiday Deduction & Attendance Synchronization (`includes/hr_ops.php`):**
  - Implemented `get_holidays_in_range()` with company and group-wide holiday resolution, in-memory caching, and cache clearing.
  - Enhanced `get_working_days_in_range()` to respect company weekly off days and automatically filter out active public holidays (both company-specific and group-wide).
  - Enhanced `leave_balance()` to look up employee `company_id` and pass it to `get_working_days_in_range()`, ensuring accurate deduction.
  - Enhanced `sync_leave_attendance()` to pass the employee's `company_id` so approved leave auto-attendance skips public holidays.
  - Updated `modules/leave/request.php` (both pre-check and transactional lock loops) to pass `$companyId` to `calculate_working_days()`. Added live JavaScript holiday preview and excluded holidays badge container.
  - Updated `modules/leave/decide.php` and `modules/leave/reports.php` to pass `company_id`.
  - Updated `modules/attendance/daily.php` and `modules/attendance/employee_attendance.php` to display public holiday notices and daily badges.
- **Holiday Management Interface (`modules/leave/holidays.php`):**
  - Full CRUD interface for public holidays: year filter, company scope filter (group-wide vs specific subsidiary), status filter, Add Modal, Edit Modal, Active/Inactive toggle, and safe delete (only inactive holidays can be deleted) with CSRF and audit logging (`holiday.create`, `holiday.update`, `holiday.toggle`, `holiday.delete`).
  - Added "Holidays" menu link in `includes/header.php` under Leave management.
- **Delete Lifecycle & Safety Guards (Part 2 & Part 3):**
  - **Candidate Deletion (`includes/recruitment.php`, `modules/candidates/delete.php`, `modules/candidates/view.php`):**
    - Safety guard: candidate cannot be deleted if hired or has active applications.
    - Action: Group admin only (`user_can('candidate.delete') && is_group_admin()`), unlinks stored documents from disk, deletes records with cascade, and audit logs `candidate.delete`. Added confirmation modal on view page.
  - **Vacancy Deletion (`includes/recruitment.php`, `modules/vacancies/delete.php`, `modules/vacancies/view.php`):**
    - Safety guard: vacancy must be in terminal state (`closed` or `cancelled`) and have no active/hired applicants.
    - Action: Group admin only (`user_can('vacancy.delete') && is_group_admin()`), deletes vacancy and residual applications, audit logs `vacancy.delete`. Added confirmation modal on view page.
  - **Interview Deletion (`includes/recruitment.php`, `modules/interviews/delete.php`, `modules/interviews/view.php`):**
    - Safety guard: interview must be in terminal `cancelled` state (scheduled/rescheduled interviews cannot be deleted).
    - Action: Group admin only (`user_can('interview.delete') && is_group_admin()`), cleans up interviewers and evaluations, audit logs `interview.delete`. Added confirmation modal on view page.
  - **Offer Deletion (`includes/offers.php`, `modules/offers/delete.php`, `modules/offers/view.php`):**
    - Safety guard: offer cannot be deleted if converted to employee or still in active `sent` state; must be `declined`, `expired`, or `withdrawn`.
    - Action: Group admin only (`user_can('offer.delete') && is_group_admin()`), deletes offer record, audit logs `offer.delete`. Added confirmation modal on view page.
  - **Employee Deletion (`includes/employees.php`, `modules/employees/delete.php`, `modules/employees/view.php`):**
    - Safety guard: employee must be in retired state (`terminated` or `resigned`); cannot be active supervisor/manager of active staff; cannot be linked to active user account.
    - Action: Group admin only (`user_can('employee.delete') && is_group_admin()`), unlinks document files from disk, deletes performance reviews/goals, leave records/approvals, attendance, history, and employee dossier, and audit logs `employee.delete`. Added confirmation modal on view page.
- **Header Variable Isolation & Form Autofill Safeguards:**
  - Renamed `$user` in `includes/header.php` to `$currentUserRow` to eliminate collision with page variables.
  - Renamed target user in `modules/users/form.php` to `$targetUser` to guarantee clean separation between logged-in administrator and edited account.
- **Automated Test Suite Expansion (`tests/run_tests.php`):**
  - Added Test Suite 21 (Company Holidays & Leave Working Days Integration) with 7 assertions.
  - Added Test Suite 22 (Recruitment & Employee Deletion Safety) with 14 assertions.
  - Total automated test count reached 151 assertions passing 100% cleanly.

---

## Phase 20 - Permissions Cleanup, Schema Verification & Bug Fixes (`database/migrations/phase20_cleanup_permissions.sql`)
- **Permissions Cleanup:** Created `database/migrations/phase20_cleanup_permissions.sql` removing deprecated permission codes `evaluation.view`, `interview.evaluate`, and `interview.schedule`.
- **CLI Schema Checker (`tests/check_schema.php`):** Static verification script checking all 35 tables and columns across all application PHP files and query statements.
- **Candidate Document Upload Fix (`public/apply.php`):** Corrected `candidate_documents` column mappings (`doc_type = 'cv'`) with atomic transaction rollback and file unlink cleanup.
- **Employee Dashboard Alignments (`public/_employee_dashboard.php`):** Aligned designation column reference to `des.title as desig_name` and leave request order to `requested_at`.
- **Company Deletion Integrity (`includes/companies.php`):** Updated table checks for `interviews`, `attendance`, `performance_reviews`, and `notifications`, removing silent exception suppressions.
- **Regression Suite 23 (`tests/run_tests.php`):** Added assertions for schema verification, public CV apply, dashboard query execution, and company deletion blockers.

---

## Phase 21 - Production Hosting Readiness, Web Installer & System Health
- **Single-File Database Installer (`database/aaza_hrms_full.sql`):** Consolidated single-file database installer without `USE` or `CREATE DATABASE` commands, fully compatible with cPanel prefixed databases.
- **Migration Compiler Tool (`tools/build_full_sql.php`):** Automated tool to compile versioned migrations from `database/migrations/` in documented sequence with `--check` verification mode.
- **Automated 3-Step Web Installer (`public/setup.php`):** Interactive web installer evaluating system requirements, executing database migrations statement by statement, provisioning the organization and initial administrator, creating `storage/installed.lock`, and self-deleting.
- **Dynamic Base URL Auto-Detection:** Removed hardcoded path references; automatically adapts to domain roots or nested subfolder paths.
- **Production Hardening & Security:**
  - Enforced `display_errors = 0` in production mode.
  - Automated log rotation for `storage/logs/app.log` at 5MB threshold retaining up to 5 archive files.
  - Strict session cookie parameters (`HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS) and HSTS header transmission.
  - Custom branded error pages: `public/403.php`, `public/404.php`, and `public/500.php`.
  - Comprehensive `.htaccess` with asset caching, Gzip compression, and directory access denial.
  - Added `.user.ini` with recommended production limits.
- **Administrator System Health Dashboard (`modules/system/health.php`):** Real-time monitoring of PHP extensions, DB latency/version, storage permissions, backup timestamps, and security alerts.
- **Production Release Packager (`tools/make_release.php`):** CLI utility producing a sanitized `aaza_hrms_release.zip` package ready for shared hosting and VPS deployment.
- **Documentation:** Authored comprehensive `docs/USER_MANUAL.md` covering all roles and operational modules.

---

## Phase 22 - Web Installer Hardening, Pristine Template Protection & System Diagnostics
- **Pristine Installer Template (`tools/templates/setup.php.dist`):**
  - Archived a pristine distribution copy of `setup.php` inside `tools/templates/setup.php.dist` that is preserved and protected from any local test execution.
  - Updated `tools/make_release.php` to unconditionally package `tools/templates/setup.php.dist` as `public/setup.php` into `dist/aaza_hrms_release.zip`, guaranteeing release zips never lose the installer.
- **Web Installer Hardening (`public/setup.php`):**
  - Removed silent auto-unlinking. After successful Step 3 provisioning, the installer generates `storage/installed.lock`, writes configuration files with `0640` permissions, and redirects to a dedicated Finish Screen.
  - Provided a prominent security warning and an explicit **Delete installer now** button (POST + CSRF action `delete_installer`) for safe administrative removal, with hosting File Manager fallback instructions.
  - Strict input sanitization with regex for database host (`^[A-Za-z0-9._:-]{1,255}$`), database name, and username (`^[A-Za-z0-9_$.-]{1,64}$`), strict port integer casting, and `var_export()` escaping to prevent code injection.
  - Immediate HTTP 403 Forbidden rejection when `storage/installed.lock` exists or registered users are present.
  - Error message masking: raw DB exception traces suppressed when in production environment.
- **System Health & Diagnostics (`modules/system/health.php`):**
  - Fully restored diagnostics module accessible exclusively to Group Administrators under Administration in `includes/header.php`.
  - Monitors PHP >= 8.0 runtime, mandatory/optional extensions, database connection status, storage directory write permissions, and backup freshness (< 3 days).
  - Actively scans for `public/setup.php` presence and provides a 1-click CSRF-protected removal action directly from the dashboard.
- **Test Suite Expansion (`tests/run_tests.php`):**
  - Added Test Suite 24 assertions covering malicious `db_name` rejection (quote and PHP code injection prevention), `installed.lock` refusal (HTTP 403), and release zip contents verification (inclusion of `public/setup.php` and `database/aaza_hrms_full.sql`; exclusion of `installed.lock`, active configs, tests, docs, and `.git`).
  - Total test assertions increased to 167 passing 100% cleanly.
