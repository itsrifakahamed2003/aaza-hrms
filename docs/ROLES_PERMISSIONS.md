# AaZa HRMS - Roles & Permissions Reference

This document provides a comprehensive mapping of every permission code defined in **AaZa HRMS**, its descriptive scope, and the assigned system roles based on database seed definitions.

---

## 1. System Roles Overview

| Role Slug | Label | Description / Scope |
|:---|:---|:---|
| `group_admin` | Group Administrator | Superuser with unrestricted global access across all group companies. |
| `group_hr` | Group HR Manager | Enterprise HR executive with multi-company analytics, group dashboard, recruitment, and audit oversight. |
| `company_admin`| Company Administrator | Operational head of a single assigned subsidiary (e.g. AZT, AZL). |
| `hr_manager` | HR Manager | Primary manager of daily HR operations, leave approvals, recruitment offers, and employee records. |
| `hr_officer` | HR Officer | Operational HR staff member handling candidate entries, attendance marking, and leave requests. |
| `interviewer` | Interviewer | Panelist assigned to specific interview rounds with scoring and evaluation privileges. |
| `manager` | Department Manager | Line manager with visibility into direct report attendance and department leave approvals. |
| `employee` | Employee | Standard employee account with self-service leave requests and attendance history. |

---

## 2. Master Permission Matrix

