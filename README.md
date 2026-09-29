# SMK Match

SMK Match is a configurable, interactive Major Discovery Quiz platform for schools. It will provide a mobile-first quiz, result sharing, a live monitor, smart QR links, and an admin control center for school staff.

## Current status

G1 local bootstrap is implemented. Database, quiz functionality, and production deployment are not implemented.

## Local bootstrap

Requirements: PHP 8.3 or later; no Composer, Node, or NPM dependencies are required. Copy `.env.example` to `.env` and adjust only local safe values. Start the development server from the project root:

```powershell
php -S 127.0.0.1:8080 -t public
```

Open `http://127.0.0.1:8080/` for the development bootstrap page and `http://127.0.0.1:8080/health` for the JSON health response. The intended future production document root is `<project>/public`; no hosting configuration has been performed.

## Authoritative documents

The baseline in `docs/` is authoritative: `PROJECT_MASTER_SPEC.md`, `ROADMAP.md`, `DESIGN_SYSTEM.md`, `UI_BEHAVIOR.md`, `DATABASE_DESIGN.md`, `SCORING_SPEC.md`, `SECURITY_BASELINE.md`, `DEPLOYMENT_SPEC.md`, `DESIGN_REFERENCE_MANIFEST.md`, `DESIGN_ASSET_MANIFEST.md`, and `ASSET_INVENTORY.md`. The local `.fig` source is intentionally ignored; versioned PNGs are visual acceptance references and the four mascot PNGs are runtime assets.

## Branch strategy and workflow

`main` is the stable/release branch and `develop` is the development integration branch. Work proceeds one gate at a time: audit, implement, test, report PASS/FAIL, then stop for review. No production deployment occurs automatically.
