# AaZa HRMS - Security Architecture & Hardening Report

This document details the security controls, defensive countermeasures, and the complete route-level authorization matrix implemented in **AaZa HRMS**.

---

## 1. Route Authorization Matrix

The table below maps every controller in `modules/` and `public/` to its required permission and multi-tenant company isolation enforcement.

| Controller Path | Required Permission / Role | Company Check? | Authorization Mechanism / Scoping Details |
|:---|:---|:---:|:---|
| `public/index.php` | `require_login()` | **YES** | Scopes dashboard metric cards and charts independently per module via `report_scope_companies()`. |
| `public/login.php` | Public | **N/A** | Rate-limited per account & per IP; constant-time hash verification. |
| `public/logout.php` | `require_login()` | **N/A** | POST-only + CSRF; invalidates session and logs `auth.logout`. |
| `public/setup.php` | Public / One-Time | **N/A** | Blocked if `storage/installed.lock` exists or any `group_admin` user exists. |
| `public/apply.php` | Public Careers Portal | **N/A** | Protected by honeypot anti-spam trap and IP rate limit (max 5/hour). |
| `public/switch_company.php` | `require_login()` | **YES** | Verifies selected `company_id` is present in user's `accessible_company_ids()`. |
| `modules/auth/change_password.php`| `require_login()` | **YES** | Scoped strictly to session user; rate-limited; mandatory first-login flow. |
| `modules/audit/index.php` | `audit.view` | **YES** | Company admin scoped strictly to accessible company IDs; group admin sees group. Export requires `audit.export`. |
| `modules/audit/view.php` | `audit.view` | **YES** | Target row's `company_id` verified against `accessible_company_ids()`. Masking of sensitive keys enforced. |
| `modules/companies/index.php` | `require_group_admin()` | **Group** | Cross-company tenant management; strictly restricted to group admins. |
| `modules/companies/form.php` | `require_group_admin()` | **Group** | Creating and updating subsidiary company legal identities. |
| `modules/companies/toggle.php` | `require_group_admin()` | **Group** | POST + CSRF; toggles `is_active` flag. Hard deletes are prohibited. |
| `modules/users/index.php` | `require_group_admin()` | **Group** | System user administration; group admin only. |
| `modules/users/form.php` | `require_group_admin()` | **Group** | Creates/edits user assignments. Forces `must_change_password=1` on creation. |
| `modules/users/roles.php` | `require_group_admin()` | **Group** | Modifies role-to-permission mappings. |
| `modules/users/toggle.php` | `require_group_admin()` | **Group** | POST + CSRF; deactivates accounts. Prevents self-deactivation. |
| `modules/organization/departments.php` | `department.manage` | **YES** | Scoped to accessible company IDs. |
| `modules/organization/designations.php` | `department.manage` | **YES** | Scoped to accessible company IDs. |
| `modules/organization/_manage.php` | `department.manage` | **YES** | `require_company_access($cid, 'department.manage')` on all mutations. |
| `modules/employees/index.php` | `employee.view` | **YES** | Scoped via `accessible_company_ids('employee.view')`. |
| `modules/employees/form.php` | `employee.create` / `employee.edit` | **YES** | `require_company_access($companyId, ...)` verified on target employee. |
| `modules/employees/view.php` | `employee.view` | **YES** | Fetches employee from DB and verifies `company_id` via `require_company_access()`. |
| `modules/employees/status_change.php`| `employee.edit` | **YES** | POST + CSRF; DB lookup of employee `company_id` before mutation. |
| `modules/employees/transfer.php` | `employee.transfer` | **YES** | Checks permissions in source and destination companies; updates history and documents. |
| `modules/employees/download.php` | `document.view` | **YES** | DB lookup of document `company_id`; checks confidentiality permissions. |
| `modules/employees/document_upload.php`| `document.upload` | **YES** | `require_company_access($companyId, 'document.upload')`; strict extension and MIME verification. |
| `modules/vacancies/index.php` | `vacancy.view` | **YES** | Scoped via `accessible_company_ids('vacancy.view')`. |
| `modules/vacancies/form.php` | `vacancy.manage` | **YES** | `require_company_access($companyId, 'vacancy.manage')`. |
| `modules/vacancies/view.php` | `vacancy.view` | **YES** | DB lookup of vacancy `company_id` via `require_company_access()`. |
| `modules/vacancies/pipeline.php` | `vacancy.view` | **YES** | DB lookup of vacancy `company_id`; status transitions require `candidate.manage`. |
| `modules/candidates/index.php` | `candidate.view` | **YES** | Scoped to candidate applications in accessible companies. |
| `modules/candidates/form.php` | `candidate.manage` / `candidate.view` | **YES** | Verified via accessible candidate scoping. |
| `modules/candidates/view.php` | `candidate.view` | **YES** | Verified via `can_access_candidate($pdo, $id)`. |
| `modules/candidates/apply.php` | `candidate.manage` | **YES** | Candidate and vacancy company scopes strictly enforced. |
| `modules/candidates/status_change.php`| `candidate.manage` | **YES** | POST + CSRF; application `company_id` verified from DB. Status guards enforced. |
| `modules/candidates/download.php`| `candidate.view` | **YES** | Verifies candidate access before streaming document. |
| `modules/candidates/document_upload.php`| `candidate.manage` | **YES** | Verifies candidate company access; extension & MIME whitelist. |
| `modules/interviews/index.php` | `interview.view` | **YES** | Scoped via `accessible_company_ids('interview.view')`. |
| `modules/interviews/schedule.php`| `interview.manage` | **YES** | `require_company_access($companyId, 'interview.manage')`. |
| `modules/interviews/view.php` | `interview.view` | **YES** | DB lookup of interview `company_id` via `require_company_access()`. |
| `modules/interviews/evaluate.php`| `interview.manage` / panelist | **YES** | Verifies panelist membership or `interview.manage` for the specific interview session. |
| `modules/interviews/status_change.php`| `interview.manage` | **YES** | POST + CSRF; DB lookup of interview `company_id`. Status guards enforced. |
| `modules/offers/index.php` | `offer.view` | **YES** | Scoped via `accessible_company_ids('offer.view')`. |
| `modules/offers/form.php` | `offer.manage` | **YES** | `require_company_access($companyId, 'offer.manage')`. |
| `modules/offers/view.php` | `offer.view` | **YES** | DB lookup of offer `company_id`. Offered salary gated by `salary.view`. |
| `modules/offers/status_change.php`| `offer.manage` | **YES** | POST + CSRF; DB lookup of offer `company_id`. |
| `modules/offers/convert.php` | `offer.manage` + `employee.create` | **YES** | DB lookup of offer `company_id`. Duplicate conversion strictly prevented. |
| `modules/attendance/daily.php` | `attendance.view` / `attendance.record` | **YES** | `require_company_access($selectedCompanyId, ...)`. |
| `modules/attendance/employee_attendance.php` | Linked Employee | **YES** | Self-service history for linked employee record. |
| `modules/attendance/reports.php` | `attendance.view` | **YES** | Scoped to accessible companies; export checked per company. |
| `modules/leave/index.php` | `leave.view` / `leave.approve` | **YES** | Scoped to accessible company IDs. |
| `modules/leave/my_leaves.php` | `leave.request` | **YES** | Self-service for linked employee; cancellation auto-removes attendance. |
| `modules/leave/request.php` | `leave.request` / `leave.manage`| **YES** | Target employee company verified; overlap and balance guards enforced. |
| `modules/leave/decide.php` | `leave.approve` | **YES** | POST + CSRF; DB lookup of request `company_id`. Self-approval blocked. |
| `modules/leave/types.php` | `leave.manage_types` | **YES** | `require_company_access($companyId, 'leave.manage_types')`. |
| `modules/leave/view.php` | `leave.view` | **YES** | DB lookup of request `company_id`; allows owner or permitted HR. |
| `modules/leave/download.php` | `leave.view` | **YES** | DB lookup of request `company_id`; owner or permitted HR. |
| `modules/leave/reports.php` | `leave.view` | **YES** | Scoped to accessible companies; export checked per company. |
| `modules/search/api.php` | `require_login()` | **YES** | JSON endpoint with 60/min rate limiting; each entity group gated by its specific view permission and company scoping. Never returns salary or confidential data. |
| `modules/search/index.php` | `require_login()` | **YES** | Unified search hub with 10 entity tabs, 20/page pagination, company scoping, and multi-field filters. |
| `modules/reports/index.php` | `report.view` | **YES** | Category-scoped access via `report_scope_companies()`. |
| `modules/reports/*.php` (11 reports) | `report.view` | **YES** | Strict intersection scoping; export requires `report.export` per company. |

