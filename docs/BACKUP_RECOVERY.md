# AaZa HRMS - Backup & Disaster Recovery Guide

This document outlines the backup, retention, and disaster recovery procedures for **AaZa Group HRMS**, covering multi-company operational databases, uploaded employee/candidate files, and configuration artifacts.

---

## 1. What Needs to Be Backed Up

To achieve a complete system recovery, three components must be backed up:

1. **MySQL / MariaDB Database (`aaza_hrms`)**:
   - Contains all enterprise records: companies, users, RBAC roles/permissions, employee directories, recruitment pipelines, attendance logs, leave balances, offers, and immutable activity audit logs.
2. **Uploaded Document Files (`storage/uploads/`)**:
   - `storage/uploads/employees/`: National IDs, contracts, education certificates.
   - `storage/uploads/candidates/`: Resumes, CVs, portfolios.
   - `storage/uploads/leave/`: Medical certificates, supporting leave documents.
3. **Application Configuration**:
   - `config/config.php` and `config/database.php` (must be archived in an encrypted, access-restricted secrets repository, never in public source control).

---

## 2. Automated Backup Utility (`bin/backup.php`)

AaZa HRMS includes a production-grade CLI backup script: [`bin/backup.php`](file:///c:/xampp/htdocs/aaza_hrms/bin/backup.php).

### Features
- **CLI Only**: Rejects HTTP web invocations with HTTP 403.
- **Secure Password Handling**: Uses temporary client defaults files (`--defaults-extra-file`) with `0600` permissions. Passwords are never visible in CLI command lines or system process lists.
- **Transactional Consistency**: Uses `--single-transaction`, `--routines`, and `--triggers` to ensure non-blocking atomic dumps on InnoDB.
- **Gzip Compression**: Compresses raw SQL output using Level 9 gzip (`.sql.gz`).
- **Optional Uploads Archiving**: Packs `storage/uploads` into a timestamped `.zip` archive.
- **Automated Retention**: Keeps the last $N$ historical archives (default: 14) and automatically prunes older backups.
- **Audit Logging**: Logs all backup operations and file sizes to `storage/logs/app.log`.

### Running Manually

```bash
# Standard database backup (last 14 kept by default)
php bin/backup.php

# Database backup + Storage Uploads ZIP archive
php bin/backup.php --uploads

# Custom retention cap (e.g. keep last 30 backups)
php bin/backup.php --uploads --keep=30

# Custom destination directory
php bin/backup.php --uploads --path=/var/backups/aaza_hrms
```

---

## 3. Scheduling Automated Backups

### A. Windows Task Scheduler (XAMPP Environments)
1. Open **Task Scheduler** (`taskschd.msc`).
2. Click **Create Basic Task...** and name it `AaZa HRMS Daily Backup`.
3. Set the Trigger to **Daily** at an off-peak time (e.g., `01:30 AM`).
4. Set Action to **Start a program**:
   - **Program/script**: `C:\xampp\php\php.exe`
   - **Add arguments**: `bin/backup.php --uploads`
   - **Start in**: `C:\xampp\htdocs\aaza_hrms`
5. Under General properties, configure:
   - Run whether user is logged on or not.
   - Run with highest privileges.

### B. Linux / Unix Cron
Edit the root or system crontab (`crontab -e`):

```cron
# Run daily at 02:00 AM
0 2 * * * /usr/bin/php /var/www/aaza_hrms/bin/backup.php --uploads >> /var/log/aaza_backup.log 2>&1
```

---

## 4. Step-by-Step Recovery Procedure

### Step 1: Decompress and Restore the Database

1. Locate the desired `.sql.gz` backup file in `storage/backups/`.
2. Decompress the archive:
   - **Windows PowerShell**:
     ```powershell
     # Using 7-Zip or PHP
     php -r '$data = gzdecode(file_get_contents("storage/backups/aaza_hrms_db_YYYYMMDD_HHMMSS.sql.gz")); file_put_contents("restore.sql", $data);'
     ```
   - **Linux / Git Bash**:
     ```bash
     gunzip -c storage/backups/aaza_hrms_db_YYYYMMDD_HHMMSS.sql.gz > restore.sql
     ```
3. Import the SQL dump into MariaDB / MySQL:
   ```bash
   mysql -u root -p aaza_hrms < restore.sql
   ```
4. Securely delete the uncompressed `restore.sql` file once imported:
   ```bash
   del restore.sql
   ```

### Step 2: Restore Uploaded Files
1. Locate the corresponding `aaza_hrms_uploads_YYYYMMDD_HHMMSS.zip`.
2. Extract the archive into the storage uploads directory:
   - **Windows**:
     ```powershell
     Expand-Archive -Path "storage/backups/aaza_hrms_uploads_YYYYMMDD_HHMMSS.zip" -DestinationPath "storage/uploads" -Force
     ```
   - **Linux**:
     ```bash
     unzip -o storage/backups/aaza_hrms_uploads_YYYYMMDD_HHMMSS.zip -d storage/uploads
     ```
3. Verify that `storage/uploads/.htaccess` exists with `Require all denied`.

---

## 5. Testing Restores (Copy Database Verification)

Never test restore procedures against production! Always test on a separate test database:

```sql
CREATE DATABASE IF NOT EXISTS aaza_hrms_restore_test
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Import the backup into the test database:
```bash
mysql -u root -p aaza_hrms_restore_test < restore.sql
```

Verify row counts and integrity:
```sql
USE aaza_hrms_restore_test;
SELECT 'companies' AS tbl, COUNT(*) FROM companies
UNION ALL SELECT 'users', COUNT(*) FROM users
UNION ALL SELECT 'employees', COUNT(*) FROM employees
UNION ALL SELECT 'candidate_applications', COUNT(*) FROM candidate_applications
UNION ALL SELECT 'activity_logs', COUNT(*) FROM activity_logs;
```

Drop the test database once verified:
```sql
DROP DATABASE aaza_hrms_restore_test;
```

---

## 6. Retention & Privacy Compliance (GDPR / Data Protection)

> [!WARNING]
> Database dumps and uploads contain sensitive personal data (PII) including National Identity Card numbers, phone numbers, addresses, salary details, and medical leave documentation.

1. **Storage Access Control**: The `storage/backups` directory must have restricted OS filesystem permissions (`0700` or `0750`). Web access is blocked by the root `.htaccess`.
2. **Encryption at Rest**: If backups are transferred offsite (e.g. S3, Azure Blob, SFTP), they must be encrypted before transit using AES-256 (e.g. `gpg --symmetric --cipher-algo AES256`).
3. **Retention Period**: Retain backups only for the minimum period required for disaster recovery (recommended: 14 to 30 days). The `--keep` parameter enforces automated pruning.
4. **Right to Erasure**: When an employee or candidate record is legally deleted/anonymized, ensure that historical backups are either aged out according to retention policies or documented in data processing registers.
