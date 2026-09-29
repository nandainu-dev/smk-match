# Scoring Specification

Each question option has zero or more weighted contributions to programs. For example, an option may give DKV 60, MPLB 20, and PM 20. The model iterates configured programs and option weights; it never assumes three programs.

For a completed attempt, sum selected-option weights by program to form raw scores. Normalize each program as its raw score divided by the sum of all raw scores, expressed as a percentage; rounding may mean totals are approximately 100%. Exact highest-score ties have no arbitrary winner and are represented according to the G6 scoring contract below. Store the dominant program, raw score, and normalized score as immutable result snapshots associated with the quiz version.

Question and answer randomization must not create any persistent position-to-program relationship. Visual presentation remains neutral until the result reveal.

## G6 scoring contract

Input is a list of `question_id`/`option_id` rows. A final submission must select exactly one option for every question in its supplied `QuizDefinition`; reject empty submissions, unknown questions/options, options from another question, duplicate answers, and unanswered questions with `InvalidArgumentException`.

Initialize every configured program at raw score zero. Add every relational option-weight row for each selected option. Include zero-score programs; never use answer position or fixed program fields. The supplied quiz definition—not current active programs—defines historical scoring membership.

For a positive total raw score, percentage is `(raw / total) * 100`, rounded independently to two decimals using deterministic half-up rounding. Do not redistribute remainders or force rounded values to exactly 100. A zero or non-scorable total raises `DomainException`.

Determine dominance from unrounded raw scores. One highest score yields that program and `is_tie=false`. Equal highest scores yield `dominant_program=null`, `is_tie=true`, and all tied codes sorted ascending; code order is not a winner selection. Rank output by raw score descending then code ascending.

Return total raw score, raw scores, percentages, ranking rows, nullable dominant program, tie flag, and tied program codes. Persisted results remain linked to the originating quiz version. Existing `results.dominant_program_id` is nullable, so it supports no dominant program; tie membership needs a future additive persistence design if it must be stored explicitly.
