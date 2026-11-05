# AaZa HRMS - Manual Smoke Testing Checklist & Role Access Matrix

This checklist specifies the expected HTTP response status codes for each standard system role across all application routes.

---

## 1. Test Roles Definition

| Role Slug | Description | Scope |
|:---|:---|:---|
| `group_admin` | Global Super Administrator | Cross-Company / All Tenancies |
| `group_hr` | Group HR Operations Manager | Cross-Company Analytics & Recruitment |
| `company_admin` | Subsidiary Company Administrator | Single Assigned Company (e.g. AZT) |
| `hr_manager` | Subsidiary HR Manager | Single Assigned Company (e.g. AZT) |
| `hr_officer` | Subsidiary HR Operational Staff | Single Assigned Company (e.g. AZT) |
| `employee` | Plain Staff Self-Service Account | Linked Employee Only |

---

## 2. Route Access Matrix & Expected Results

| Route / Module | `group_admin` | `group_hr` | `company_admin` | `hr_manager` | `hr_officer` | `employee` | Notes / Expected Behavior |
|:---|:---:|:---:|:---:|:---:|:---:|:---:|:---|
| `public/login.php` | 200 / Redir | 200 / Redir | 200 / Redir | 200 / Redir | 200 / Redir | 200 / Redir | Unauthenticated sees form; authenticated redirects to dashboard |
| `public/logout.php` | 302 | 302 | 302 | 302 | 302 | 302 | POST only + CSRF; invalidates session, redirects to login |
| `public/index.php` (Dashboard) | 200 | 200 | 200 | 200 | 200 | 200 | Restricted stats hidden without matching permission |
| `public/apply.php` (Public Portal) | 200 | 200 | 200 | 200 | 200 | 200 | Public careers page; honeypot & IP rate limit active |
| `public/setup.php` | 403 | 403 | 403 | 403 | 403 | 403 | Refuses to execute once installed / admin exists |
| `modules/auth/change_password.php`| 200 | 200 | 200 | 200 | 200 | 200 | Self-service credential update; audited |
| `modules/audit/index.php` | 200 | 200 | 200 | 403 | 403 | 403 | `audit.view` required; scoped to own company for company_admin |
| `modules/audit/view.php?id=N` | 200 | 200 | 200 / 403 | 403 | 403 | 403 | 403 if target log belongs to another company |
| `modules/companies/index.php` | 200 | 403 | 403 | 403 | 403 | 403 | Group Admin only |
| `modules/companies/form.php` | 200 | 403 | 403 | 403 | 403 | 403 | Group Admin only |
| `modules/users/index.php` | 200 | 403 | 403 | 403 | 403 | 403 | Group Admin only |
| `modules/users/roles.php` | 200 | 403 | 403 | 403 | 403 | 403 | Group Admin only |
| `modules/employees/index.php` | 200 | 200 | 200 | 200 | 403 | 403 | Scoped to accessible companies |
| `modules/employees/form.php` | 200 | 200 | 200 | 200 | 403 | 403 | `employee.create` / `employee.edit` required |
| `modules/employees/view.php?id=N` | 200 | 200 | 200 / 403 | 200 / 403 | 403 | 403 | 403 if employee belongs to unauthorized company |
| `modules/employees/transfer.php` | 200 | 200 | 200 | 200 | 403 | 403 | Requires transfer permissions across both companies |
| `modules/vacancies/index.php` | 200 | 200 | 200 | 200 | 200 | 403 | `vacancy.view` required |
| `modules/vacancies/form.php` | 200 | 200 | 200 | 200 | 403 | 403 | `vacancy.manage` required |
| `modules/vacancies/pipeline.php` | 200 | 200 | 200 | 200 | 200 | 403 | `vacancy.view` required; status moves require `candidate.manage` |
| `modules/candidates/index.php` | 200 | 200 | 200 | 200 | 200 | 403 | `candidate.view` required |
| `modules/candidates/form.php` | 200 | 200 | 200 | 200 | 403 | 403 | `candidate.manage` required |
| `modules/interviews/index.php` | 200 | 200 | 200 | 200 | 200 | 403 | `interview.view` required |
| `modules/interviews/schedule.php` | 200 | 200 | 200 | 200 | 403 | 403 | `interview.manage` required |
| `modules/interviews/evaluate.php` | 200 | 200 | 200 | 200 | 200 / 403 | 403 | Panel membership required for the specific interview |
| `modules/offers/index.php` | 200 | 200 | 200 | 200 | 403 | 403 | `offer.view` required |
| `modules/offers/form.php` | 200 | 200 | 200 | 200 | 403 | 403 | `offer.manage` required |
| `modules/offers/convert.php` | 200 | 200 | 200 | 200 | 403 | 403 | Converts accepted offer to employee record |
| `modules/attendance/daily.php` | 200 | 200 | 200 | 200 | 200 | 403 | `attendance.view` required; save requires `attendance.record` |
| `modules/attendance/employee_attendance.php` | 200 | 200 | 200 | 200 | 200 | 200 | Linked employee self check-in / check-out history |
| `modules/leave/index.php` | 200 | 200 | 200 | 200 | 403 | 403 | `leave.view` or `leave.approve` required |
| `modules/leave/my_leaves.php` | 200 | 200 | 200 | 200 | 200 | 200 | Employee self-service leave balance & history |
| `modules/leave/request.php` | 200 | 200 | 200 | 200 | 200 | 200 | `leave.request` or `leave.manage` required |
| `modules/leave/decide.php` | 200 | 200 | 200 | 200 | 403 | 403 | `leave.approve` required; self-approval strictly blocked |
| `modules/leave/types.php` | 200 | 200 | 200 | 403 | 403 | 403 | `leave.manage_types` required |
| `modules/reports/index.php` | 200 | 200 | 200 | 200 | 200 | 403 | `report.view` required |
| `modules/reports/interview_results.php` | 200 | 200 | 200 | 200 | 200 | 403 | Scores masked without `interview.view_evaluations` |

