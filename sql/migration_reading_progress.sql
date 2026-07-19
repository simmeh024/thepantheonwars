-- Personal reading progress and the public "Currently Reading" profile detail.
-- Run once in phpMyAdmin after deploying the matching API and public pages.
-- A member can store a status for every book; the application keeps at most
-- one row in the `reading` state, which is what becomes publicly visible.
CREATE TABLE IF NOT EXISTS user_book_progress (
  user_id INT UNSIGNED NOT NULL,
  book_id INT NOT NULL,
  status ENUM('not_started','reading','finished') NOT NULL DEFAULT 'not_started',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, book_id),
  KEY idx_user_book_progress_current (user_id, status, updated_at),
  CONSTRAINT fk_user_book_progress_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_book_progress_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
