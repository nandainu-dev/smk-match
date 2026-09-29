# SMK Match

SMK Match is a configurable, interactive Major Discovery Quiz platform for schools. It will provide a mobile-first quiz, result sharing, a live monitor, smart QR links, and an admin control center for school staff.

## Current status

Specification and baseline only. No production application, database, or dependencies have been implemented yet.

## Authoritative documents

The baseline in `docs/` is authoritative: `PROJECT_MASTER_SPEC.md`, `ROADMAP.md`, `DESIGN_SYSTEM.md`, `UI_BEHAVIOR.md`, `DATABASE_DESIGN.md`, `SCORING_SPEC.md`, `SECURITY_BASELINE.md`, `DEPLOYMENT_SPEC.md`, and `DESIGN_REFERENCE_MANIFEST.md`.

## Branch strategy and workflow

`main` is the stable/release branch and `develop` is the development integration branch. Work proceeds one gate at a time: audit, implement, test, report PASS/FAIL, then stop for review. No production deployment occurs automatically.