---

## 3. Manual Verification Steps

1. **Sign in as `group_admin`**: Verify all modules are accessible and no cross-company restriction blocks occur.
2. **Sign in as `group_hr`**: Verify Audit Logs and Reports are accessible across companies. Verify `modules/companies/form.php` and `modules/users/form.php` return HTTP 403.
3. **Sign in as `company_admin`**: Verify Audit Log displays only events for their assigned company. Verify accessing Company B's audit entry or employee by direct ID returns HTTP 403.
4. **Sign in as `hr_manager`**: Verify leave approval, recruitment pipeline, and daily attendance work. Verify Audit Logs returns HTTP 403.
5. **Sign in as `hr_officer`**: Verify candidate entry and vacancy viewing. Verify leave approval and offer conversion return HTTP 403.
6. **Sign in as `employee`**: Verify navigation bar displays only "My Leave", "My Attendance", and "Change Password". Accessing any management module directly via URL returns HTTP 403.
7. **User Management Form Scope Isolation & Autofill Check**:
   - Sign in as `group_admin` (e.g., user Rifak / Ahamed).
   - Navigate to `/modules/users/form.php` (Create mode):
     - Verify that `full_name`, `username`, and `email` inputs are completely empty on load (not prefilled with the logged-in administrator's details).
     - Verify typing into each field works cleanly.
   - Navigate to `/modules/users/form.php?id=69` (Edit user 'safran'):
     - Verify that `full_name`, `username`, and `email` display Mohamed Safran's details (`Mohamed Safran`, `safran`), NOT the logged-in administrator's details.
     - Verify the topbar user avatar and dropdown menu still correctly show the logged-in admin (`Rifak`).
     - Save changes and verify the target user's data is updated accurately without corruption.
