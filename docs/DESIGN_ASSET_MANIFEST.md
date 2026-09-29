# SMK Match — G0.2 Design Asset Manifest

## Baseline intent
This package normalizes the latest supplied UI exports and character artwork into repository-ready semantic paths. It does not contain application code.

## Visual reference baseline

### Quiz — core flow
- `design-reference/quiz/core/01-landing.png`
- `design-reference/quiz/core/02-identity.png`
- `design-reference/quiz/core/03-intro.png`
- `design-reference/quiz/core/04-question.png`
- `design-reference/quiz/core/05-final-round.png`
- `design-reference/quiz/core/06-analyzing.png`

### Quiz — DKV result variant
- `design-reference/quiz/results/dkv/result-reveal.png`
- `design-reference/quiz/results/dkv/result-detail.png`
- `design-reference/quiz/results/dkv/share-result.png`
- `design-reference/quiz/results/dkv/finish.png`

### Quiz — MPLB result variant
- `design-reference/quiz/results/mplb/result-reveal.png`
- `design-reference/quiz/results/mplb/result-detail.png`
- `design-reference/quiz/results/mplb/share-result.png`
- `design-reference/quiz/results/mplb/finish.png`

### Quiz — PM result variant
- `design-reference/quiz/results/pm/result-reveal.png`
- `design-reference/quiz/results/pm/result-detail.png`
- `design-reference/quiz/results/pm/share-result.png`
- `design-reference/quiz/results/pm/finish.png`

### Live monitor — exactly four authoritative slides
- `design-reference/monitor/01-live-overview.png`
- `design-reference/monitor/02-dkv-showcase.png`
- `design-reference/monitor/03-mplb-showcase.png`
- `design-reference/monitor/04-pm-showcase.png`

Monitor rule: all slides use the fixed right-side QR + live participant/result feed pattern from the latest monitor design direction.

### Admin baseline
- `design-reference/admin/overview.png`
- `design-reference/admin/quiz.png`
- `design-reference/admin/questions.png`
- `design-reference/admin/jurusan.png`
- `design-reference/admin/campaign.png`
- `design-reference/admin/qr-links.png`
- `design-reference/admin/participants.png`
- `design-reference/admin/results.png`
- `design-reference/admin/analytics.png`
- `design-reference/admin/live-monitor.png`
- `design-reference/admin/appearance.png`
- `design-reference/admin/school-profile.png`
- `design-reference/admin/settings.png`

`ADMIN/identity-screen-v2.png` from the source ZIP was a mobile-size artifact inside the Admin directory. It is preserved only as `design-reference/legacy/quiz/identity-screen-v2-legacy.png` and is NOT part of the authoritative Admin baseline.

## Production mascot assets
Transparent PNG assets intended for direct application use:
- `public/assets/mascots/mascot-all.png` — trio / landing hero
- `public/assets/mascots/dkv/dkv-hero.png` — DKV hero
- `public/assets/mascots/mplb/mplb-hero.png` — MPLB hero
- `public/assets/mascots/pm/pm-hero.png` — PM hero

These four assets were supplied as transparent RGBA PNG files at 1800×1500 and were renamed semantically without recompression.

## Character reference assets
Front/side/back views are stored under:
- `design-reference/characters/turnaround/dkv/`
- `design-reference/characters/turnaround/mplb/`
- `design-reference/characters/turnaround/pm/`

Background-containing character renders are preserved as reference-only assets under:
- `design-reference/characters/source-renders/`

## Source-to-target rename map

| Source | Target |
|---|---|
| `No Background/1.png` | `public/assets/mascots/mascot-all.png` |
| `No Background/2.png` | `public/assets/mascots/dkv/dkv-hero.png` |
| `No Background/3.png` | `public/assets/mascots/mplb/mplb-hero.png` |
| `No Background/4.png` | `public/assets/mascots/pm/pm-hero.png` |
| `QUIZ/result-reveal-screen-v2.png` | `design-reference/quiz/results/dkv/result-reveal.png` |
| `QUIZ/result-detail-screen-v2.png` | `design-reference/quiz/results/dkv/result-detail.png` |
| `QUIZ/share-result-screen-v2.png` | `design-reference/quiz/results/dkv/share-result.png` |
| `QUIZ/finish-screen-v2.png` | `design-reference/quiz/results/dkv/finish.png` |
| `ADMIN/identity-screen-v2.png` | `design-reference/legacy/quiz/identity-screen-v2-legacy.png` |

## Important UI constraints carried forward
- Question answer cards remain visually neutral and must not reveal DKV/MPLB/PM scoring direction.
- Program-specific colors/mascots become strong only after result reveal or in program showcase contexts.
- The monitor has exactly 4 main rotating slides.
- The monitor live participant feed remains visible in the right sidebar and moves bottom-to-top.
- Admin QR/Link management represents permanent aliases whose destination campaign can change without changing the printed QR.

## Editable design source and Git policy

`GAMES JURUSAN(1).fig` is the external/local authoritative editable source. A local copy may be retained for audit, but `.fig` files are ignored and must not be committed in V1. Git acceptance references are the PNG files beneath `design-reference/`; prior Figma/design versions are LEGACY / NON-AUTHORITATIVE.

The sample school identity visible in supplied Admin/Monitor references is not a product default. School name, logo, contact details, colors, and branding remain configurable.
