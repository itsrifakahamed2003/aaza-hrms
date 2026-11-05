# AaZa HRMS - Database Schema & Migrations

This directory contains the database definition and versioned migrations for AaZa HRMS.

---

## 1. Fresh Installations (Recommended)

For any new deployment (cPanel, Plesk, VPS, or local development):

- **Single Install File:** Import [`aaza_hrms_full.sql`](aaza_hrms_full.sql) directly into your empty database.
- **Web Installer:** Alternatively, use the automated installer at `http://your-domain/public/setup.php`, which imports `aaza_hrms_full.sql` automatically.

> [!NOTE]
> `aaza_hrms_full.sql` contains **no** `CREATE DATABASE` or `USE` statements. It is safe for hosting providers that enforce account-prefixed database names (e.g. `cpaneluser_aazahrms`). Simply create an empty database in your hosting control panel, select it, and import `aaza_hrms_full.sql`.

### MySQL CLI Quick Import:
```bash
mysql -u <db_user> -p <db_name> < database/aaza_hrms_full.sql
```

---

## 2. Existing Installations (Upgrades)

For existing instances that are already running an earlier phase of AaZa HRMS:

- Do **not** re-import `aaza_hrms_full.sql`.
- Navigate to the [`migrations/`](migrations/) directory.
- Apply **only** the migration files that are newer than your current installed version in numerical order:

```bash
mysql -u <db_user> -p <db_name> < database/migrations/phase19_holidays.sql
mysql -u <db_user> -p <db_name> < database/migrations/phase20_cleanup_permissions.sql
```

---

## 3. Developer Tooling

When adding a new migration to `database/migrations/`:
1. Save your migration file as `database/migrations/phase<N>_<description>.sql`.
2. Add the migration filename to `$migrationSequence` in `tools/build_full_sql.php`.
3. Regenerate the single installer file:
   ```bash
   php tools/build_full_sql.php
   ```
4. Verify consistency before committing:
   ```bash
   php tools/build_full_sql.php --check
   ```

