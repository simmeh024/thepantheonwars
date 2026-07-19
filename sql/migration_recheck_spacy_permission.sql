-- Permission for the System Status spaCy recheck action. Run once in
-- phpMyAdmin after deploying the matching API and Admin Console files.
-- Admins are superusers; this is deliberately opt-in for every other role.
INSERT INTO permissions (`key`, label, category) VALUES
  ('dashboards.recheck_spacy', 'Recheck the spaCy script', 'Dashboards')
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category);
