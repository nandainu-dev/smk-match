# SMK Match — Project Master Specification

## Purpose and scope

SMK Match helps students discover their tendency toward school programs through a short, gamified personality-style quiz. The system is reusable across schools; school identity and programs are configuration, never application constants. Initial content may include DKV (THE VISUAL CREATOR), MPLB (THE SMART ORGANIZER), and PM (THE MARKET CONNECTOR), but the architecture supports N active programs.

## Interfaces

The mobile-first quiz targets 390×844 and flows Landing → Identity → Intro → Question → Final Round → Analyzing → Result Reveal → Result Detail → Share Result → Mission Complete. Identity captures full name, school, class, WhatsApp, and separate marketing consent.

The 1920×1080 live monitor has exactly four rotating main slides: Overall/Live Overview, DKV, MPLB, and PM. Each has a fixed right sidebar for QR and a compact live participant/result feed. V1 uses lightweight polling; WebSockets are not required.

The V1 runtime targets PHP 8.3 shared hosting with MySQL/MariaDB, PDO, and browser fetch. It must not require WebSocket/Swoole or server-side shell execution.

The desktop-first admin control center includes Overview, Quiz, Question Bank, Programs, Campaign, QR & Links, Participants, Results, Analytics, Live Monitor Settings, Appearance, School Profile, and Settings.

## Configuration requirements

School configuration covers name, logo, address, website, WhatsApp, email, Instagram, colors, and branding. Programs configure name, short name, personality title, description, colors, mascot, skills, careers, media, result copy, active state, and ordering. Quiz administration supports authoring, question/answer randomization, multi-program weights, preview, draft/publish, retry policy, and result/share visibility.

## Non-negotiable behavior

Answer choices stay visually neutral: no program colors, mascots, profession icons, department symbols, program labels, or fixed answer-position mapping while answering. Program identity becomes prominent only after result reveal. A WhatsApp number is not marketing consent. Duplicate protection uses visitor UUID, attempt UUID, and idempotent submission—not IP limits.

## Historical integrity

Publishing creates an immutable quiz version. Attempts, responses, raw scores, normalized percentages, and dominant program remain attached to that version and are never silently recalculated after later editing.
