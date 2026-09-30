ALTER TABLE result_scores MODIFY COLUMN raw_score DOUBLE NOT NULL;
ALTER TABLE result_scores MODIFY COLUMN normalized_percentage DOUBLE NOT NULL;
ALTER TABLE results ADD COLUMN total_raw_score DOUBLE NULL AFTER attempt_id;
UPDATE results SET total_raw_score = COALESCE((SELECT SUM(raw_score) FROM result_scores WHERE result_scores.result_id = results.id), 0);
ALTER TABLE results MODIFY COLUMN total_raw_score DOUBLE NOT NULL;
ALTER TABLE results ADD COLUMN is_tie TINYINT(1) NULL AFTER dominant_program_id;
UPDATE results SET is_tie = 0 WHERE is_tie IS NULL;
ALTER TABLE results MODIFY COLUMN is_tie TINYINT(1) NOT NULL;
CREATE TABLE result_tied_programs (result_id BIGINT UNSIGNED NOT NULL, program_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY (result_id,program_id), KEY result_tied_programs_program (program_id), CONSTRAINT result_tied_programs_result_fk FOREIGN KEY (result_id) REFERENCES results(id) ON DELETE RESTRICT, CONSTRAINT result_tied_programs_program_fk FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
