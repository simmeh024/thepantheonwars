-- Quiz enhancements: server-side scoring, weighted answers, admin-managed
-- Overlord result copy, and per-question answer capture for analytics.
--
-- Run once in phpMyAdmin after deploying the accompanying code. Every
-- statement is idempotent (IF NOT EXISTS / IF EXISTS / INSERT IGNORE /
-- blank-only UPDATE), so a partial or repeated run is safe.
--
-- Deploy order is NOT load-bearing: pw_quiz_capabilities() in
-- api/quiz/quiz-helpers.php detects each piece and falls back to the previous
-- behaviour until this has been run.

-- ---------------------------------------------------------------------------
-- 1. Answer options are no longer locked to exactly one per Overlord.
--
-- uq_quiz_option_score forced every question to carry exactly six answers, one
-- written in each Overlord's voice. Dropping it allows three- or four-option
-- questions; sort_order takes over as the display order that score_index used
-- to imply. score_index becomes nullable and is kept only so an unmigrated
-- reader (and the weight backfill below) can still resolve legacy rows.
-- ---------------------------------------------------------------------------

ALTER TABLE quiz_options
  ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0,
  MODIFY COLUMN score_index TINYINT UNSIGNED NULL;

ALTER TABLE quiz_options DROP INDEX IF EXISTS uq_quiz_option_score;
ALTER TABLE quiz_options ADD KEY IF NOT EXISTS idx_quiz_options_question (question_id, sort_order, id);

-- Legacy rows were ordered by score_index; preserve that as the new sort_order.
UPDATE quiz_options SET sort_order = score_index WHERE sort_order = 0 AND score_index IS NOT NULL;

