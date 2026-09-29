# Quiz Versioning Specification

## Identity and snapshot

A **quiz identity** is the logical quiz across time. It may have many quiz
versions. A **quiz version** is one content snapshot belonging to exactly one
quiz identity.

```text
Quiz
|-- Version 1
|-- Version 2
`-- Version 3
```

Attempts and results must remain associated with the exact quiz version that
produced them. Historical resolution must start with the stored
`quiz_version` relationship, never with a latest version, current quiz name,
current campaign, or other indirect lookup.

## Version numbering

- The first version number is `1`.
- A version number is a positive integer and is unique within its quiz
  identity.
- The next number is `MAX(existing version_number for the quiz) + 1`.
- Gaps are allowed and are never filled or reused. For example, existing
  versions `1`, `2`, and `4` produce next version `5`.
- Discarded versions remain persisted, so their allocated numbers are included
  by `MAX()`. For example, version `1` published and version `2` discarded
  produce next version `3`.
- Normal application behavior does not allow manual version-number assignment.
  The versioning service/repository determines the next number.
- Persistence must retain uniqueness for quiz identity plus version number.

## Lifecycle and immutability

The G7 lifecycle has three states: `DRAFT`, `PUBLISHED`, and `DISCARDED`.

An unused draft may be edited in place only when it has never been published
and has never been used by a persisted attempt or result. This avoids creating
a new version for every draft edit.

Published content is immutable. A published version must not become editable
by changing its status back to draft. Independently of status or UI behavior,
a version referenced by a persisted attempt or result is immutable. This is a
safety invariant.

The normal publish transition is `DRAFT -> PUBLISHED`. `PUBLISHED -> DRAFT`
and `PUBLISHED -> DISCARDED` are not allowed in G7 normal behavior.

An unused draft may transition through `DRAFT -> DISCARDED` only when it has
never been published and has no persisted participant attempt or result usage.
`DISCARDED` is terminal: `DISCARDED -> DRAFT` and
`DISCARDED -> PUBLISHED` are not allowed. A discarded version is retained as a
tombstone/history record, is not editable, publishable, playable, or used for
normal participant attempts, and is not counted as the one editable draft.
Normal versioning behavior does not physically hard-delete a discarded
quiz-version record.

A published version cannot be discarded. Any version referenced by a
persisted attempt or result cannot be discarded or mutated regardless of
status or UI mistakes.

Editing published or historical content creates a new draft instead:

1. Select an explicit source version.
2. Copy its complete version snapshot.
3. Allocate `MAX(version_number) + 1` for that quiz identity.
4. Create a new `DRAFT` version.
5. Apply edits only to the new draft.

The source need not be the numerically latest version. If versions `1`, `2`,
and `3` exist, cloning version `1` produces version `4`.

There may be at most one editable draft per quiz identity. Published and
discarded versions do not count as editable drafts. If an editable draft
already exists, normal editing continues on it; attempting to create another
editable draft is rejected by domain/service behavior. The implementation and
schema gates decide the appropriate enforcement layer.

## Immutable snapshot boundary

One version includes all content that affects participant-visible quiz content
or scoring:

- quiz name/title snapshot;
- questions, their stable version-local IDs, order, and text;
- options, their stable version-local IDs, order, and text; and
- relational program-weight rows.

The cloned snapshot is independent: changing version `N + 1` must never
mutate version `N`.

At G7, name/title is the only required quiz-level field beyond this
question/option/weight snapshot. Future fields that change participant-visible
content or scoring must be evaluated for inclusion in a version snapshot.
Administrative metadata that does not affect historical participant experience
or scoring may remain quiz-level.

`quizzes.name` is mutable, while the current `quiz_versions` schema has no
historical name/title field. Historical rendering must not rely only on the
current quiz name. Therefore G7 requires an **additive migration** for a
version-level title/name snapshot. The exact column name belongs to the schema
gate. Migrations 001 and 002 remain immutable.

## Program references

G7 does not snapshot mutable program branding metadata. Display name,
description, colors, mascot, careers, and current active/inactive state remain
outside the quiz-version snapshot unless a later requirement explicitly needs
historical branding reproduction.

Historical scoring requires stable referenced program identity and immutable
option-weight rows inside the quiz version. A program referenced by historical
versions must not be hard-deleted in a way that breaks those references;
deactivation is used instead. A currently inactive referenced program remains
valid for a historical `QuizDefinition` and historical G6 scoring.

No program-metadata snapshot migration is required for G7 scoring
reproducibility.

## Scoring and result relationship

G7 does not change G6 scoring semantics. Historical scoring continues through:

```php
ScoringEngine::score(QuizDefinition $quiz, array $answers)
```

G7 selects or builds the exact historical `QuizDefinition`; it does not change
raw scoring, percentage calculation, rounding, float equality, dominance, tie
behavior, or ranking.

Persisted participant attempts must use a `PUBLISHED` version. Draft versions
may support admin preview/testing only when that preview does not create normal
persisted participant attempts or results. Preview implementation is outside
G7.

## Active-version selection

G7 defines no global active quiz version and forbids implicit
`latest version = playable` behavior. Consumers must explicitly select a quiz
version identity. Campaign and Smart QR configuration, in the later campaign
gate, owns selection of the published version served to participants.

## Minimum G7 responsibility

The G7 domain layer must:

- represent a quiz-version snapshot;
- load an exact version deterministically;
- determine the next version number;
- create an independent draft from an explicitly selected source version;
- prevent mutation of published or used versions;
- discard an eligible unused draft as a retained tombstone;
- publish a valid draft;
- preserve historical definitions; and
- build/load a `QuizDefinition` for G6 scoring.

The proposed minimal implementation shape is:

- `app/Core/QuizVersion.php`
- `app/Core/QuizVersionRepository.php`
- `app/Core/QuizVersionService.php`
- `tests/versioning.php`

## Required future tests

The G7 implementation must prove:

- first version is `1`, next version is `MAX + 1`, gaps are not reused, and
  normal behavior cannot assign a version number manually;
- version numbers are unique per quiz identity;
- unused drafts can be edited, while published and used versions cannot;
- an unused draft can become discarded and remains persisted;
- a discarded version is neither editable nor publishable and does not count
  as an editable draft;
- published and used versions cannot be discarded;
- editing published content creates an independent new draft;
- cloning version `1` while version `3` exists creates version `4`;
- a clone includes title, questions, options, IDs, orders, and relational
  weights;
- one quiz has at most one editable draft, discarded draft numbers are not
  reused, and next version allocation remains `MAX + 1` after a discard;
- historical version lookup and attempt/result resolution are deterministic;
- inactive referenced programs remain scoreable historically;
- historical `QuizDefinition` scoring remains identical through locked G6;
- N-program weights are preserved; and
- no global latest-version-active assumption exists.

## Out of scope

G7 does not implement campaign selection, Smart QR, participant attempts,
admin UI, result UI, live monitor behavior, program-branding snapshots,
production deployment, or database writes.
