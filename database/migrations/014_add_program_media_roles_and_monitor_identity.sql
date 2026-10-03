ALTER TABLE programs
    ADD COLUMN result_image_path VARCHAR(500) NULL AFTER mascot_path,
    ADD COLUMN share_image_path VARCHAR(500) NULL AFTER result_image_path,
    ADD COLUMN monitor_image_path VARCHAR(500) NULL AFTER share_image_path;

ALTER TABLE quiz_version_program_presentations
    ADD COLUMN result_image_path_snapshot VARCHAR(500) NULL AFTER mascot_path_snapshot,
    ADD COLUMN share_image_path_snapshot VARCHAR(500) NULL AFTER result_image_path_snapshot,
    ADD COLUMN monitor_image_path_snapshot VARCHAR(500) NULL AFTER share_image_path_snapshot;

ALTER TABLE monitor_settings
    ADD COLUMN footer_logo_path VARCHAR(500) NULL AFTER is_active,
    ADD COLUMN footer_text TEXT NULL AFTER footer_logo_path;
