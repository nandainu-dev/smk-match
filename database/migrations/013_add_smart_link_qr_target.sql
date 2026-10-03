ALTER TABLE smart_links
    ADD COLUMN qr_target_url VARCHAR(2048) NULL AFTER source;
