# AaZa HRMS - Database Schema & Relationships

This document outlines the database architecture, tables, relationships, and migration history for **AaZa HRMS (Multi-Company HR & Recruitment Management System)**.

---

## 1. Architecture & Design Principles

- **Engine & Charset:** MySQL 5.7+ / MariaDB 10.4+, InnoDB storage engine, `utf8mb4` encoding with `utf8mb4_unicode_ci` collation.
- **Referential Integrity:** Foreign keys enforce strict referential integrity across companies, users, employees, candidates, and applications.
- **Multi-Tenant Scoping:** Core business entities (`employees`, `job_vacancies`, `interviews`, `job_offers`, `attendance`, `leave_requests`, `company_holidays`, `performance_reviews`, `activity_logs`) are partitioned by `company_id`.
- **Soft Deletion & Auditability:** Strict deletion guards prevent data loss; operational actions are immutably logged into `activity_logs`.
- **Single-File Provisioning:** Fresh installations use [`database/aaza_hrms_full.sql`](../database/aaza_hrms_full.sql), which contains the complete schema and seeds without database-binding statements (`USE` or `CREATE DATABASE`).

---

## 2. Table Summary & Relationships

| # | Table Name | Description | Key Relationships | Status |
|---|------------|-------------|-------------------|--------|
| 1 | `companies` | Multi-company tenant entities in the group (e.g. AaZa Tech, AaZa Logistics). | Referenced by all company-scoped child tables via `company_id`. | Active |
| 2 | `company_settings` | Key-value configuration pairs per company. | FK to `companies(id)`. | Active |
| 3 | `company_holidays` | Company and group-wide public holidays (NULL company = group-wide). | FK to `companies(id)`. | Active |
| 4 | `system_settings` | Global group-wide configuration parameters (e.g. login branding). | Standalone configuration store. | Active |
| 5 | `roles` | RBAC system roles (`group_admin`, `group_hr`, `company_admin`, `hr_manager`, `hr_officer`, `employee`). | Referenced by `company_user_assignments(role_id)` and `role_permissions(role_id)`. | Active |
| 6 | `permissions` | Granular capability slugs categorized by module. | Referenced by `role_permissions(permission_id)`. | Active |
| 7 | `role_permissions` | RBAC junction mapping permissions to roles. | Composite PK `(role_id, permission_id)`. | Active |
| 8 | `user_permission_overrides` | Direct per-user per-company grant/deny overrides. | FKs to `users(id)`, `companies(id)`, `permissions(id)`. | Active |
| 9 | `users` | User accounts with authentication credentials and group admin privileges. | Primary entity referenced across system. | Active |
| 10 | `company_user_assignments` | Many-to-many company access assignments for company administrators and staff. | FKs to `users(user_id)` and `companies(company_id)`. | Active |
| 11 | `departments` | Organizational departments per company. | FK to `companies(company_id)`. | Active |
| 12 | `designations` | Job titles and organizational designations per company. | FK to `companies(company_id)`. | Active |
| 13 | `employees` | Employee records, demographic data, compensation, and manager hierarchy. | FKs to `companies(id)`, `departments(id)`, `designations(id)`, `users(user_id)`. | Active |
| 14 | `employment_history` | Historical timeline of designation, department, and salary changes per employee. | FKs to `employees(employee_id)`, `companies(id)`. | Active |
| 15 | `employee_documents` | Scanned identity, contracts, and certificates for employees. | FKs to `employees(employee_id)`, `companies(company_id)`. | Active |
| 16 | `employee_transfers` | Formal transfer tracking between group companies or departments. | FKs to `employees(employee_id)`, `companies(from/to)`. | Active |
| 17 | `job_vacancies` | Job requisitions with headcount, closing dates, and hiring status. | FKs to `companies(company_id)`, `departments(id)`, `designations(id)`. | Active |
| 18 | `candidates` | Unique applicant profiles indexed by email and NIC/passport. | Referenced by `candidate_applications` and documents. | Active |
| 19 | `candidate_applications` | Specific application instances linking candidate to a vacancy. | FKs to `candidates(candidate_id)`, `job_vacancies(vacancy_id)`, `companies(company_id)`. | Active |
| 20 | `candidate_status_history` | Immutable log of status transitions throughout recruitment pipeline. | FKs to `candidate_applications(application_id)`. | Active |
| 21 | `candidate_documents` | Candidate resumes (CVs), certificates, and evaluation documents. | FKs to `candidates(candidate_id)`. | Active |
| 22 | `interviews` | Scheduled interview rounds, dates, modes, and meeting details. | FKs to `candidate_applications(application_id)` and `companies(company_id)`. | Active |
| 23 | `interview_panel` | Many-to-many junction of users assigned to interview an applicant. | FKs to `interviews(interview_id)` and `users(user_id)`. | Active |
| 24 | `interviewers` | **Legacy table:** Retained for backward schema parity. | **Legacy / Unused** | Legacy |
| 25 | `interview_evaluations` | Candidate ratings (1-5 scale) across technical, communication, attitude with recommendation. | FKs to `interviews(interview_id)` and `users(evaluator_id)`. | Active |
| 26 | `job_offers` | Formal employment offers issued to selected candidates. | FKs to `candidate_applications(application_id)`, `companies(company_id)`. | Active |
| 27 | `attendance` | Daily attendance records, check-in/out times, and presence status. | FKs to `employees(employee_id)` and `companies(company_id)`. | Active |
| 28 | `leave_types` | Annual leave quotas, categories, and document requirements. | FK to `companies(company_id)` (NULL indicates group default). | Active |
| 29 | `leave_requests` | Employee leave applications with date spans, half-day flag, and status. | FKs to `employees(employee_id)`, `companies(company_id)`, `leave_types(leave_type_id)`. | Active |
| 30 | `leave_approvals` | Multi-step approval decisions recorded for leave requests. | FKs to `leave_requests(leave_request_id)` and `users(approver_id)`. | Active |
| 31 | `notifications` | In-app user notifications with deduplication keys and read status. | FK to `users(user_id)`. | Active |
| 32 | `performance_reviews`| Periodic employee appraisals with scoring and review lifecycle. | FKs to `companies(company_id)`, `employees(employee_id)`, `users(reviewer_user_id)`. | Active |
| 33 | `performance_goals`  | Measurable target goals linked to employee performance reviews. | FKs to `companies(company_id)`, `employees(employee_id)`, `performance_reviews(review_id)`. | Active |
| 34 | `activity_logs` | Comprehensive security and operational audit trail with masked context JSON. | FKs to `companies(company_id)` and `users(user_id)`. | Active |
| 35 | `rate_limits` | IP and account throttling store for authentication attempts and public job applications. | Indexed by `(ip_address, action, created_at)`. | Active |

---

## 3. Installation & Provisioning

### Fresh Installs
For new deployments, import the single master file:
```bash
mysql -u <db_user> -p <db_name> < database/aaza_hrms_full.sql
```
*Alternatively, complete the interactive Web Installer at `http://your-domain/public/setup.php`.*

### Existing Installs (Incremental Upgrades)
For existing installations, apply only the newer migrations located in [`database/migrations/`](../database/migrations/) in sequential order.

### Synchronizing the Master Installer
Whenever a new migration file is created:
```bash
php tools/build_full_sql.php
```
Verify synchronization:
```bash
php tools/build_full_sql.php --check
```
