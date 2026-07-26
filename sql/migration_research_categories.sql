-- Research Facility categories
-- Run once in phpMyAdmin against pantheonwars after deploying the matching
-- application files. Categories organise the public research lattice; deleting
-- one later leaves its protocols available as uncategorised nodes.

CREATE TABLE game_research_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_research_category_slug (slug),
  KEY idx_game_research_categories_sort (sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE game_research_nodes
  ADD COLUMN research_category_id INT UNSIGNED NULL AFTER image_url,
  ADD KEY idx_game_research_nodes_category (research_category_id, sort_order),
  ADD CONSTRAINT fk_game_research_node_category
    FOREIGN KEY (research_category_id) REFERENCES game_research_categories(id) ON DELETE SET NULL;
