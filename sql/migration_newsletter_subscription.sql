-- migration_newsletter_subscription.sql
-- Adds mailing-list subscription as a real account attribute rather than the
-- previous purely client-side, fake newsletter form (js/main.js's
-- newsletter-form handler showed a confirmation and sent the email nowhere).
-- The public "Subscribe" form on every page now sends visitors to Create
-- Account instead, since actual subscription is now a member-account
-- attribute. DEFAULT 1 means every existing account is treated as already
-- subscribed (registration was implicitly the closest thing to consent this
-- site had), and every registration path (password/Google/admin-created)
-- omits this column from its INSERT, so new accounts get the same default
-- without any code change.

ALTER TABLE users
  ADD COLUMN newsletter_subscribed TINYINT(1) NOT NULL DEFAULT 1 AFTER email_verified_at;
