-- Adds an optional header/featured image to News posts, used by the
-- redesigned homepage Dispatches teaser (one large featured card + a
-- compact list of others) and available for the individual News post
-- editor. Existing posts simply have NULL here until an editor sets one;
-- nothing else changes for them.

ALTER TABLE news_posts
  ADD COLUMN header_image_url VARCHAR(255) NULL AFTER body;