---

## 2. Core Security Controls

### A. Output Escaping & XSS Defense
- **Global Escaper**: All dynamic output in HTML templates is passed through `e($value)`, which executes `htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
- **JSON in Script Tags**: Any embedded JSON in script tags utilizes `JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP` to prevent script breakout attacks.

### B. SQL Injection Neutralization
- **Strict Prepared Statements**: Concatenation of user input into SQL strings is forbidden. 100% of parameterized queries use PDO prepared statements with `?` parameter markers.
- **Whitelist Filtering**: Dynamic column or table names (such as in `_manage.php`) are hardcoded constants from caller code. Sort fields and pagination limits are strictly cast to integers.

### C. Cross-Site Request Forgery (CSRF)
- **Token Verification**: Every state-changing form emits a cryptographically secure token via `csrf_field()`. Handlers enforce `csrf_require()`.
- **No State Change via GET**: All toggles, status updates, approvals, rejections, and deletes are implemented as POST endpoints.

### D. Session & Cookie Hardening
- **Strict Cookies**: `session.use_strict_mode = 1`, `session.use_only_cookies = 1`.
- **Cookie Attributes**: `HttpOnly = true`, `SameSite = Lax`, `Secure` dynamically enabled on HTTPS connections.
- **Session Regeneration**: Session IDs are regenerated upon sign-in (`reset_session()`) and credential updates to eliminate session fixation.
- **Idle Timeout**: Sessions expire after 30 minutes of inactivity (`SESSION_IDLE_TIMEOUT = 1800`). User agent hashing guards against session hijacking.

### E. Password Policy & Authentication Security
- **Complexity Enforcement**: Passwords require a minimum of 10 characters and must contain at least one letter and one number.
- **Identity Matching Prevention**: Passwords matching the username or email address are rejected.
- **First-Login Mandatory Change**: Newly provisioned accounts have `must_change_password = 1`. `require_login()` intercepts any request and redirects to `modules/auth/change_password.php`.
- **Dual Lockout Policy**: Failed logins trigger a 15-minute lockout after 5 consecutive failures per account **and** per client IP address. Constant-time dummy hash verification ensures timing-attack resistance.

### F. File Upload & Storage Defense
- **Whitelisting**: Only extensions (`pdf`, `doc`, `docx`, `jpg`, `jpeg`, `png`) and verified MIME types via `finfo(FILEINFO_MIME_TYPE)` are permitted.
- **Directory Traversal Defense**: Stored names use randomized hex strings (`bin2hex(random_bytes(16))`). Download handlers enforce `basename()` on stored filenames.
- **Direct Access Denied**: Uploads in `storage/uploads/` are shielded by `.htaccess` with `Require all denied`. Downloads are routed through authorized PHP scripts with `Content-Disposition: attachment; filename=...; filename*=UTF-8''...` and `X-Content-Type-Options: nosniff`.
- **Static Asset Uploads**: Public asset uploads in `assets/uploads/` (company logos and branding) are protected by `.htaccess` which explicitly disables PHP engine execution (`php_flag engine off`, removes script handlers, and denies execution of `.php`, `.phtml`, `.phar`, and executable extensions) while allowing only validated static image formats (`jpg`, `jpeg`, `png`, `webp`, `svg`, `ico`). Directory indexing is disabled and placeholder `index.html` files are deployed.

### G. Security Headers, HSTS & CSP
In `includes/bootstrap.php`:
- `Strict-Transport-Security: max-age=31536000; includeSubDomains` (sent automatically on HTTPS connections)
- `X-Frame-Options: SAMEORIGIN` (prevents clickjacking)
- `X-Content-Type-Options: nosniff` (prevents MIME sniffing)
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: camera=(), microphone=(), geolocation=()`
- `Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; font-src 'self' data:; img-src 'self' data:; connect-src 'self'; frame-ancestors 'self';`

