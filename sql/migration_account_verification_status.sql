-- Account email-verification status
-- Run once in phpMyAdmin after deploying the Members verification column.

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL AFTER email;

-- Google only returns profiles with email_verified=true (enforced in
-- api/oauth.php). Backfill existing Google identities only when the provider
-- email is the same as the account email; a linked, different Google address
-- must not verify the member's primary address.
UPDATE users u
INNER JOIN oauth_identities oi
  ON oi.user_id = u.id
 AND oi.provider = 'google'
 AND oi.provider_email = u.email
SET u.email_verified_at = COALESCE(u.email_verified_at, UTC_TIMESTAMP());
