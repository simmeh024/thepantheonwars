-- Quiz Control: admin-managed questions and answer options. The public quiz
-- keeps its current built-in questions until at least one active managed
-- question exists, so this migration is safe to run before content is added.

CREATE TABLE IF NOT EXISTS quiz_questions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question_text VARCHAR(500) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_quiz_questions_active_order (is_active, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_options (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question_id INT UNSIGNED NOT NULL,
  score_index TINYINT UNSIGNED NOT NULL,
  option_text VARCHAR(1000) NOT NULL,
  UNIQUE KEY uq_quiz_option_score (question_id, score_index),
  CONSTRAINT fk_quiz_options_question FOREIGN KEY (question_id) REFERENCES quiz_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (`key`, label, category) VALUES
  ('quiz.view', 'View Quiz Control', 'Lore Management'),
  ('quiz.edit', 'Create and edit quiz questions', 'Lore Management'),
  ('quiz.delete', 'Delete quiz questions', 'Lore Management')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);
