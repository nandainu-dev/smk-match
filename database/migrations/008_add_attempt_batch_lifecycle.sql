ALTER TABLE attempts ADD COLUMN campaign_batch_id BIGINT UNSIGNED NULL AFTER campaign_id;
ALTER TABLE attempts ADD COLUMN status VARCHAR(30) NULL AFTER source;
UPDATE attempts SET status = CASE WHEN submitted_at IS NULL THEN 'started' ELSE 'completed' END WHERE status IS NULL;
ALTER TABLE attempts MODIFY COLUMN status VARCHAR(30) NOT NULL;
ALTER TABLE attempts ADD KEY attempts_batch_status_submitted (campaign_batch_id,status,submitted_at), ADD KEY attempts_visitor_created (visitor_uuid,created_at), ADD CONSTRAINT attempts_campaign_batch_fk FOREIGN KEY (campaign_batch_id) REFERENCES campaign_batches(id) ON DELETE RESTRICT;
