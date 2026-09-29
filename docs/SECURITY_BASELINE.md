# Security and Privacy Baseline

Implementation must use PDO prepared statements, CSRF protection, contextual output escaping/XSS defenses, secure admin sessions, `password_hash`/`password_verify`, and session regeneration. Uploads require MIME/type validation and size restrictions. Production requires HTTPS, `APP_DEBUG=false`, and secrets only from environment configuration; `.env` is never committed.

Collect only needed participant data. Marketing consent is an explicit separate checkbox with timestamp; a WhatsApp number alone is not consent. Submission safety uses visitor UUID, attempt UUID, and idempotency rather than IP-based exclusions. Privacy policy, consent copy, retention/deletion policy, rate limits, authorization matrix, audit logging, backups, and incident procedures are TODO for later security/privacy and launch gates.
