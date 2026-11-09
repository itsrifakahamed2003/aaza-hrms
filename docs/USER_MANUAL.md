# AaZa HRMS - Complete User Manual
**Enterprise Multi-Company Human Resource & Recruitment Management Platform**
*Version 2.0 · Core PHP 8, Strict Typing, PDO, MySQL/MariaDB*

---

## Table of Contents
1. [Introduction & Role-Based Overview](#1-introduction--role-based-overview)
2. [First-Time System Setup & Company Provisioning](#2-first-time-system-setup--company-provisioning)
3. [User Management & Linking Employee Profiles](#3-user-management--linking-employee-profiles)
4. [Roles & Multi-Tenant Permission Scoping](#4-roles--multi-tenant-permission-scoping)
5. [Recruitment Pipeline & Candidate Tracking](#5-recruitment-pipeline--candidate-tracking)
6. [Interview Coordination & Structured Scorecards](#6-interview-coordination--structured-scorecards)
7. [Job Offers & One-Click Candidate Onboarding](#7-job-offers--one-click-candidate-onboarding)
8. [Employee Dossier & Personnel Management](#8-employee-dossier--personnel-management)
9. [Inter-Company Employee Transfers](#9-inter-company-employee-transfers)
10. [Attendance Tracking & Workday Logging](#10-attendance-tracking--workday-logging)
11. [Leave Management, Approvals & Working Days Engine](#11-leave-management-approvals--working-days-engine)
12. [Company & Group-Wide Public Holidays](#12-company--group-wide-public-holidays)
13. [Employee Performance Reviews & Goal Tracking](#13-employee-performance-reviews--goal-tracking)
14. [Executive Analytics & CSV Data Streaming](#14-executive-analytics--csv-data-streaming)
15. [Unified Global Search Engine](#15-unified-global-search-engine)
16. [In-App Notifications & Scheduled Reminders](#16-in-app-notifications--scheduled-reminders)
17. [Enterprise Deletion Safety & Lifecycle Guards](#17-enterprise-deletion-safety--lifecycle-guards)
18. [Branding & Company Settings](#18-branding--company-settings)

---

## 1. Introduction & Role-Based Overview

AaZa HRMS is designed to support conglomerates and multi-tenant groups with centralized governance and partitioned subsidiary operations.

### Key Roles
- **Group Administrator (`group_admin`):** Global superuser with unrestricted access across all companies, settings, user provisioning, and deletion tools.
- **Group HR Manager (`group_hr`):** Cross-company HR governance, analytics, and group-wide recruitment oversight.
- **Company Administrator (`company_admin`):** Full operational authority within their assigned subsidiary company.
- **HR Manager (`hr_manager`):** Company-level HR oversight: candidate pipelines, interviews, offers, employee dossiers, and leave approvals.
- **HR Officer (`hr_officer`):** Operational staff handling daily attendance, document intake, and candidate status updates.
- **Reporting Manager / Supervisor (`manager`):** Departmental managers approving leaves and conducting direct-report performance appraisals.
- **Employee (`employee`):** Self-service portal (ESS): viewing leave quotas, submitting leave requests, reviewing appraisals, and monitoring company holidays.

---

## 2. First-Time System Setup & Company Provisioning

### Web Installer (`public/setup.php`)
When launching AaZa HRMS for the first time:
1. Navigate to `http://your-domain/public/setup.php`.
2. **Step 1 - Requirements Check:** The installer verifies PHP 8.0+, required extensions (`pdo_mysql`, `mbstring`, `fileinfo`, `zip`, `gd`), and writable directories (`storage/`, `assets/uploads/`).
3. **Step 2 - Database Setup:** Enter MySQL host, port, database name, username, and password. The installer tests connectivity and imports the complete master schema (`database/aaza_hrms_full.sql`) statement by statement.
4. **Step 3 - Organization & Superuser Provisioning:** Configure application name, base URL, system timezone (`Asia/Colombo`), default currency (`LKR`), first company details (name, code), and your first Group Administrator account.
5. Upon completion, `storage/installed.lock` is created, and `public/setup.php` automatically unlinks itself for security.

---

## 3. User Management & Linking Employee Profiles

### Creating Users
1. Log in as an Administrator and navigate to **Administration → Users** (`/modules/users/index.php`).
2. Click **+ Add User** (`/modules/users/form.php`).
3. Fill in Full Name, Username, Email, and initial strong password.
4. Assign company roles (e.g. AaZa Tech → HR Manager).

### Linking User Accounts to Employee Profiles
Every staff member logging into the system requires both a **Login Account** (in `users`) and an **Employee Dossier** (in `employees`).
1. Navigate to **People → Employees** (`/modules/employees/index.php`).
2. Open the employee record and click **Edit Details**.
3. Under **Login Account**, select the user's login account from the dropdown.
4. Save the employee record.
5. **Result:** The user's Employee Self-Service (ESS) dashboard immediately displays their real-time leave quotas, attendance records, and direct manager hierarchy.

---

## 4. Roles & Multi-Tenant Permission Scoping

AaZa HRMS enforces strict multi-company tenant scoping. A user assigned to Company A cannot view or manipulate data belonging to Company B unless explicitly assigned a cross-company role.

### Customizing Permissions
1. Navigate to **Administration → Roles & Permissions** (`/modules/roles/index.php`).
2. View default roles or create custom roles.
3. Click **Configure Permissions** to toggle capabilities across Recruitment, Employees, Attendance, Leave, Performance, and Reports.
4. Changes are recorded in the immutable audit log (`role.permissions_update`).

---

## 5. Recruitment Pipeline & Candidate Tracking

### Managing Job Vacancies
1. Navigate to **Recruitment → Vacancies** (`/modules/vacancies/index.php`).
2. Click **+ Post Vacancy** to create a requisition with target positions, department, designation, and closing date.
3. When all positions are filled via offer conversion, the system automatically marks the vacancy as **Closed**.

### Candidate Applications
- **Internal Intake:** HR adds candidate profiles and files applications under open vacancies.
- **Public Career Portal (`public/apply.php`):** External applicants submit online applications with resume (CV) attachments. Each submission automatically creates:
  - A deduplicated candidate profile.
  - A candidate application in `applied` status.
  - An entry in `candidate_documents` (`doc_type = 'cv'`).
  - An audit trail record and HR in-app notification.

### Visual Pipeline
Navigate to **Vacancies → Pipeline Kanban** (`/modules/vacancies/pipeline.php`) to drag and drop candidates across stages:
`Applied` → `Under Review` → `Shortlisted` → `Interview Scheduled` → `Interviewed` → `Selected` → `Offered` → `Hired`.

### Candidate Email Notifications & Preview Modal
On the **Candidate Profile** (`/modules/candidates/view.php`, per application) and **Interview View** (`/modules/interviews/view.php`):
1. Click **Email candidate** and select a template from the dropdown:
   - `Application received`
   - `Shortlisted`
   - `Interview invite` (pre-filled with interview date, time, mode, location/link, and panel names)
   - `Selected`
   - `Rejected`
   - `Offer`
2. A **Preview Modal** opens displaying:
   - **To:** Read-only field showing the candidate's real email address to authorized HR users.
   - **Subject & Body:** Editable fields allowing HR to personalize the message before sending.
   - **Action Button:**
     - When outgoing email (`MAIL_DRIVER = 'smtp'` or `'phpmail'`) is configured in `config/config.php`, click **Send** to dispatch the email directly and log the event in **Email History**.
     - When outgoing email is not configured (`MAIL_DRIVER = 'none'`), click **Open in mail app** to launch your default email client via a `mailto:` link pre-populated with the recipient, subject, and body.
3. **Optional Automatic Stage Emails:**
   - When updating a candidate's stage (`Shortlisted`, `Selected`, or `Rejected`) or scheduling an interview (`/modules/interviews/schedule.php`), an optional **Email the candidate** checkbox is available (checked by default when email is configured).
   - Email failures never block saving; if an email cannot be sent, the record is saved and a warning banner (`"Saved, but the email could not be sent: ..."`) is shown.
4. **Email History:**
   - The Candidate Profile page displays an **Email History** card listing every email attempt recorded in the activity log (sender name, template used, timestamp, and Sent/Failed status).

---

## 6. Interview Coordination & Structured Scorecards

### Scheduling an Interview
1. On any shortlisted candidate application, click **Schedule Interview** (`/modules/interviews/schedule.php`).
2. Select interview round (Round 1, Round 2, Final), mode (`In-Person`, `Online Video`, or `Phone`), date, time, and duration.
3. Assign interview panelists from the user directory.

### Objective Evaluation
1. Interviewers open the evaluation sheet (`/modules/interviews/evaluate.php`).
2. Score candidates on a standardized 1–5 scale across:
   - Technical Competence
   - Communication Skills
   - Cultural Attitude & Alignment
3. Provide recommendation (`Strong Hire`, `Hire`, `Hold`, `Reject`) and qualitative notes.
4. Once submitted, scorecards become immutable and are factored into recruitment analytical reports.

---

## 7. Job Offers & One-Click Candidate Onboarding

### Creating an Offer
1. When a candidate reaches `Selected` status, click **Issue Job Offer** (`/modules/offers/form.php`).
2. Specify offered salary, designation, department, proposed join date, and expiration deadline.
3. Track offer status: `Sent` → `Accepted` / `Declined` / `Withdrawn`.

### One-Click Onboarding Conversion
1. When an offer is marked `Accepted`, click **Convert to Employee** (`/modules/offers/convert.php`).
2. The system pre-fills candidate biographical information, creates an active employee dossier, initial employment history, and links the application.
3. Double-conversion guards prevent accidental duplicate employee creation.

---

## 8. Employee Dossier & Personnel Management

### Master Directory
Navigate to **People → Employees** (`/modules/employees/index.php`) to access the complete staff directory.
- Filters: Company, Department, Designation, Employment Status (`Active`, `Probation`, `Notice Period`, `Resigned`, `Terminated`, `Retired`).
- Search: Employee code, name, NIC/Passport, phone, or email.

### Document Repository
1. On the employee profile, click **Documents & Files** (`/modules/employees/document_upload.php`).
2. Upload contracts, identification certificates, and education credentials.
3. Mark sensitive files as **Confidential** (accessible only to users with `document.confidential` permission).

---

## 9. Inter-Company Employee Transfers

To move an employee between subsidiaries (e.g. from AaZa Tech to AaZa Logistics):
1. Navigate to the employee profile and click **Transfer Employee** (`/modules/employees/transfer.php`).
2. Select destination company, new department, designation, and effective transfer date.
3. **Automated Actions:**
   - Active company ID updates to the destination company.
   - Historical record is preserved in `employment_history`.
   - All employee documents move company tenancy to maintain document accessibility.
   - Formal transfer audit log is recorded.

---

## 10. Attendance Tracking & Workday Logging

### Daily Attendance
1. Navigate to **People → Attendance → Daily Log** (`/modules/attendance/daily.php`).
2. Select company and date.
3. Update check-in and check-out timestamps and status (`Present`, `Late`, `Half Day`, `Absent`, `On Leave`).
4. Employees on approved leave are automatically populated and locked against accidental absent marking.

### Monthly Summaries
Navigate to **People → Attendance → Reports** (`/modules/attendance/reports.php`) to view attendance percentages, total late days, and export CSV attendance sheets.

---

## 11. Leave Management, Approvals & Working Days Engine

### Leave Quotas & Types
Navigate to **Leave → Policy & Types** (`/modules/leave/types.php`).
- Configure annual quotas (e.g., Annual: 14 days, Casual: 7 days, Medical: 14 days).
- Set company overrides or group-wide defaults.
- Toggle mandatory document attachment requirements.

### Submitting Leave Requests
1. Employees navigate to **Apply for Leave** (`/modules/leave/request.php`).
2. Select leave type, start date, end date, and half-day option.
3. The real-time date calculator excludes weekends (configured weekly-off days) and official public holidays.
4. Overlap detection prevents submitting duplicate or overlapping leave dates.

### Approving Leave
1. HR Managers and Supervisors view pending applications in **Leave Requests** (`/modules/leave/index.php`).
2. Review remaining balance and historical leaves.
3. Click **Approve** or **Reject** with mandatory decision notes.
4. Upon approval, daily attendance logs are automatically synchronized.

---

## 12. Company & Group-Wide Public Holidays

Navigate to **Leave → Public Holidays** (`/modules/leave/holidays.php`).
- **Group-Wide Holidays:** Set Company to `All Companies (Group-Wide)`. Applies automatically across all group subsidiaries.
- **Company Holidays:** Target specific subsidiaries (e.g. regional mercantile or provincial holidays).
- **Working Days Integration:** All active holidays are automatically subtracted when computing working days and leave quota deductions.

---

## 13. Employee Performance Reviews & Goal Tracking

Navigate to **People → Performance** (`/modules/performance/index.php`).

### Performance Appraisals
1. Create a review cycle (e.g. Q4 2026 Appraisal) for an employee.
2. Reviewer rates overall performance on a 1–5 scale with feedback, strengths, and areas for improvement.
3. Status workflow: `Draft` (visible only to manager) → `Submitted` → `Acknowledged` (signed off by employee) → `Locked`.

### Goal Tracking
1. Add measurable goals (`performance_goals`) with target deadlines.
2. Track progress (0–100%) and mark goals as `Open`, `Achieved`, or `Missed`.

---

## 14. Executive Analytics & CSV Data Streaming

Navigate to **Insights → Reports** (`/modules/reports/index.php`).

### Available Reports
- **Headcount Demographics:** Breakdown by company, department, designation, and employment type.
- **Turnover & Retention:** Joiners, resignations, and turnover ratios over customized date ranges.
- **Leave Utilization:** Quotas consumed, remaining balances, and leave categories taken.
- **Recruitment Aging & Conversion:** Pipeline velocity, time-to-hire, and interview scorecard distributions.

### Formula Injection Protection
All CSV streaming exports automatically neutralize formula injection characters (`=`, `+`, `-`, `@`, `\t`, `\r`) to protect spreadsheets against CSV command injection attacks.

---

## 15. Unified Global Search Engine

Press **Ctrl + K** (or click the search bar in the top navigation):
- Search across Employees, Candidates, Job Vacancies, and System Users in a single unified overlay.
- Multi-tenant scoping ensures users only see records from their authorized companies.
- Sensitive identifiers (like NIC / Passport) are masked unless the user possesses confidential document clearance.

---

## 16. In-App Notifications & Scheduled Reminders

### In-App Bell
- Click the notification bell in the top navigation to view recent notifications.
- Unread count badges update dynamically via polling (`modules/notifications/api.php`).
- One-click "Mark all as read" or click directly to jump to the relevant item.

### Scheduled Reminders Cron
Set up the automated daily cron task:
```bash
php bin/send_reminders.php
```
Automatically generates notifications for:
- Stale leave requests awaiting approval (> 3 days).
- Upcoming interviews scheduled for tomorrow.
- Vacancy closing deadlines within 3 days.
- Expiring employee documents (passports, visas, contracts) within 30 days.

---

## 17. Enterprise Deletion Safety & Lifecycle Guards

AaZa HRMS features strict lifecycle dependency guards to ensure historical audit integrity:
- **Companies:** Cannot be deleted if containing active employees, vacancies, interviews, or attendance records.
- **Employees:** Cannot be deleted while active (must be resigned, terminated, or retired) and must not be the active supervisor of current employees.
- **Candidates:** Cannot be deleted if hired or holding active job offers.
- **Vacancies:** Cannot be deleted while in `Open` status or holding active candidate applications.
- **Audit Logging:** Every deletion attempt (successful or blocked) is permanently recorded in `activity_logs`.

---

## 18. Branding & Company Settings

### Company Branding
1. Navigate to **Organization → Companies** (`/modules/companies/index.php`).
2. Group Administrators can upload custom company logos (`.png`, `.jpg`, `.svg`).
3. Set the global login screen logo under **Branding Settings**.

### Company Specific Settings
Navigate to **Companies → Settings** (`/modules/companies/settings.php`) to configure:
- Currency symbol (e.g. `LKR`, `USD`, `EUR`).
- Employee code prefix (e.g. `AZT-`, `AZL-`).
- Workday start time and late grace minutes.
- Custom weekly off days (e.g. Friday & Saturday vs. Saturday & Sunday).
- **Email Configuration Status (Group Admin):** Group Administrators also see a read-only banner indicating whether outgoing candidate email is configured (`Email: Configured (SMTP)`) or using the mail-client fallback (`Email: Not configured - candidates are contacted via your mail app`). Credentials are stored strictly in `config/config.php` and never exposed in the UI.


