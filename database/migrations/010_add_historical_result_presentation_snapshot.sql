CREATE TABLE quiz_version_program_presentations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quiz_version_program_id BIGINT UNSIGNED NOT NULL,
    program_code_snapshot VARCHAR(100) NOT NULL,
    program_name_snapshot VARCHAR(255) NOT NULL,
    personality_title_snapshot VARCHAR(255) NULL,
    mascot_path_snapshot VARCHAR(500) NULL,
    primary_color_snapshot CHAR(7) NULL,
    accent_color_snapshot CHAR(7) NULL,
    tagline_snapshot TEXT NULL,
    description_snapshot TEXT NULL,
    superpower_snapshot TEXT NULL,
    skills_snapshot LONGTEXT NULL,
    careers_snapshot LONGTEXT NULL,
    snapshot_provenance VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY quiz_version_program_presentations_membership_unique (quiz_version_program_id),
    CONSTRAINT quiz_version_program_presentations_membership_fk FOREIGN KEY (quiz_version_program_id) REFERENCES quiz_version_programs(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO quiz_version_program_presentations (
    quiz_version_program_id,
    program_code_snapshot,
    program_name_snapshot,
    personality_title_snapshot,
    mascot_path_snapshot,
    primary_color_snapshot,
    accent_color_snapshot,
    tagline_snapshot,
    description_snapshot,
    superpower_snapshot,
    skills_snapshot,
    careers_snapshot,
    snapshot_provenance,
    created_at,
    updated_at
)
SELECT
    qvp.id,
    p.short_name,
    p.name,
    p.personality_title,
    p.mascot_path,
    NULL,
    NULL,
    NULL,
    p.description,
    NULL,
    p.skills_json,
    NULL,
    'legacy_current_catalog',
    UTC_TIMESTAMP(),
    UTC_TIMESTAMP()
FROM quiz_version_programs AS qvp
INNER JOIN programs AS p ON p.id = qvp.program_id;

ALTER TABLE result_scores ADD COLUMN display_order INT UNSIGNED NULL AFTER normalized_percentage;

UPDATE result_scores AS target
INNER JOIN (
    SELECT
        current_score.id,
        COUNT(preceding_score.id) AS legacy_display_order
    FROM result_scores AS current_score
    INNER JOIN result_scores AS preceding_score
        ON preceding_score.result_id = current_score.result_id
        AND preceding_score.program_id <= current_score.program_id
    GROUP BY current_score.id
) AS legacy_order ON legacy_order.id = target.id
SET target.display_order = legacy_order.legacy_display_order;
