ALTER TABLE result_scores
    MODIFY COLUMN display_order INT UNSIGNED NOT NULL,
    ADD CONSTRAINT result_scores_display_order_positive
        CHECK (display_order >= 1),
    ADD UNIQUE KEY result_scores_result_display_order_unique
        (result_id, display_order);
