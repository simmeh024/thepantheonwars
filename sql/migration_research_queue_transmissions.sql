-- Research queue and activation transmissions
-- Run once in phpMyAdmin against pantheonwars after deploying the matching
-- application files. The queue stores one player-selected next protocol;
-- transmissions are snapshots so an author edit never rewrites a command's log.

ALTER TABLE game_research_nodes
  ADD COLUMN activation_transmission TEXT NULL AFTER description;

CREATE TABLE IF NOT EXISTS game_player_research_queue (
  user_id INT UNSIGNED NOT NULL,
  research_node_id INT UNSIGNED NOT NULL,
  queued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  KEY idx_game_player_research_queue_node (research_node_id),
  CONSTRAINT fk_game_player_research_queue_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_game_player_research_queue_node FOREIGN KEY (research_node_id) REFERENCES game_research_nodes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_player_research_transmissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  research_node_id INT UNSIGNED NOT NULL,
  protocol_name VARCHAR(120) NOT NULL,
  transmission_text TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_player_research_transmission (user_id, research_node_id),
  KEY idx_game_player_research_transmissions_recent (user_id, created_at),
  CONSTRAINT fk_game_player_research_transmission_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_game_player_research_transmission_node FOREIGN KEY (research_node_id) REFERENCES game_research_nodes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
