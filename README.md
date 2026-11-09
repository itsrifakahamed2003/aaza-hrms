# AaZa HRMS - Multi-Company Human Resource & Recruitment Management System

AaZa HRMS is an enterprise-grade, multi-company Human Resource Management and Recruitment Information System designed for group conglomerates. It provides centralized group governance while enforcing strict tenant data isolation, granular Role-Based Access Control (RBAC), end-to-end recruitment pipelines, interview scoring, job offer workflows, attendance/leave lifecycle management, analytical reporting, and tamper-resistant audit logging.

---

## Table of Contents
1. [Core Features by Module](#1-core-features-by-module)
2. [System Requirements](#2-system-requirements)
3. [Production Hosting Installation (cPanel / VPS in 5 Steps)](#3-production-hosting-installation-cpanel--vps-in-5-steps)
4. [Local Installation (XAMPP)](#4-local-installation-xampp)
5. [Database Architecture & Migrations](#5-database-architecture--migrations)
6. [First Login & Initial Administration](#6-first-login--initial-administration)
7. [Multi-Company & User Access Management](#7-multi-company--user-access-management)
8. [Employee Self-Service & Profile Linking](#8-employee-self-service--profile-linking)
9. [Default Roles & Capabilities](#9-default-roles--capabilities)
10. [Configuration Directives](#10-configuration-directives)
11. [Daily Operations & Maintenance](#11-daily-operations--maintenance)
12. [Troubleshooting & Common Issues](#12-troubleshooting--common-issues)
13. [Upgrade Procedure](#13-upgrade-procedure)
14. [Production Deployment Checklist](#14-production-deployment-checklist)

---

## 1. Core Features by Module

### Multi-Company Architecture & Tenant Isolation
- **Tenant Isolation:** Centralized database with strict multi-company partitioning. Users assigned to one company cannot view, search, export, or modify records belonging to other companies.
- **Group vs. Company Scoping:** Group Administrators and Group HR personnel maintain oversight across all subsidiaries; Company Administrators and HR Officers are restricted strictly to their assigned companies.
- **Organization Hierarchy:** Configurable departments and designations tailored per company subsidiary.

### Employee Lifecycle Management
- **Master Directory:** Comprehensive employee profiles covering personal identity (NIC/Passport), emergency contacts, employment classifications, and manager hierarchy.
- **Compensation Privacy:** Strict permission guards (`salary.view`) control access to basic salary and allowances.
- **Promotions & Timeline:** Automatic tracking of historical designation, department, and compensation revisions in `employment_history`.
- **Inter-Company Transfers:** Formal multi-company transfer workflow updating active employee records, preserving historical timelines, moving document ownership, and reassigning direct subordinates.

### Recruitment & Candidate Pipeline
- **Job Vacancies:** Requisition management with headcount targets, closing dates, and automatic status closure when positions are filled.
- **Candidate Database:** Centralized applicant profiles deduplicated by email and identity card.
- **Recruitment Kanban:** Visual stage management (`applied`, `under_review`, `shortlisted`, `interview_scheduled`, `interviewed`, `selected`, `offered`, `hired`, `rejected`, `on_hold`, `withdrawn`).
- **Status Guards:** Robust database-level transactions prevent moving applications backward from terminal or active stages.
- **Public Career Portal:** Public vacancy application portal with anti-spam honeypot traps and IP rate limiting (5 applications/hour).

### Structured Interview Scoring
- **Scheduling:** Multi-round interview coordination supporting online, phone, and in-person interviews with meeting links or physical room locations.
- **Panel Coordination:** Multi-interviewer assignment via `interview_panel`.
- **Objective Scorecards:** Standardized evaluations on a 1-5 scale across technical competence, communication, and attitude, with formal recommendations (`strong_hire`, `hire`, `hold`, `reject`).

### Job Offers & Conversion
- **Offer Generation:** Formal job offers with compensation, designation, probation terms, joining dates, and expiry tracking.
- **Seamless Onboarding:** One-click conversion of accepted job offers into active employee profiles, pre-filling biographical details and setting up initial employment history.

### Attendance & Leave Management
- **Daily Time Tracking:** Daily attendance logging with check-in/out stamps, presence tracking (`present`, `absent`, `late`, `half_day`, `leave`), and monthly employee summaries.
- **Leave Quotas & Balances:** Configurable annual quotas per leave type with document upload requirements and negative balance prevention.
- **Multi-Level Approvals:** Manager leave reviews with self-approval guards and automatic synchronization to daily attendance logs upon approval.
- **Public Holidays & Working Days Engine:** Configurable group-wide and company-specific public holidays (`modules/leave/holidays.php`). Automatically excluded from leave requests, working day counts, quota balances, and daily attendance logging.

### Enterprise Deletion Safety & Lifecycle Guards
- **Candidate & Vacancy Deletion:** Group administrator-gated deletion with cascade cleanup of applications, interviews, evaluations, and stored files; blocked if candidate is hired/active or vacancy is not closed/cancelled.
- **Interview & Job Offer Deletion:** Strict state guards allowing deletion only for cancelled interviews or declined/withdrawn/expired offers.
- **Employee Deletion:** Safeguarded removal of retired staff (`terminated` or `resigned`) only when clear of active managerial responsibilities or active user credentials, with complete unlinking of documents from disk.

### Group Analytics & Reports
- **Executive Dashboard:** Live group KPI cards (Headcount, Attendance, Open Positions, Upcoming Interviews, Pending Approvals) and visual demographic charts.
- **Analytical Reports:** Headcount breakdowns, Turnover Rates, Attendance Summaries, Leave Utilization, Vacancy Aging, and Interview Evaluation Performance.
- **Secure CSV Streaming:** High-capacity CSV exports protected against spreadsheet formula injection (`=`, `+`, `-`, `@`, `\t`, `\r`).

### Security & Audit Infrastructure
- **Comprehensive Audit Trail:** Dedicated viewer (`modules/audit/`) logging user actions with automatic payload redaction for passwords, tokens, NICs, and salary figures.
### Modern Office UI & Design System
- **Enterprise Office Aesthetic:** Restrained, professional palette with neutral slate scales, standard 4px/8px spacing, 8px radii, and Inter variable typography.
- **Light & Dark Themes:** Seamless light/dark switching with persistent state, dark navy `#0F1E3A` sidebar in both modes, and zero-flash inline script initialization.
- **100% Self-Contained Offline Assets:** Fully localized vendor libraries in `assets/vendor/` (Bootstrap 5.3.3, Bootstrap Icons 1.11.3, Chart.js 4.4.7, Inter font). Zero external CDN network dependencies, strictly enforcing `Content-Security-Policy: default-src 'self'`.
- **Responsive 12-Column Grid:** Robust `.dashboard-grid` layout with `min-width: 0` child containers preventing flexbox blowouts and horizontal scrolling across all desktop, tablet, and mobile breakpoints.
- **Micro-Interactions & Accessibility:** Accessible keyboard navigation, WCAG AA focus rings, 800ms stat number count-up animation (`initStatCountUp`), and strict `prefers-reduced-motion` compliance.

---

## 2. System Requirements

- **Hosting requirements:** PHP 8.1+ with PDO MySQL, MySQL/MariaDB, Apache with mod_rewrite; not suitable for serverless platforms because it needs a writable filesystem.
- **PHP Version:** PHP 8.1 or higher (PHP 8.2+ recommended).
- **Database:** MySQL 5.7+ or MariaDB 10.4+.
- **Web Server:** Apache 2.4+ (XAMPP recommended on Windows) with `mod_rewrite` and `mod_headers` enabled.
- **Mandatory PHP Extensions:**
  - `pdo_mysql` (Database abstraction)
  - `fileinfo` (MIME verification for file uploads)
  - `zip` (Backup compression)
  - `mbstring` (Multibyte string processing)
  - `openssl` (Secure token generation and TLS/SSL SMTP connections)
  - `zlib` (Gzip database dump compression)

---

## 3. Production Hosting Installation (cPanel / VPS in 5 Steps)

Follow these 5 straightforward steps to deploy AaZa HRMS on any cPanel shared hosting, Apache virtual host, or VPS.

### Step 1: Upload and Extract Release Package
1. Build or download the clean deployment archive (`aaza_hrms_release.zip`).
2. In your hosting cPanel **File Manager** (or via SFTP/SSH), upload `aaza_hrms_release.zip` into your web root (e.g. `public_html/` or a subfolder `public_html/hrms/`).
3. Extract the zip archive. Ensure hidden files (`.htaccess`, `.user.ini`) are extracted.

### Step 2: Create MySQL Database & User
1. In cPanel, navigate to **MySQL Databases**.
2. Create a new database (e.g., `cpaneluser_aazahrms`).
3. Create a dedicated database user (e.g., `cpaneluser_hrmsuser`) with a strong password.
4. Add the user to the database and grant **ALL PRIVILEGES**.

### Step 3: Run the Interactive Web Installer
1. Open your browser and navigate to:
   ```text
   https://yourdomain.com/public/setup.php
   (or https://yourdomain.com/hrms/public/setup.php)
   ```
2. **Step 1 - Requirements:** The installer verifies PHP >= 8.0, required extensions (`pdo_mysql`, `mbstring`, `fileinfo`, `zip`, optional `gd`), folder write permissions (`storage/` and `assets/uploads/`), and displays PHP runtime limits (`upload_max_filesize`, `post_max_size`).
3. **Step 2 - Database Connection:** Enter your database host (`localhost` or `127.0.0.1`), port (`3306`), database name, username, and password. The installer tests the connection, checks for existing tables (requiring confirmation to reuse and strictly refusing if user accounts exist), executes the single-file schema `database/aaza_hrms_full.sql` statement by statement, and writes `config/database.php` with `0640` permissions using `var_export()`.
4. **Step 3 - Organization & Superadmin:** Enter your Application Name, Base URL (auto-detected), Timezone (whitelisted from standard PHP timezone list), Currency code (`^[A-Z]{3}$`), First Company Name & Code, and provision the initial **Group Administrator** account.

### Step 4: Finish Screen, Removal & Lock Verification
Upon completing Step 3, the installer generates `storage/installed.lock`, writes `config/config.php` (`APP_ENV=production`, `APP_DEBUG=false`, `0640` permissions), and redirects to the **Finish Screen**.
- **Safe Removal:** The installer does NOT delete itself silently. It displays a prominent security alert and offers a 1-click **Delete installer now** button (POST + CSRF) that permanently unlinks `public/setup.php`. Alternatively, hosting users can delete `public/setup.php` via cPanel File Manager or FTP.
- **Lock Protection:** Once `storage/installed.lock` exists (or user accounts are registered), `public/setup.php` immediately blocks all access with HTTP 403 Forbidden.
- **Pristine Template Guarantee:** A pristine distribution copy is maintained at `tools/templates/setup.php.dist`. The release builder (`tools/make_release.php`) always copies this pristine template to `public/setup.php` inside `dist/aaza_hrms_release.zip`, ensuring local testing can never remove the installer from distribution releases.
- **Post-Install Verification:** Verify system operation by signing in with your administrator credentials and checking `storage/logs/app.log`. If `public/setup.php` is still present in production mode, an urgent alert is displayed on the dashboard until it is removed.

### Step 5: Configure Scheduled Cron Jobs
In cPanel **Cron Jobs**, set up the two essential background tasks:
1. **Daily 07:00 AM - System Reminders & Notifications:**
   ```bash
   /usr/local/bin/php /home/cpaneluser/public_html/bin/send_reminders.php >/dev/null 2>&1
   ```
2. **Daily 02:00 AM - Automated Database & Upload Backups:**
   ```bash
   /usr/local/bin/php /home/cpaneluser/public_html/bin/backup.php >/dev/null 2>&1
   ```
*(Replace `/home/cpaneluser/public_html` with your server's actual absolute home directory path.)*

---

## 4. Local Installation (XAMPP)

### Method A: Using the Interactive Web Installer (Recommended)
1. Place the project folder in `C:\xampp\htdocs\aaza_hrms`.
2. Start Apache and MySQL from XAMPP Control Panel.
3. In **phpMyAdmin** (`http://localhost/phpmyadmin`), create a database named `aaza_hrms`.
4. Open `http://localhost/aaza_hrms/public/setup.php` in your browser and complete the 3 setup steps.

### Method B: Manual CLI Setup
1. Copy configuration templates:
   - Copy `config/config.sample.php` to `config/config.php`
   - Copy `config/database.sample.php` to `config/database.php`
2. Create database and import the single-file installer:
   ```sql
   CREATE DATABASE aaza_hrms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
3. Import `database/aaza_hrms_full.sql`:
   ```powershell
   Get-Content database\aaza_hrms_full.sql | C:\xampp\mysql\bin\mysql.exe -u root aaza_hrms
   ```
4. Run `http://localhost/aaza_hrms/public/setup.php` to initialize the superadmin user.

---

## 5. Database Architecture & Migrations

### Single-File Master Schema
- **Fresh Installs:** Always import `database/aaza_hrms_full.sql`. This file compiles the complete schema and all historical phases into one optimized, host-prefix safe script (no hardcoded `USE` or `CREATE DATABASE`).
- **Generator Tool:** Run `php tools/build_full_sql.php` to rebuild the master file from migrations, or `php tools/build_full_sql.php --check` to verify that the committed file matches current migrations.

### Existing Installations (Upgrades)
Individual sequential migrations are stored in `database/migrations/`:
```text
database/migrations/schema.sql
database/migrations/phase2_roles_seed.sql
database/migrations/phase6_permissions.sql
...
database/migrations/phase20_cleanup_permissions.sql
```
For existing production instances, only apply migrations created after your installed version.

---

## 6. First Login & Initial Administration

> [!WARNING]
> ### Delete `public/setup.php` after the first admin is created
> Once setup is complete and your first administrator account has been provisioned, **delete `public/setup.php` immediately** from your web server. `public/setup.php` attempts to self-delete (`unlink(__FILE__)`) upon locking installation, but manual verification or deletion is required on production environments.

### Super Admin Dashboard
1. Log in via `http://localhost/aaza_hrms/public/login.php` or `https://yourdomain.com/public/login.php`.
2. Verify system operation by confirming the dashboard loads properly and checking `storage/logs/app.log` for any errors or warnings.

> [!CAUTION]
> **CRITICAL PRODUCTION WARNING:**  
> Once setup is complete, you **MUST delete or disable `public/setup.php`**. In production mode (`APP_ENV = 'production'`), if `setup.php` is present, a high-priority warning will be displayed across administrative dashboards.

### First Login & Password Rotation
1. Navigate to `http://localhost/aaza_hrms/public/login.php`.
2. Sign in with the administrator credentials.
3. If an account was created by another admin, the system automatically redirects the user to `modules/auth/change_password.php` to set a private password before granting access.

---

## 7. Multi-Company & User Access Management

1. **Creating Companies:** Navigate to **Companies -> Add Company** (`modules/companies/form.php`). Specify company code (e.g., `AZT`), legal name, and contact details.
2. **Adding Users:** Navigate to **Users -> Add User** (`modules/users/form.php`). Enter user details, select a primary role (e.g., `employee`, `hr_officer`, or `company_admin`), and assign the primary company.
3. **Multi-Company Access Assignment:** To grant an administrator or HR officer access to multiple subsidiaries, select the additional companies in the **Company Access** selector.

---

## 8. Employee Self-Service & Profile Linking

In AaZa HRMS, **User Accounts** (login credentials, passwords, system roles) and **Employee Records** (employment profile, department, designation, leave balances, attendance logs) are distinct:

- **Why Linking is Required:** For an employee to submit leave requests, view their leave balances, or track attendance, their login User Account must be linked to their Employee Record (`uq_employees_user_id`).
- **How to Link User Account to Employee Profile:**
  1. Navigate to **People > Employees** (`modules/employees/index.php`).
  2. Click **Add Employee** (or click **Edit** on an existing employee record).
  3. Under the **Employment Information** section, find the **Login Account** dropdown.
  4. Select the matching user account (e.g., `safran (Mohamed Safran)`).
  5. Save the employee record.
- **Employee Self-Service (ESS) Dashboard:**
  - When an employee signs in, the system renders the **Employee Self-Service Dashboard** (`public/_employee_dashboard.php`).
  - If linked: Shows their personalized leave balance quotas, quick action "Apply for Leave", recent leave request timeline, and upcoming company holidays.
  - If not yet linked: Displays an informative onboarding screen instructing them to contact HR to link their login to their employee dossier.

---

## 9. Default Roles & Capabilities

| Role Name | Scope | Key Capabilities |
|---|---|---|
| **Group Admin** (`group_admin`) | Global (All Companies) | Full system governance, user provisioning, company settings, RBAC role permissions, cross-company reporting, full audit trail viewing & CSV export, disaster backups. |
| **Group HR** (`group_hr`) | Global (All Companies) | Cross-company HR operations, executive group dashboard analytics, candidate pipelines, interview panels, job offers, global leave & attendance reports, audit log viewing & CSV export. |
| **Company Admin** (`company_admin`) | Assigned Companies Only | Department & designation setups, company user management, employee records, recruitment pipelines, offer generation, leave/attendance policies, company-scoped audit logs. |
| **HR Manager** (`hr_manager`) | Assigned Companies Only | Full employee lifecycle, recruitment pipelines, interview scheduling, offer recommendations, leave approvals, attendance tracking, operational HR reports. |
| **HR Officer** (`hr_officer`) | Assigned Companies Only | Day-to-day employee record maintenance, vacancy management, candidate status updates, interview coordination, daily attendance logging, leave request intake. |
| **Employee** (`employee`) | Self Record Only | View own employee profile, submit leave applications, review personal leave balances, perform daily check-in / check-out. |

---

## 8. Configuration Directives

Key settings located in `config/config.php` and `config/database.php`:

| Constant | Description | Default |
|---|---|---|
| `APP_ENV` | Environment mode (`'development'` or `'production'`). Masks debug errors in production. | `'development'` |
| `APP_DEBUG` | Boolean (`true` or `false`). In production, displays generic error screens (`ERR-XXXXXXX`), disables verbose startup errors, and logs PHP errors silently to `storage/logs/app.log`. | `false` |
| `BASE_URL` | Canonical root HTTP URL with trailing slash. | `'http://localhost/aaza_hrms/'` |
| `WEEKLY_OFF_DAYS` | Array of weekly off day indices (`0` = Sun, `6` = Sat). | `[0, 6]` (Weekends) |
| `ATTENDANCE_LATE_THRESHOLD` | Time string after which check-in is flagged as late. | `'09:30:00'` |
| `ATTENDANCE_HALF_DAY_HOURS` | Minimum worked hours required for full day vs half day. | `4.0` |
| `BACKUP_PATH` | Server directory where `.sql.gz` database backups are written. | `dirname(__DIR__) . '/storage/backups'` |
| `BACKUP_RETENTION_DAYS` | Number of daily backup archives preserved before automated pruning. | `14` |
| `BACKUP_MYSQLDUMP_PATH` | Explicit binary path to `mysqldump.exe` (auto-detected if blank). | `''` |
| `MAIL_DRIVER` | Candidate email driver: `'smtp'`, `'phpmail'`, or `'none'` (default). | `'none'` |
| `MAIL_HOST` | Outgoing SMTP server hostname. | `'smtp.gmail.com'` |
| `MAIL_PORT` | Outgoing SMTP port (`587` for STARTTLS, `465` for implicit SSL). | `587` |
| `MAIL_ENCRYPTION` | SMTP encryption mode: `'tls'` (STARTTLS) or `'ssl'` (implicit TLS). | `'tls'` |
| `MAIL_USERNAME` | SMTP authentication username / mailbox address. | `''` |
| `MAIL_PASSWORD` | SMTP password or Gmail App Password (never commit). | `''` |
| `MAIL_FROM_EMAIL` | Sender email address for outgoing candidate emails. | `''` |
| `MAIL_FROM_NAME` | Default sender display name. | `'AaZa HRMS'` |

### Email setup

AaZa HRMS includes a built-in, zero-dependency SMTP / PHP `mail()` engine (`includes/mailer.php` and `includes/mail_templates.php`) for sending automated and manual notifications to job candidates (`application_received`, `shortlisted`, `interview_invite`, `selected`, `rejected`, and `offer_sent`).

- **Configuration Location:** Put your email settings **only in `config/config.php`** (which is git-ignored and excluded from release archives; **never commit** credentials or passwords to version control).
- **Gmail SMTP Example:**
  1. Enable **2-Step Verification** on the Google account.
  2. Generate a 16-character **App Password** under *Google Account -> Security -> 2-Step Verification -> App passwords*.
  3. Configure `config/config.php` with:
     ```php
     define('MAIL_DRIVER', 'smtp');
     define('MAIL_HOST', 'smtp.gmail.com');
     define('MAIL_PORT', 587);
     define('MAIL_ENCRYPTION', 'tls');
     define('MAIL_USERNAME', 'careers@yourcompany.com');
     define('MAIL_PASSWORD', 'your-16-char-app-password'); // Gmail App Password, never commit
     define('MAIL_FROM_EMAIL', 'careers@yourcompany.com');
     define('MAIL_FROM_NAME', 'AaZa HRMS');
     ```
- **Free Shared Hosting Fallback (`Open in mail app`):** Many free shared hosts (such as InfinityFree) block outgoing SMTP ports (`587` / `465`) and disable `mail()`. When `MAIL_DRIVER` is set to `'none'`, the **"Open in mail app"** fallback is automatically used in the candidate email modal, opening the HR user's default mail client via a safe `mailto:` link pre-populated with the candidate's email, subject, and message body.
- **Production Mailbox Recommendation:** Always use a dedicated **company mailbox** (e.g., `careers@yourcompany.com` or `hr@yourcompany.com`) when contacting real candidates in production.

---

## 9. Directory Structure

```text
aaza_hrms/
├── assets/
│   ├── css/
│   │   ├── app.css                # Core application styles
│   │   ├── components.css         # Office Software Design System components
│   │   ├── login.css              # Authentication view styling
│   │   ├── print.css              # High-fidelity print styles
│   │   └── tokens.css             # Light & Dark design tokens, palette & typography
│   ├── js/
│   │   └── app.js                 # Theme controller, count-up animation, modal confirms
│   └── vendor/                    # 100% offline self-contained libraries
│       ├── bootstrap/             # Bootstrap 5.3.3 CSS & JS bundle
│       ├── bootstrap-icons/       # Bootstrap Icons 1.11.3 & woff/woff2 font files
│       ├── chartjs/               # Chart.js 4.4.7 (chart.umd.min.js)
│       └── inter/                 # Inter variable typography and woff2 font files
├── bin/
│   ├── backup.php                 # CLI-only automated backup & retention utility
│   └── send_reminders.php         # CLI automated daily reminders engine
├── config/
│   ├── config.sample.php          # Sample general system configuration
│   ├── database.sample.php        # Sample database connection configuration (with auto-reconnect)
│   ├── config.php                 # Active application configuration (ignored in git)
│   └── database.php               # Active database credentials (ignored in git)
├── database/
│   ├── schema.sql                 # Baseline schema (tables, foreign keys)
│   ├── phase2_roles_seed.sql      # Initial RBAC seed data
│   ├── phase6_permissions.sql     # Interview permissions
│   ├── phase6_fix.sql             # Interview panel table & scoring alignment
│   ├── phase7_permissions.sql     # Job offer & salary permissions
│   ├── phase8_leave_attendance.sql# Leave & attendance enhancements
│   ├── phase9_reports.sql         # Reporting permissions & query indexes
│   ├── phase10_final.sql          # Audit indexes, must_change_password & rate_limits
│   ├── phase11_link_user.sql      # Employee login account linking
│   ├── phase12_user_overrides.sql # User-level permission overrides (allow/deny)
│   ├── phase12_company_logo.sql   # Company logo uploads & company.logo / company.delete
│   ├── phase12_login_logo.sql     # Custom login page branding settings
│   ├── phase13_user_delete.sql    # user.delete permission for safe user removal
│   ├── phase14_delete_permissions.sql # Master org deletion permissions (dept, desig, leave, role)
│   ├── phase15_search_indexes.sql # Performance indexes for unified global search
│   ├── phase16_notifications.sql  # In-app notifications & scheduled reminders schema
│   ├── phase17_company_settings.sql # Company settings schema & company.settings permission
│   └── phase18_performance.sql    # Performance reviews, goals & performance.* permissions
├── docs/
│   ├── BACKUP_RECOVERY.md         # Disaster recovery, task scheduling & restore guide
│   ├── CHANGELOG.md               # Detailed phase-by-phase release history
│   ├── DATABASE.md                # Comprehensive database schema & table inventory
│   ├── ROLES_PERMISSIONS.md       # Complete RBAC permissions matrix
│   └── SECURITY.md                # Security architecture & controller access matrix
├── includes/
│   ├── audit.php                  # Audit trail logger & sensitive data maskers
│   ├── auth.php                   # Authentication, lockout & session managers
│   ├── bootstrap.php              # Strict bootstrap, security headers, error shielding
│   ├── companies.php              # Multi-company helpers & logo management
│   ├── csrf.php                   # Anti-CSRF token generation & validation
│   ├── employees.php              # Employee master data, transfers & document security
│   ├── footer.php                 # Shared footer layout & global confirmation modal
│   ├── functions.php              # Security sanitizers, formatters, date parsers, pagination
│   ├── header.php                 # Top navigation, sidebar, notification bell, role indicators
│   ├── hr_ops.php                 # HR operations, status transitions & CSV helpers
│   ├── notifications.php          # In-app notification dispatcher & reminder engine
│   ├── offers.php                 # Job offer helpers & currency formatting
│   ├── organization.php           # Departments & designations management
│   ├── page_header.php            # Standardized page header component
│   ├── performance.php            # Performance appraisals, rating scales & reporting manager rules
│   ├── permissions.php            # RBAC permissions, overrides & scope checking
│   ├── recruitment.php            # Vacancies, candidates, interview panel helpers
│   ├── reports.php                # Aggregated analytical metrics & CSV streaming
│   ├── search.php                 # Unified global search engine & safe highlighting
│   ├── ui.php                     # Centralized status_badge() helper & UI decorators
│   └── users.php                  # User accounts, effective permissions & deletion safety
├── modules/
│   ├── attendance/                # Daily attendance, time logs, attendance reports
│   ├── audit/                     # Audit Log Viewer & secure CSV export
│   ├── auth/                      # Self-service password change
│   ├── candidates/                # Candidate profiles, applications, document uploads
│   ├── companies/                 # Multi-company subsidiaries, logos & settings (settings.php)
│   ├── dashboard/                 # Analytical group dashboard
│   ├── employees/                 # Employee directory, transfers, document vault, performance tab
│   ├── interviews/                # Scheduling, calendar, evaluation scorecards, panel reviews
│   ├── leave/                     # Leave requests, balance tracking, approvals, my leaves
│   ├── notifications/             # Full Notification Center & live polling JSON API
│   ├── offers/                    # Job offers, salary control, employee conversion
│   ├── organization/              # Departments & designations management
│   ├── performance/               # Employee performance reviews, dynamic goals & appraisals
│   ├── reports/                   # 14 cross-company analytical reports (including performance)
│   ├── roles/                     # System role management & permission editor
│   ├── search/                    # Unified global search hub & live JSON API
│   ├── users/                     # User management, activity trail & permission overrides
│   └── vacancies/                 # Job requisition management & recruitment pipelines
├── public/
│   ├── apply.php                  # Public career application portal (rate-limited)
│   ├── index.php                  # Application landing & dashboard router
│   ├── login.php                  # User login with brute-force lockout & custom branding
│   ├── logout.php                 # Secure session termination (POST + CSRF)
│   ├── setup.php                  # Initial superadmin bootstrap tool
│   └── switch_company.php         # Active tenant company context switcher
├── storage/
│   ├── backups/                   # Encrypted / compressed backup archives (.sql.gz)
│   ├── logs/                      # Application error logs (app.log)
│   └── uploads/                   # Uploaded employee documents, resumes & medical slips
└── tests/
    ├── run_tests.php              # Automated test suite (127 assertions across 19 suites)
    └── smoke_http.md              # Role-by-role manual HTTP test matrix
```

---

## 10. Audit Logging Event Coverage Matrix

AaZa HRMS features immutable, structured activity logging via `log_activity()`. All sensitive payload values (passwords, salary figures, identity numbers, session tokens) are automatically masked as `[hidden]`.

| Business Event | Action Code | Entity Type | Source Location |
|---|---|---|---|
| **Candidate Creation** | `candidate.create` | `candidates` | `modules/candidates/form.php`, `public/apply.php` |
| **Candidate Status Change** | `candidate.status_change` | `candidate_application` | `includes/recruitment.php`, `modules/candidates/status_change.php` |
| **Public Application Intake** | `candidate.apply` | `candidate_applications` | `public/apply.php`, `modules/candidates/apply.php` |
| **Interview Scheduling** | `interview.schedule` | `interview` | `modules/interviews/schedule.php` |
| **Interview Rescheduling** | `interview.reschedule` | `interview` | `modules/interviews/status_change.php` |
| **Interview Evaluation** | `interview.evaluate` | `interview` | `modules/interviews/evaluate.php` |
| **Interview Completion** | `interview.complete` | `interview` | `modules/interviews/evaluate.php`, `modules/interviews/status_change.php` |
| **Job Offer Creation** | `offer.create` | `job_offer` | `modules/offers/form.php` |
| **Job Offer Dispatched** | `offer.send` | `job_offer` | `modules/offers/status_change.php` |
| **Job Offer Acceptance** | `offer.accept` | `job_offer` | `modules/offers/status_change.php` |
| **Job Offer Rejection** | `offer.decline` | `job_offer` | `modules/offers/status_change.php` |
| **Job Offer Withdrawn** | `offer.withdraw` | `job_offer` | `modules/offers/status_change.php` |
| **Candidate Conversion** | `offer.convert` | `job_offer` | `modules/offers/convert.php` |
| **Employee Creation** | `employee.create` | `employee` | `modules/employees/form.php`, `modules/offers/convert.php` |
| **Employee Record Changes** | `employee.edit` | `employee` | `modules/employees/form.php` |
| **Employee Inter-Company Transfer** | `employee.transfer` | `employee` | `modules/employees/transfer.php` |
| **Employee Status Change** | `employee.status_change` | `employee` | `modules/employees/status_change.php` |
| **Daily Attendance Recorded** | `attendance.record` | `attendance` | `modules/attendance/daily.php` |
| **Daily Attendance Updated** | `attendance.update` | `attendance` | `modules/attendance/daily.php` |
| **Self Check-In / Check-Out** | `attendance.self_check_in` / `attendance.self_check_out` | `attendance` | `modules/attendance/employee_attendance.php` |
| **Leave Approval** | `leave.approve` | `leave_request` | `modules/leave/decide.php` |
| **Leave Rejection** | `leave.reject` | `leave_request` | `modules/leave/decide.php` |
| **Leave Request Cancellation** | `leave.cancel` | `leave_request` | `modules/leave/my_leaves.php` |
| **User Creation & Editing** | `user.create` / `user.update` | `user` | `modules/users/form.php` |
| **User Activation / Deactivation** | `user.activate` / `user.deactivate` | `user` | `modules/users/toggle.php` |
| **Role Permission Matrix Updated** | `role.permissions_update` | `role` | `modules/users/roles.php` |
| **Company Created / Updated** | `company.create` / `company.update` | `company` | `modules/companies/form.php` |
| **Company Activation / Deactivation** | `company.activate` / `company.deactivate` | `company` | `modules/companies/toggle.php` |
| **User Login Succeeded** | `auth.login` | `user` | `includes/auth.php` |
| **User Login Failed** | `auth.failed_login` | `user` | `includes/auth.php` |
| **User Account Locked Out** | `auth.lockout` | `user` | `includes/auth.php` |
| **User Logout** | `auth.logout` | `user` | `includes/auth.php` |
| **Password Changed** | `auth.password_change` | `user` | `modules/auth/change_password.php` |
| **Document Uploaded** | `document.upload` / `candidate.document_upload` | `employee_document` / `candidate_document` | `modules/employees/documents.php`, `modules/candidates/document_upload.php` |
| **Document Downloaded** | `document.download` / `candidate.document_download` | `employee_document` / `candidate_document` | `modules/employees/download.php`, `modules/candidates/download.php`, `modules/leave/download.php` |
| **Performance Review Created** | `performance.review_create` | `performance_reviews` | `modules/performance/form.php` |
| **Performance Review Updated** | `performance.review_update` | `performance_reviews` | `modules/performance/form.php` |
| **Performance Review Submitted** | `performance.review_submit` | `performance_reviews` | `modules/performance/form.php` |
| **Performance Review Acknowledged** | `performance.review_acknowledge` | `performance_reviews` | `modules/performance/view.php` |
| **Performance Review Reopened** | `performance.review_reopen` | `performance_reviews` | `modules/performance/view.php` |
| **Analytical Report Export** | `report.export` / `report.performance_ratings.export` / `report.performance_due.export` | `report` | `includes/reports.php`, `modules/reports/performance_ratings.php`, `modules/reports/performance_due.php` |
| **Audit Log Viewed** | `audit.view` | `activity_logs` | `modules/audit/index.php`, `modules/audit/view.php` |
| **Audit Log Exported to CSV** | `audit.export` | `activity_logs` | `modules/audit/index.php` |

---

---

## 11. Daily Operations & Maintenance

### 1. Automated Backups (`bin/backup.php`)
- **Schedule:** Run daily at 02:00 AM via cron or Task Scheduler.
- **Output:** Stored in `storage/backups/backup_YYYY-MM-DD_HHMMSS.sql.gz`.
- **Retention:** Automatically keeps the last 14 days of backups and prunes older archives.
- **Manual Backup:** Run `php bin/backup.php` via CLI at any time.

### 2. Scheduled Reminders & Alerts (`bin/send_reminders.php`)
- **Schedule:** Run daily at 07:00 AM via cron or Task Scheduler.
- **Scope:** Processes upcoming interviews, missing interview scorecards, pending hiring decisions, approaching vacancy deadlines, stale leave requests, and expiring employee documents.
- **Testing:** Run `php bin/send_reminders.php --dry-run` to preview notifications without sending.

### 3. User Provisioning & Employee Linking
When onboarding staff:
1. Create their login credentials in **Users > Add User** with the `Employee` role.
2. Under **People > Employees**, create or edit their Employee Record and assign their login account in the **Login Account** dropdown.
3. The employee can now sign in to access their Employee Self-Service dashboard, request leaves, and log attendance.

### 4. System Verification & Application Logs
Group Administrators can verify ongoing system operations by:
- Signing in to the administrator dashboard to inspect company activity and module statistics.
- Reviewing `storage/logs/app.log` for runtime exceptions, audit events, and background cron status.
- Reviewing **Administration > Audit Logs** (`modules/audit/index.php`) for administrative actions.

---

## 12. Troubleshooting & Common Issues

### 1. 403 "You do not have permission to view dashboard reports"
- **Cause:** A user with self-service `Employee` privileges logged into the root analytics dashboard without administrative report permissions.
- **Resolution:** The system automatically renders the **Employee Self-Service Dashboard** (`public/_employee_dashboard.php`) for staff accounts. Ensure the user's role is correctly assigned. If the employee wishes to request leave, ensure their user account is linked to an Employee Record under *People > Employees*.

### 2. "Apply for Leave" or "My Leave" Missing from Menu
- **Cause:** The logged-in user account has not yet been linked to an active Employee profile in the `employees` table.
- **Resolution:** Navigate to **People > Employees**, edit the employee's record, and select the user's username in the **Login Account** dropdown.

### 3. Directory Write Permission Errors
- **Symptom:** Unable to upload documents or save backups.
- **Resolution:** Ensure the web server user (`www-data`, `apache`, or `cpanel`) has write permissions on:
  ```text
  chmod 775 storage storage/logs storage/backups storage/uploads assets/uploads
  ```

### 4. Apache Rewrite / Base URL Issues
- **Symptom:** 404 errors when navigating subpages.
- **Resolution:** Confirm that Apache's `mod_rewrite` is enabled and `AllowOverride All` is set for the project directory in Apache configuration so `.htaccess` can route requests properly.

### 5. Timezone Discrepancies in Attendance or Logs
- **Symptom:** Attendance timestamps or audit logs do not match local business hours.
- **Resolution:** Set `APP_TIMEZONE` in `config/config.php` (e.g. `'Asia/Colombo'`). Both PHP and MariaDB sessions are automatically synchronized.

---

## 13. Upgrade Procedure

To upgrade an existing live deployment to a newer release:
1. **Take a full backup:**
   ```bash
   php bin/backup.php
   ```
2. **Apply new migrations:**
   Check the `database/migrations/` directory for any migration files numbered higher than your current deployment (e.g., `phase21_*.sql`) and import them in sequential order:
   ```bash
   mysql -u hrmsuser -p hrmsdb < database/migrations/phase21_example.sql
   ```
3. **Deploy updated code:**
   Upload the new codebase files, ensuring you do not overwrite existing `config/config.php`, `config/database.php`, `storage/`, or `assets/uploads/`.
4. **Verify System Operation:**
   Sign in as Group Admin, navigate through key pages, and check `storage/logs/app.log` to confirm no errors are logged.

---

## 14. Production Deployment Checklist

Before taking AaZa HRMS live:

1. [ ] **Environment Configuration:** Set `APP_ENV = 'production'` in `config/config.php` to disable PHP error display and shield SQL traces.
2. [ ] **Remove Setup Script:** Permanently delete `public/setup.php` from the server.
3. [ ] **HTTPS Enforcement:** Configure an SSL certificate and force HTTPS redirection in Apache virtual hosts.
4. [ ] **Rotate Default Passwords:** Require all administrative users to change default credentials.
5. [ ] **Restrict Database Privileges:** Grant the application database user only `SELECT`, `INSERT`, `UPDATE`, `DELETE`, and `LOCK TABLES` privileges; remove `DROP` and `GRANT` permissions.
6. [ ] **Automate Scheduled Backups:** Set up Windows Task Scheduler or Linux crontab to execute `php bin/backup.php` daily.
7. [ ] **Automate Daily Reminders:** Register Windows Task Scheduler or crontab to execute `php bin/send_reminders.php` daily at 08:00 AM.
8. [ ] **Lockdown File Permissions:** Ensure `.htaccess` blocks public web access to `config/`, `database/`, `includes/`, `storage/`, `tests/`, and `docs/`.
9. [ ] **Audit Trail Monitoring:** Regularly review `modules/audit/` for unauthorized login attempts or administrative privilege adjustments.