### H. Error Handling, Log Rotation & Incident Tracking
- In production (`APP_ENV=production`), `display_errors` is turned off.
- The global exception handler logs full stack traces and file paths to `storage/logs/app.log`.
- Log rotation is automatically executed when `app.log` exceeds 5MB, maintaining up to 5 historical archive files (`app.log.1` ... `app.log.5`).
- End users receive a clean generic message with a unique incident reference code (e.g. `ERR-XXXXXXX`).
- Custom branded error documents are deployed at `public/403.php`, `public/404.php`, and `public/500.php`.

### I. Installation & Setup Security
- The 3-step installer (`public/setup.php`) is protected by CSRF verification on every step and per-IP rate limiting (40 req/min).
- The installer automatically refuses execution if `storage/installed.lock` exists or if any user records are detected in the database.
- Upon completion, `public/setup.php` attempts immediate self-deletion (`unlink(__FILE__)`). A prominent security warning banner appears on both the login screen and administrative dashboards if the file remains on disk.

### J. Audit Trail Immutability & Masking
- All key administrative and HR events are recorded in `activity_logs`.
- The audit table is strictly append-only (no UPDATE or DELETE code paths).
- In [`modules/audit/view.php`](file:///c:/xampp/htdocs/aaza_hrms/modules/audit/view.php), sensitive payload keys (`password`, `password_hash`, `salary`, `offered_salary`, `nic_passport`, `token`, `csrf`, `account`, `card`) are masked as `[hidden]`.

---

## 3. Known Limitations & Future Recommendations

1. **Antivirus Scanning**: Integration with ClamAV daemon (`clamd`) is recommended for scanning uploaded documents at scale.
2. **Multi-Factor Authentication (MFA)**: Implementing TOTP (RFC 6238) for group administrator accounts would provide an additional authentication factor.
3. **Transparent Data Encryption (TDE)**: Enable filesystem encryption (BitLocker / LUKS) or MariaDB data-at-rest encryption for sensitive tablespaces.
