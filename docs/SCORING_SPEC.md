# Scoring Specification

Each question option has zero or more weighted contributions to programs. For example, an option may give DKV 60, MPLB 20, and PM 20. The model iterates configured programs and option weights; it never assumes three programs.

For a completed attempt, sum selected-option weights by program to form raw scores. Normalize each program as its raw score divided by the sum of all raw scores, expressed as a percentage; rounding may mean totals are approximately 100%. Define and document a deterministic tie-break rule before G6 implementation (TODO). Store the dominant program, raw score, and normalized score as immutable result snapshots associated with the quiz version.

Question and answer randomization must not create any persistent position-to-program relationship. Visual presentation remains neutral until the result reveal.
