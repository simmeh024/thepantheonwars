-- Overlord page enhancements (accent theming, resonance, decree panel).
-- Run once in phpMyAdmin against pantheonwars after deploying.
-- accent_color/accent_glow already existed and needed no migration; the only
-- new storage is an optional admin-authored list of short "decree" lines,
-- one per line, that api/overlords.php rotates through deterministically by
-- date. Leave blank and the public page falls back to a generated line.
ALTER TABLE overlords ADD COLUMN decrees TEXT NULL AFTER quote_cite;
