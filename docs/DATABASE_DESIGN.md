# Proposed Relational Model

This is a proposed relational model only; no executable migrations exist in G0.

`schools` owns branding and configuration; `admins` belongs to schools. `programs` belongs to schools and has `program_media` and `program_careers`. `quizzes` is the editable conceptual quiz; each publish creates immutable `quiz_versions`. `questions` and `question_options` belong to a version (or versioned snapshot); `option_weights` maps an option to any number of `programs` with a numeric weight.

`campaigns` belong to schools. `smart_links` hold permanent aliases, destinations, source, activity state, QR metadata, and scan tracking. `participants` record identity, separate consent state/timestamp, campaign/source provenance, and timestamps. `attempts` use visitor UUID and unique attempt UUID, reference participant/campaign/quiz version, and record idempotent submission state. `responses` preserve selected options for an attempt.

`results` records an immutable attempt outcome and dominant program. `result_scores` records one row per result/program containing raw score and normalized percentage; it is the N-program score model, replacing fixed program-score columns. `campaign_stats` stores aggregation suitable for polling. `monitor_settings` configures monitor behavior. `settings` stores scoped configurable values.

Key integrity requirements: preserve quiz-version references; never recompute historical results silently; normalize result percentages to approximately 100%; use foreign keys, unique aliases, unique attempt UUIDs, and indexes for polling/analytics queries. Detailed field types, retention, indexes, and migration syntax remain TODO for G2.
