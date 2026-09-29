CREATE TABLE campaign_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    batch_number INT UNSIGNED NOT NULL,
    quiz_version_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(255) NULL,
    status VARCHAR(30) NOT NULL,
    active_marker TINYINT(1) NULL,
    started_at DATETIME NOT NULL,
    closed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY campaign_batches_campaign_number_unique (campaign_id,batch_number),
    UNIQUE KEY campaign_batches_campaign_active_marker_unique (campaign_id,active_marker),
    KEY campaign_batches_quiz_version (quiz_version_id),
    CONSTRAINT campaign_batches_campaign_fk FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE RESTRICT,
    CONSTRAINT campaign_batches_quiz_version_fk FOREIGN KEY (quiz_version_id) REFERENCES quiz_versions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
