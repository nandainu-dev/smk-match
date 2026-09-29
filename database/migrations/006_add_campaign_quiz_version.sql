ALTER TABLE campaigns
    ADD COLUMN quiz_version_id BIGINT UNSIGNED NULL,
    ADD CONSTRAINT campaigns_quiz_version_fk
        FOREIGN KEY (quiz_version_id) REFERENCES quiz_versions(id) ON DELETE RESTRICT;