-- ---------------------------------------------------------------------------
-- 2. Weighted answers. One option may now resonate with several Overlords at
-- different strengths, so a blended answer produces a blended result instead
-- of the flat one-point-per-question model.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS quiz_option_weights (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  option_id INT UNSIGNED NOT NULL,
  score_index TINYINT UNSIGNED NOT NULL,
  weight TINYINT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_quiz_option_weight (option_id, score_index),
  CONSTRAINT fk_quiz_option_weights_option FOREIGN KEY (option_id) REFERENCES quiz_options(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. Per-question answer capture. quiz_results only ever stored the six final
-- totals, so nothing could report which option readers actually chose -- the
-- data needed to spot a question where everyone picks the same answer and
-- which therefore carries no signal.
--
-- No foreign key on question_id: quiz_options already cascades from
-- quiz_questions, so an option row disappearing takes its answers with it.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS quiz_result_answers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  result_id INT UNSIGNED NOT NULL,
  question_id INT UNSIGNED NOT NULL,
  option_id INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_quiz_result_answer (result_id, question_id),
  KEY idx_quiz_result_answers_question (question_id, option_id),
  CONSTRAINT fk_quiz_result_answers_result FOREIGN KEY (result_id) REFERENCES quiz_results(id) ON DELETE CASCADE,
  CONSTRAINT fk_quiz_result_answers_option FOREIGN KEY (option_id) REFERENCES quiz_options(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. The quiz result screen's Overlord copy moves into Overlord Control.
--
-- quiz.html hardcoded each Overlord's name, epithet, portrait and result blurb,
-- so editing an Overlord in the admin console silently did nothing to the quiz.
-- Everything except the blurb already exists on this table; the blurb is its
-- own column because card_teaser is roster-card copy and reads differently.
--
-- Seeded only where blank, so re-running never overwrites an edited blurb.
-- ---------------------------------------------------------------------------

ALTER TABLE overlords ADD COLUMN IF NOT EXISTS quiz_result_blurb VARCHAR(400) NOT NULL DEFAULT '';

UPDATE overlords SET quiz_result_blurb = 'You rule through knowledge no one else has. People fear what you might already know about them — and they''re right to.'
  WHERE slug = 'syn-dravus' AND quiz_result_blurb = '';
UPDATE overlords SET quiz_result_blurb = 'You rule through control, seized and never loosened. Order is the only mercy you believe in.'
  WHERE slug = 'malric-thorne' AND quiz_result_blurb = '';
UPDATE overlords SET quiz_result_blurb = 'You rule through efficiency, even when the numbers are grim. The system survives — that''s the point.'
  WHERE slug = 'korrus-vale' AND quiz_result_blurb = '';
UPDATE overlords SET quiz_result_blurb = 'You rule through care that looks like softness and isn''t. Everyone feels safe near you. Not everyone should.'
  WHERE slug = 'lysara-venthe' AND quiz_result_blurb = '';
UPDATE overlords SET quiz_result_blurb = 'You rule through patience, letting things take the shape they need to. Nothing near you stays the same for long.'
  WHERE slug = 'zura-kaleth' AND quiz_result_blurb = '';
UPDATE overlords SET quiz_result_blurb = 'You rule through honor and reputation, staged as carefully as any duel. Your word is the one currency you never let devalue.'
  WHERE slug = 'maerion-thal' AND quiz_result_blurb = '';

-- ---------------------------------------------------------------------------
-- 5. Seed the twenty built-in questions into Quiz Control.
--
-- The quiz kept its questions in quiz.html, which meant the server had no
-- record of them and could not score an answer set. Seeding makes the database
-- the single source of truth so scoring can move server-side unconditionally.
--
-- Only runs when quiz_questions is empty, so an installation that already has
-- managed questions is left completely alone.
-- ---------------------------------------------------------------------------

SET @pw_seed_quiz = (SELECT IF(COUNT(*) = 0, 1, 0) FROM quiz_questions);

SET @qtext = 'Someone crosses you badly. What''s your first move?';
INSERT INTO quiz_questions (question_text, sort_order, is_active)
  SELECT @qtext, 1, 1 FROM DUAL WHERE @pw_seed_quiz = 1;
SET @qid = LAST_INSERT_ID();
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 0, 'I find out everything about them before I decide anything.', 0 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 1, 'I make an example of them, publicly, so no one else tries.', 1 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 2, 'I calculate whether removing them is worth the resources it costs.', 2 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 3, 'I let them think they got away with it — for now.', 3 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 4, 'I let them come closer. Closer things are easier to prune.', 4 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 5, 'I challenge them properly — where everyone can watch me be right.', 5 FROM DUAL WHERE @pw_seed_quiz = 1;

SET @qtext = 'What''s the most valuable currency in your world?';
INSERT INTO quiz_questions (question_text, sort_order, is_active)
  SELECT @qtext, 2, 1 FROM DUAL WHERE @pw_seed_quiz = 1;
SET @qid = LAST_INSERT_ID();
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 0, 'Information. Everything else is downstream of it.', 0 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 1, 'Order. Chaos is the only real enemy.', 1 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 2, 'Efficiency. Waste is the only sin.', 2 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 3, 'Trust. Even the false kind, if it''s convincing.', 3 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 4, 'Patience. Everything grows if you wait long enough.', 4 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 5, 'Reputation. A broken word costs more than any treaty ever could.', 5 FROM DUAL WHERE @pw_seed_quiz = 1;

SET @qtext = 'How do you feel about being loved by your people?';
INSERT INTO quiz_questions (question_text, sort_order, is_active)
  SELECT @qtext, 3, 1 FROM DUAL WHERE @pw_seed_quiz = 1;
SET @qid = LAST_INSERT_ID();
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 0, 'Irrelevant. I only need them to believe I already know their secrets.', 0 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 1, 'Unnecessary. Feared is more durable than loved.', 1 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 2, 'A statistic. Loyalty is measured, not felt.', 2 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 3, 'Essential. I need them to call me “mother” and mean it.', 3 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 4, 'Complicated. Love and fear grow from the same root here.', 4 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 5, 'Preferred, actually. A court that adores you forgives almost anything.', 5 FROM DUAL WHERE @pw_seed_quiz = 1;

SET @qtext = 'A rival Overlord insults you at the Nexus Veil. You:';
INSERT INTO quiz_questions (question_text, sort_order, is_active)
  SELECT @qtext, 4, 1 FROM DUAL WHERE @pw_seed_quiz = 1;
SET @qid = LAST_INSERT_ID();
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 0, 'Say nothing — and mention something private of theirs, just once.', 0 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 1, 'Remind the room, calmly, who annexed their last neighbor.', 1 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 2, 'Note the insult in a ledger. It''ll matter eventually.', 2 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 3, 'Smile. Let them think they won this round.', 3 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 4, 'Compliment them. Let them wonder why that feels like a threat.', 4 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 5, 'Challenge them to something with rules, in front of witnesses, and win politely.', 5 FROM DUAL WHERE @pw_seed_quiz = 1;

SET @qtext = 'What''s your biggest weakness, honestly?';
INSERT INTO quiz_questions (question_text, sort_order, is_active)
  SELECT @qtext, 5, 1 FROM DUAL WHERE @pw_seed_quiz = 1;
SET @qid = LAST_INSERT_ID();
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 0, 'I trust my own records more than I trust people.', 0 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 1, 'I mistake obedience for loyalty.', 1 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 2, 'I forget that people aren''t components.', 2 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 3, 'I care more than my reputation can afford.', 3 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 4, 'I''ve stopped being able to tell where I end and my world begins.', 4 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 5, 'I''ve started believing my own legend a little more than I should.', 5 FROM DUAL WHERE @pw_seed_quiz = 1;

SET @qtext = 'Pick a punishment for a low-level betrayal:';
INSERT INTO quiz_questions (question_text, sort_order, is_active)
  SELECT @qtext, 6, 1 FROM DUAL WHERE @pw_seed_quiz = 1;
SET @qid = LAST_INSERT_ID();
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 0, 'Erase their memory of ever being useful to me.', 0 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 1, 'Public demotion. Let their peers watch.', 1 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 2, 'Reassignment to a sector with worse survival odds.', 2 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 3, 'Forgiveness — with a debt attached that never quite clears.', 3 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 4, 'A slow one. Let it teach a lesson to everyone watching.', 4 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 5, 'A public duel they didn''t ask for and can''t refuse.', 5 FROM DUAL WHERE @pw_seed_quiz = 1;

SET @qtext = 'What does your throne room actually look like?';
INSERT INTO quiz_questions (question_text, sort_order, is_active)
  SELECT @qtext, 7, 1 FROM DUAL WHERE @pw_seed_quiz = 1;
SET @qid = LAST_INSERT_ID();
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 0, 'Dim, layered with screens no one else can read.', 0 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 1, 'Iron and red banners. Impossible to mistake for anything soft.', 1 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 2, 'Function over form — a control room, not a court.', 2 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 3, 'Warm, open, deceptively unguarded.', 3 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 4, 'Alive. The walls occasionally rearrange themselves.', 4 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 5, 'Marble, brass, and enough banners to make the point before I speak.', 5 FROM DUAL WHERE @pw_seed_quiz = 1;

SET @qtext = 'How do you make decisions under pressure?';
INSERT INTO quiz_questions (question_text, sort_order, is_active)
  SELECT @qtext, 8, 1 FROM DUAL WHERE @pw_seed_quiz = 1;
SET @qid = LAST_INSERT_ID();
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 0, 'I already decided three moves ago. I''m just watching it land.', 0 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 1, 'Fast, and I don''t revisit it.', 1 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 2, 'I run the numbers, even if the numbers are grim.', 2 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 3, 'I read the room first. The room usually tells me.', 3 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 4, 'I let it grow into an answer instead of forcing one.', 4 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 5, 'Like I already rehearsed it. Composure is half the victory.', 5 FROM DUAL WHERE @pw_seed_quiz = 1;

SET @qtext = 'What''s the one rule you never break?';
INSERT INTO quiz_questions (question_text, sort_order, is_active)
  SELECT @qtext, 9, 1 FROM DUAL WHERE @pw_seed_quiz = 1;
SET @qid = LAST_INSERT_ID();
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 0, 'Never let anyone know what you actually know.', 0 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 1, 'Never apologize where it can be seen.', 1 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 2, 'Never spend a resource you can''t justify losing.', 2 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 3, 'Never let them see the cost of what you protect them from.', 3 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 4, 'Never waste a threat you could grow instead.', 4 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 5, 'Never let your word be worth less than it was yesterday.', 5 FROM DUAL WHERE @pw_seed_quiz = 1;

SET @qtext = 'Someone begs you for mercy. What actually moves you?';
INSERT INTO quiz_questions (question_text, sort_order, is_active)
  SELECT @qtext, 10, 1 FROM DUAL WHERE @pw_seed_quiz = 1;
SET @qid = LAST_INSERT_ID();
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 0, 'A secret I didn''t already have.', 0 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 1, 'Nothing. Mercy is a resource I ration on my terms.', 1 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 2, 'A good argument that keeping them alive is more efficient.', 2 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 3, 'Genuine fear, not performed fear.', 3 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 4, 'The way they remind me of something I used to be.', 4 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 5, 'A good performance of honor, even if we both know it''s a performance.', 5 FROM DUAL WHERE @pw_seed_quiz = 1;

SET @qtext = 'What do you think of the Thirteenth Key myth?';
INSERT INTO quiz_questions (question_text, sort_order, is_active)
  SELECT @qtext, 11, 1 FROM DUAL WHERE @pw_seed_quiz = 1;
SET @qid = LAST_INSERT_ID();
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 0, 'It''s data nobody''s indexed correctly yet.', 0 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 1, 'A fairy tale rebels use to justify losing.', 1 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 2, 'An unaccounted variable. Which bothers me more than I''d admit.', 2 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 3, 'A story worth being afraid of, quietly.', 3 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 4, 'Something that''s already growing, whether the Pantheon likes it or not.', 4 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 5, 'A legend without proper witnesses. I don''t trust stories no one signed.', 5 FROM DUAL WHERE @pw_seed_quiz = 1;

SET @qtext = 'How do you handle your own mistakes?';
INSERT INTO quiz_questions (question_text, sort_order, is_active)
  SELECT @qtext, 12, 1 FROM DUAL WHERE @pw_seed_quiz = 1;
SET @qid = LAST_INSERT_ID();
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 0, 'I rewrite the narrative before anyone else gets to.', 0 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 1, 'I don''t have mistakes. I have decisions others misunderstood.', 1 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 2, 'I audit it, fix the process, move on without ceremony.', 2 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 3, 'I absorb the blame so it doesn''t reach the people I protect.', 3 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 4, 'I let it rot until it becomes fertilizer for something better.', 4 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 5, 'I turn it into a story where I still come out looking honorable.', 5 FROM DUAL WHERE @pw_seed_quiz = 1;

SET @qtext = 'What''s your relationship with the other eleven Overlords?';
INSERT INTO quiz_questions (question_text, sort_order, is_active)
  SELECT @qtext, 13, 1 FROM DUAL WHERE @pw_seed_quiz = 1;
SET @qid = LAST_INSERT_ID();
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 0, 'Useful sources. Nothing more.', 0 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 1, 'Rivals I''m still deciding whether to outlast or outgun.', 1 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 2, 'Variables in a system I''d rather simplify.', 2 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 3, 'Careful. Everyone at that table wants something from me.', 3 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 4, 'Distant. Roots don''t need to touch to be connected.', 4 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 5, 'Cordial rivals. We trade treaties the way others trade threats.', 5 FROM DUAL WHERE @pw_seed_quiz = 1;

SET @qtext = 'Pick the compliment that actually lands:';
INSERT INTO quiz_questions (question_text, sort_order, is_active)
  SELECT @qtext, 14, 1 FROM DUAL WHERE @pw_seed_quiz = 1;
SET @qid = LAST_INSERT_ID();
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 0, '“No one sees you coming.”', 0 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 1, '“No one questions you twice.”', 1 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 2, '“Nothing you run is wasted.”', 2 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 3, '“Everyone who meets you feels safe — even when they shouldn''t.”', 3 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 4, '“Nothing near you stays the same shape for long.”', 4 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 5, '“No one''s ever caught you losing.”', 5 FROM DUAL WHERE @pw_seed_quiz = 1;

SET @qtext = 'What''s a luxury you allow yourself?';
INSERT INTO quiz_questions (question_text, sort_order, is_active)
  SELECT @qtext, 15, 1 FROM DUAL WHERE @pw_seed_quiz = 1;
SET @qid = LAST_INSERT_ID();
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 0, 'Knowing things before anyone else in the room does.', 0 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 1, 'Silence, immediately, whenever I want it.', 1 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 2, 'A win column that''s always, technically, positive.', 2 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 3, 'Being underestimated. It''s useful and it''s restful.', 3 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 4, 'Watching something grow that I planted myself.', 4 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 5, 'A duel every so often, just to remind everyone — including me — that I still can.', 5 FROM DUAL WHERE @pw_seed_quiz = 1;

SET @qtext = 'How do you view the god-core bound to you?';
INSERT INTO quiz_questions (question_text, sort_order, is_active)
  SELECT @qtext, 16, 1 FROM DUAL WHERE @pw_seed_quiz = 1;
SET @qid = LAST_INSERT_ID();
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 0, 'A library that occasionally talks back.', 0 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 1, 'A weapon I inherited and refuse to be afraid of.', 1 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 2, 'A machine I maintain, not a god I worship.', 2 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 3, 'Something I protect my people from more than I let on.', 3 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 4, 'Something we''re slowly becoming the same thing as.', 4 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 5, 'A crown jewel I display more than I explain.', 5 FROM DUAL WHERE @pw_seed_quiz = 1;

SET @qtext = 'Your world is dying slowly and no one knows yet. What do you do?';
INSERT INTO quiz_questions (question_text, sort_order, is_active)
  SELECT @qtext, 17, 1 FROM DUAL WHERE @pw_seed_quiz = 1;
SET @qid = LAST_INSERT_ID();
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 0, 'Quietly move the pieces that matter before the truth leaks.', 0 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 1, 'Double down on control before anyone can exploit the panic.', 1 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 2, 'Recalculate the timeline and start rationing what''s left.', 2 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 3, 'Shoulder it alone so the fear doesn''t spread.', 3 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 4, 'Let it die where it must, and plant something new in the wreckage.', 4 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 5, 'Stage a triumph loud enough that no one thinks to look underneath it.', 5 FROM DUAL WHERE @pw_seed_quiz = 1;

SET @qtext = 'What''s your idea of a perfect ally?';
INSERT INTO quiz_questions (question_text, sort_order, is_active)
  SELECT @qtext, 18, 1 FROM DUAL WHERE @pw_seed_quiz = 1;
SET @qid = LAST_INSERT_ID();
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 0, 'Someone who tells me the truth and nothing they shouldn''t.', 0 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 1, 'Someone who follows the order before they finish hearing it.', 1 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 2, 'Someone who doesn''t need to be managed to be useful.', 2 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 3, 'Someone who lets me protect them without asking why.', 3 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 4, 'Someone patient enough to grow alongside me.', 4 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 5, 'Someone whose word is as good as mine — which is a shorter list than you''d think.', 5 FROM DUAL WHERE @pw_seed_quiz = 1;

SET @qtext = 'How do outsiders usually describe you, behind your back?';
INSERT INTO quiz_questions (question_text, sort_order, is_active)
  SELECT @qtext, 19, 1 FROM DUAL WHERE @pw_seed_quiz = 1;
SET @qid = LAST_INSERT_ID();
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 0, '“You never know what she already knows.”', 0 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 1, '“He built a throne out of everyone who doubted him.”', 1 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 2, '“Cold. Efficient. Occasionally right, which is worse.”', 2 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 3, '“Everyone loves her. That''s what worries me.”', 3 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 4, '“Get too close and you start to wonder if you were ever really free.”', 4 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 5, '“Charming, until you remember he''s never actually lost anything.”', 5 FROM DUAL WHERE @pw_seed_quiz = 1;

SET @qtext = 'If your reign ended tomorrow, what would you want people to remember?';
INSERT INTO quiz_questions (question_text, sort_order, is_active)
  SELECT @qtext, 20, 1 FROM DUAL WHERE @pw_seed_quiz = 1;
SET @qid = LAST_INSERT_ID();
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 0, 'That I always knew more than I let on — and used it well.', 0 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 1, 'That order held because I was the one holding it.', 1 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 2, 'That the system I built outlasted the mess it replaced.', 2 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 3, 'That I kept them safer than they ever realized.', 3 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 4, 'That something of me kept growing long after I was gone.', 4 FROM DUAL WHERE @pw_seed_quiz = 1;
INSERT INTO quiz_options (question_id, score_index, option_text, sort_order)
  SELECT @qid, 5, 'That my word held, every time, in a Pantheon where nobody else''s did.', 5 FROM DUAL WHERE @pw_seed_quiz = 1;

-- ---------------------------------------------------------------------------
-- 6. Backfill weights. Every option carrying a legacy score_index -- both
-- pre-existing rows and anything seeded above -- becomes a single weight-1
-- vote for that Overlord, which reproduces the old scoring exactly.
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO quiz_option_weights (option_id, score_index, weight)
  SELECT id, score_index, 1 FROM quiz_options WHERE score_index IS NOT NULL;
