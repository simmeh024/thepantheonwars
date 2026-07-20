-- Add Cealis Dorne to the Overlord roster as a sealed throne.
-- Run once through phpMyAdmin after deploying the associated carousel artwork.
-- This does not assign a world or expose a public profile; the public carousel
-- renders locked roster records in its non-interactive "Lore coming soon" area.

INSERT INTO overlords (
  slug,
  name,
  status,
  portrait_image_url,
  card_teaser,
  sort_order
) VALUES (
  'cealis-dorne',
  'Cealis Dorne',
  'locked',
  'images/overlord-cealis-throne.png',
  'The apparatus still hums beneath the sealed throne.',
  12
)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  status = 'locked',
  portrait_image_url = VALUES(portrait_image_url),
  card_teaser = VALUES(card_teaser);
