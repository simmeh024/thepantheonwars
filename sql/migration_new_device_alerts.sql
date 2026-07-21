-- New Device / Location Sign-in Alerts
-- Run once in phpMyAdmin against pantheonwars after deploying.
-- Detection itself needs no new table: user_sessions already records
-- browser_name/operating_system/country_code per session and is never
-- deleted (only revoked_at set), so it already is the historical record of
-- every fingerprint seen for a user.
ALTER TABLE notifications MODIFY type ENUM('like','mention','quote','report_resolved','world_available','news_published','topic_reply','icon_unlocked','direct_message','new_device_login') NOT NULL;
ALTER TABLE notification_preferences ADD COLUMN notif_new_device_login TINYINT(1) NOT NULL DEFAULT 1;
