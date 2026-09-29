ALTER TABLE quiz_versions ADD COLUMN name VARCHAR(255) NULL;
UPDATE quiz_versions AS qv INNER JOIN quizzes AS q ON q.id = qv.quiz_id SET qv.name = q.name WHERE qv.name IS NULL;
ALTER TABLE quiz_versions MODIFY COLUMN name VARCHAR(255) NOT NULL;
