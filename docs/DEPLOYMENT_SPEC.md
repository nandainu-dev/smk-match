# Deployment Specification

Target environment is Hostinger Premium Web Hosting. The existing primary site is `smkassalamahdepok.com`; SSL is active and CDN is available. The V1 production compatibility target is PHP 8.3. No production connection, database, subdomain, cron job, Git integration, or deployment is authorized or configured in this baseline.

## Verified hosting capabilities

PHP 8.3 is active (with 8.2, 8.4, and 8.5 selectable). Verified enabled capabilities include PDO, `nd_pdo_mysql`, MySQLi/`nd_mysqli`, mysqlnd, mbstring, fileinfo, gd, imagick, intl, zip, dom, opcache, bcmath, and phar. Verified PHP state: `displayErrors=OFF`, `logErrors=ON`, `fileUploads=ON`, `session.useStrictMode=ON`, `session.cookieHttpOnly=ON`, `shortOpenTag=OFF`, `opcache.enable=ON`, and `date.timezone=Asia/Jakarta`.

MySQL database creation, separate database-user creation, and phpMyAdmin are verified available. SSH, Hostinger Git deployment integration (GitHub and GitLab), and cron jobs (PHP and custom modes) are also verified available. SSH is inactive; Git integration is not connected; automatic deployment is not configured; and no SMK Match cron job exists. Hostinger supports subdomains and custom document folders. `match.smkassalamahdepok.com` is only a planned candidate: it has not been created and no document root has been selected.

## Architecture constraints

Use shared-hosting-compatible PHP + MySQL/MariaDB + PDO + browser JavaScript/fetch. The live monitor uses lightweight HTTP polling; WebSocket/Swoole is not a V1 requirement. Hostinger disables shell functions including `system`, `exec`, `shell_exec`, `passthru`, `proc_open`, and `popen`; runtime code must not depend on them.

Composer availability is **PENDING VERIFICATION**. Releases must remain compatible either with Composer available through SSH or with dependencies prepared locally and included in a controlled release package. GitHub must not connect directly to production during development; the production deployment mechanism will be defined in a later authorized gate.

## Pending production verification and decisions

The following remain **PENDING**: exact MySQL/MariaDB server version; Composer availability; exact PHP resource limits; final subdomain document root; final subdomain-specific `session.cookieSecure` behavior; `expose_php` hardening; web-server/rewrite details; and the production deployment mechanism.

Before launch, verify remaining capabilities, provision environment configuration securely, configure HTTPS, validate backup and rollback, run deployment/UAT procedures, and obtain explicit production authorization. No automatic production deployment is permitted.
