BEANTRACK - PHP + MySQL/XAMPP Web System Prototype

Requirements: XAMPP with Apache + MySQL, PHP 8+

1. Copy the beantrack folder to: C:\xampp\htdocs\beantrack (or your web root).
2. Start Apache and MySQL in the XAMPP Control Panel.
3. In phpMyAdmin, use Import from the top navigation and import database/beantrack.sql.
	This automatically creates the `beantrack` database with the complete 17-table schema,
	audit hash-chain support, recipe management, multi-branch tracking, and initial seed data.
4. Open http://localhost/beantrack/ in your web browser.

Database Backups and Restores:
- Administrators can generate a full database backup or restore an existing SQL backup directly within the system at:
  http://localhost/beantrack/backup.php
- System exports strictly preserve the Philippine Time Zone (Asia/Manila / UTC+8) and the integrity of cryptographic audit hashes (SHA-256).

Cryptographic Audit Hash Chain:
- All system events are linked with SHA-256 hashes in `audit_trail` and tracked by `audit_chain_state`.
- Supervisors can verify log integrity at http://localhost/beantrack/audit_integrity.php.

Demo accounts (all password: password):
admin@beantrack.local / password
supervisor@beantrack.local / password
cashier@beantrack.local / password
inventory@beantrack.local / password
manager@beantrack.local / password

Roles:
- Administrator: users, backup/export-ready admin functions
- Supervisor: transactions, inventory review, reports, audit trail
- Cashier: transactions
- Inventory Staff: stock receiving/inventory
- Management: dashboards/reports

Notes:
- Online payment is represented as an optional/demo payment method. No real gateway is connected in this student prototype.
- The transaction save uses a MySQL transaction and updates inventory automatically.
