# Design Reference Manifest

## Source policy

`GAMES JURUSAN(1).fig` is the authoritative editable design source, maintained externally/local. A local copy currently exists at `design-reference/GAMES JURUSAN.fig`; `*.fig` is ignored and must never be committed in V1. Versioned visual acceptance references are `design-reference/**/*.png`. Previous Figma files and design exports are LEGACY / NON-AUTHORITATIVE.

`design-reference/` contains screenshots, visual acceptance references, character references, and legacy material. `public/assets/` contains only files intended for runtime serving. Screenshots of interface elements are references only and must later be recreated in HTML/CSS, never used as production UI assets.

## Authoritative references

Quiz references: six core frames (`01-landing` through `06-analyzing`) and four result frames each for DKV, MPLB, and PM. The supplied `05-final-round.png` is authoritative. Question presentation remains visually neutral; result variants correspond to THE VISUAL CREATOR, THE SMART ORGANIZER, and THE MARKET CONNECTOR.

Monitor references are exactly four slides: `01-live-overview.png`, `02-dkv-showcase.png`, `03-mplb-showcase.png`, and `04-pm-showcase.png`. Every slide has a fixed right sidebar for QR and compact, bottom-to-top activity feed. Any centered result popup from older material is legacy.

Admin references are the 13 supplied desktop screens: overview, quiz, questions, jurusan, campaign, QR/links, participants, results, analytics, live monitor, appearance, school profile, and settings. Sample SMK Assalamah branding/data in screenshots is reference content only; school identity remains configurable.

## Runtime mascot mapping

- `public/assets/mascots/mascot-all.png` — combined trio
- `public/assets/mascots/dkv/dkv-hero.png` — DKV hero
- `public/assets/mascots/mplb/mplb-hero.png` — MPLB hero
- `public/assets/mascots/pm/pm-hero.png` — PM hero

Turnarounds and source renders stay under `design-reference/characters/` as references, not runtime dependencies. `design-reference/legacy/quiz/identity-screen-v2-legacy.png` is non-authoritative.