| Module | Permission Code | Description | Default Roles Assigned |
|:---|:---|:---|:---|
| **Audit** | `audit.view` | View activity audit trail logs | `group_admin`, `group_hr`, `company_admin` (own company only) |
| **Audit** | `audit.export` | Export activity audit trail logs to CSV | `group_admin`, `group_hr` |
| **Reports** | `dashboard.group` | View group-level cross-company KPIs & analytics | `group_admin`, `group_hr` |
| **Reports** | `report.view` | Access analytics dashboards and report metrics | `group_admin`, `group_hr`, `company_admin`, `hr_manager`, `hr_officer` |
| **Reports** | `report.export` | Stream report datasets to CSV with formula injection protection | `group_admin`, `group_hr`, `company_admin`, `hr_manager` |
| **Employees** | `employee.view` | View employee directory and profiles | `group_admin`, `company_admin`, `hr_manager`, `hr_officer`, `manager` |
| **Employees** | `employee.create` | Register new employee profiles | `group_admin`, `company_admin`, `hr_manager`, `hr_officer` |
| **Employees** | `employee.edit` | Modify employee profile details and status | `group_admin`, `company_admin`, `hr_manager`, `hr_officer` |
| **Employees** | `employee.transfer`| Transfer employees across subsidiary companies | `group_admin`, `company_admin`, `hr_manager` |
| **Employees** | `salary.view` | View confidential employee remuneration figures | `group_admin`, `group_hr`, `company_admin`, `hr_manager` |
| **Documents** | `document.view` | Access employee uploaded documentation | `group_admin`, `company_admin`, `hr_manager`, `hr_officer` |
| **Documents** | `document.upload` | Upload personnel files and attachments | `group_admin`, `company_admin`, `hr_manager`, `hr_officer` |
| **Documents** | `document.confidential` | Access confidential employee records | `group_admin`, `company_admin`, `hr_manager` |
| **Recruitment**| `vacancy.view` | View job vacancies across permitted companies | `group_admin`, `company_admin`, `hr_manager`, `hr_officer` |
| **Recruitment**| `vacancy.manage` | Create, edit, and close job vacancies | `group_admin`, `company_admin`, `hr_manager`, `hr_officer` |
| **Recruitment**| `candidate.view` | View candidates and talent pipeline | `group_admin`, `company_admin`, `hr_manager`, `hr_officer`, `interviewer` |
| **Recruitment**| `candidate.manage` | Register candidates and transition stages | `group_admin`, `company_admin`, `hr_manager`, `hr_officer` |
| **Recruitment**| `candidate.cross_company` | Search candidates across all group companies | `group_admin`, `group_hr` |
| **Recruitment**| `interview.view` | View scheduled interview sessions | `group_admin`, `company_admin`, `hr_manager`, `hr_officer`, `interviewer` |
| **Recruitment**| `interview.manage` | Schedule, reschedule, or cancel interviews and manage panels | `group_admin`, `company_admin`, `hr_manager`, `hr_officer` |
| **Recruitment**| `interview.view_evaluations` | Inspect evaluation scores and remarks | `group_admin`, `company_admin`, `hr_manager` |
| **Recruitment**| `offer.view` | View employment job offers | `group_admin`, `group_hr`, `company_admin`, `hr_manager`, `hr_officer` |
| **Recruitment**| `offer.manage` | Issue offers and convert candidates to employees | `group_admin`, `group_hr`, `company_admin`, `hr_manager` |
| **Attendance** | `attendance.view` | View daily and monthly attendance records | `group_admin`, `group_hr`, `company_admin`, `hr_manager`, `hr_officer`, `manager` |
| **Attendance** | `attendance.record` | Record and modify daily attendance entries | `group_admin`, `company_admin`, `hr_manager`, `hr_officer` |
| **Leave** | `leave.view` | View leave requests and department balances | `group_admin`, `group_hr`, `company_admin`, `hr_manager`, `hr_officer`, `manager` |
| **Leave** | `leave.request` | Submit leave requests (self or staff) | `group_admin`, `group_hr`, `company_admin`, `hr_manager`, `hr_officer`, `manager`, `employee` |
| **Leave** | `leave.approve` | Approve or reject submitted leave applications | `group_admin`, `group_hr`, `company_admin`, `hr_manager`, `hr_officer`, `manager` |
| **Leave** | `leave.manage_types` | Configure company leave policies & quotas | `group_admin`, `group_hr`, `company_admin`, `hr_manager` |
| **Leave** | `leave_type.delete` | Safely delete inactive company leave types with zero leave requests | `group_admin` |
| **Administration**| `company.view` | View group companies | `group_admin`, `company_admin`, `hr_manager`, `hr_officer` |
| **Administration**| `company.manage` | Create, modify, and activate/deactivate companies | `group_admin` |
| **Administration**| `company.logo` | Upload and configure company branding logos | `group_admin` |
| **Administration**| `company.delete` | Safely delete inactive companies with zero dependent records | `group_admin` |
| **Administration**| `company.settings`| Configure multi-tenant company preferences, rules & formats | `group_admin`, `company_admin` |
| **Performance**   | `performance.view` | View company performance reviews and appraisal analytics | `group_admin`, `company_admin`, `hr_manager` |
| **Performance**   | `performance.manage`| Create, edit, submit, and reopen performance appraisals | `group_admin`, `company_admin`, `hr_manager` |
| **Performance**   | `performance.view_own`| View own acknowledged or submitted performance reviews | `group_admin`, `group_hr`, `company_admin`, `hr_manager`, `manager`, `employee` |
| **Administration**| `department.manage`| Manage company departments and designations | `group_admin`, `company_admin`, `hr_manager` |
| **Administration**| `department.delete`| Safely delete inactive company departments with zero employees | `group_admin` |
| **Administration**| `designation.delete`| Safely delete inactive company designations with zero employees | `group_admin` |
| **Administration**| `user.view` | View user accounts and assignment lists | `group_admin`, `company_admin`, `hr_manager` |
| **Administration**| `user.manage` | Create, update, and toggle user accounts | `group_admin`, `company_admin` (own company non-admin users) |
| **Administration**| `user.delete` | Safely delete inactive system user accounts with zero dependencies | `group_admin` |
| **Administration**| `role.manage` | Edit role-permission mapping rules | `group_admin` |
| **Administration**| `role.delete` | Safely delete non-system custom roles with zero user assignments | `group_admin` |

---

## 3. Granular User Permission Overrides

AaZa HRMS supports per-user, per-company granular permission overrides stored in `user_permission_overrides`:

1. **Precedence Hierarchy:**
   - **Explicit Deny (`deny`):** Strictly denies the capability in the target company, even if granted by assigned system roles.
   - **Explicit Allow (`allow`):** Grants the capability in the target company, even if not granted by assigned system roles.
   - **Role Default:** If no override is present, effective permissions inherit from the user's assigned roles in that company.
2. **Audit Logging:** Every grant, update, or revocation of an override is recorded in the activity audit trail (`user.override.set`, `user.override.remove`).
3. **Safety Protection:** Administrative lockout guards prevent stripping essential management permissions from root system administrators.
