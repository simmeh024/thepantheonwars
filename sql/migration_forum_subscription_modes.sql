-- Per-topic notification cadence. Run once in phpMyAdmin against the
-- pantheonwars database after deploying the matching code.
-- Existing watch subscriptions remain instant, preserving current behavior.
ALTER TABLE topic_subscriptions
  ADD COLUMN IF NOT EXISTS delivery_mode ENUM('instant','daily','mentions') NOT NULL DEFAULT 'instant' AFTER topic_id;
