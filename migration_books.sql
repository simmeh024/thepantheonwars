CREATE TABLE books (
  id INT AUTO_INCREMENT PRIMARY KEY,
  book_number INT NOT NULL,
  saga_phase TINYINT NOT NULL,
  writing_stage TINYINT NOT NULL DEFAULT 1,
  title VARCHAR(255) NOT NULL,
  status_label VARCHAR(100) NOT NULL,
  meta_text VARCHAR(500) DEFAULT NULL,
  description TEXT,
  cover_image_url VARCHAR(500) DEFAULT NULL,
  character_image_url VARCHAR(500) DEFAULT NULL,
  character_alt VARCHAR(255) DEFAULT NULL,
  preview_enabled TINYINT(1) NOT NULL DEFAULT 0,
  preview_eyebrow VARCHAR(255) DEFAULT NULL,
  preview_lede VARCHAR(500) DEFAULT NULL,
  preview_hero_image_url VARCHAR(500) DEFAULT NULL,
  preview_body TEXT,
  preview_quote TEXT,
  preview_quote_cite VARCHAR(255) DEFAULT NULL,
  buy_kobo_url VARCHAR(500) DEFAULT NULL,
  buy_amazon_url VARCHAR(500) DEFAULT NULL,
  buy_apple_url VARCHAR(500) DEFAULT NULL,
  buy_bn_url VARCHAR(500) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_book_number (book_number)
);

INSERT INTO books (book_number, saga_phase, writing_stage, title, status_label, meta_text, description, cover_image_url, character_image_url, character_alt, preview_enabled, preview_eyebrow, preview_lede, preview_hero_image_url, preview_body, preview_quote, preview_quote_cite, sort_order) VALUES
(1, 1, 5, 'The Mindweaver''s Lie', 'Book One', 'World: Neoh &nbsp;·&nbsp; Protagonist: Kael Veyr', 'A thief in Neoh''s rain-drowned undercity carries a shard that pulses like a second heartbeat, and memories that were never supposed to survive Syn Dravus''s purges. A heist on a vault no one has ever robbed cracks open the truth about the Pantheon — and a thirteenth world every record insists doesn''t exist.', 'images/covers/book-1.jpg', 'images/char-kael.jpg', 'Kael Veyr', 1, 'Book One &middot; Preview', 'A spoiler-free glimpse of Chapter One, before the heist that was never supposed to work.', 'images/world-neoh.jpg', 'Rain never really stopped falling in the undercity of Neoh. Not the real kind — that hadn''t touched the lower decks in decades — but runoff, filtered down through eleven levels of pipe and neon until it reached the gutters where Kael Veyr counted his own heartbeat against the drip.

Three days since he''d lifted the shard from a dead man''s coat. Three days since it had started answering when he thought at it — low, wordless, a hum against his ribs like something that recognized him.

He told himself it recognized the coat.

Above him, the Memory Vaults ran quiet and blue through the rain-static, their light bleeding down through the grates the way it always did — patient, unhurried, the color of something that had already decided how the night would end. Neoh didn''t need to chase you. It only had to remember you, and eventually, everyone made a mistake worth remembering.

Kael had made a career out of being the exception.

"You''re thinking too loud again," said Ress, from somewhere above him on the fire escape, not quite a joke. "Vault crews can practically hear you sweat."

"Then it''s a good thing," Kael said, "that we''re not robbing a vault crew."

The silence that followed was the kind Ress was good at — the kind that meant she already knew exactly which vault he meant, and had already decided, against every instinct that had kept her alive this long, to come anyway.

Somewhere beneath their feet, past nine decks of rusted stairwell and one very bribeable maintenance drone, sat a door that had never been opened by anyone who hadn''t been invited. Kael wasn''t invited. He had a shard humming in his coat pocket that seemed to think otherwise.

He didn''t know yet what the vault actually held. He didn''t know that Neoh''s official maps insisted there were only twelve worlds bound to the Pantheon, and that the shard in his pocket was about to make a very convincing argument that they were wrong.

He just knew it was raining, and that some doors, once you knocked on them, didn''t let you leave the same person who''d knocked.', 'Every vault remembers who it was built to keep out. This one was built for me.', 'Kael Veyr, The Mindweaver''s Lie', 1),
(2, 1, 2, 'The Rootbinder''s Game', 'Book Two', 'World: Babki Prime &nbsp;·&nbsp; Protagonist: Rakka', 'Rakka''s tribe worships the Still God — a frozen Pantheon corpse half-swallowed by jungle. When a shard buried in her own bone starts singing near a stranger''s magic, she learns the Still God might not be as dead as everyone needs it to be.', 'images/covers/book-2.jpg', 'images/char-rakka.jpg', 'Rakka', 0, NULL, NULL, NULL, NULL, NULL, NULL, 2),
(3, 1, 1, 'The Gilded Rebellion', 'Book Three', 'World: Geof V &nbsp;·&nbsp; Protagonist: Lady Ciri of House Veyl', 'Disinherited and done pretending to be harmless, Lady Ciri turns her father''s enemies against each other in a court where the wine is liquefied Aetherweave and noble children are bred with eyes built to see the gates between worlds.', 'images/covers/book-3.jpg', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 3),
(4, 1, 1, 'The Tidebreaker', 'Book Four', 'World: Asmecu &nbsp;·&nbsp; Overlord: Lysara Venthe', 'Beneath a palace floating on the eye of the Abyss, a queen wears a crown carved from her predecessor''s bones. When a stolen surge of power tears through Asmecu''s defenses, the tide breaks — and so does the illusion that this world was ever safe.', 'images/covers/book-4.jpg', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 4),
(5, 2, 1, 'The Chainspeaker''s Requiem', 'Book Five', 'World: Sed &nbsp;·&nbsp; Overlord: Krev Ashmane &nbsp;·&nbsp; Protagonist: Graal', 'In the mines of Sed, prisoners dig for screamstone — ore that records torture like memory. Graal has never spoken a word in his life. He doesn''t need to; the dead do enough talking for both of them.', 'images/covers/book-5.jpg', 'images/char-krev.jpg', 'Krev Ashmane', 0, NULL, NULL, NULL, NULL, NULL, NULL, 5),
(6, 2, 1, 'The Prophet''s Equation', 'Book Six', 'World: Beoctica &nbsp;·&nbsp; Protagonist: Delta-7', 'Delta-7 was built to diagnose faults in Beoctica''s machinery. It never expected to diagnose one in the Pantheon itself: they are not gods. They are the twelfth version of something that has failed before — twelve times.', 'images/covers/book-6.jpg', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 6),
(7, 2, 1, 'The Reactor Born', 'Book Seven', 'World: Reanium &nbsp;·&nbsp; Overlord: Korrus Vale &nbsp;·&nbsp; Protagonist: Jax', 'Jax was raised in Reanium''s nuclear ruins, radiation scars tracing patterns he''s seen somewhere before. Korrus Vale calls his final gambit a rebirth. Jax calls it what it is: an attempt to wake something that was buried on purpose.', 'images/covers/book-7.jpg', 'images/char-korrus.jpg', 'Korrus Vale', 0, NULL, NULL, NULL, NULL, NULL, NULL, 7),
(8, 2, 1, 'The Vermillion Ghost', 'Book Eight', 'World: Vermillia XI &nbsp;·&nbsp; Protagonist: the Dome Warden''s cloned daughter', 'Vermillia XI''s domes were never built to protect anyone — they''re filters. Its oceans hold something worse than water: the liquefied remains of an earlier Pantheon, and a consciousness that isn''t finished yet.', 'images/covers/book-8.jpg', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 8),
(9, 2, 1, 'The Warmaster''s March', 'Book Nine', 'The War of Lies, continued', 'A general who has never lost a campaign starts one he was never authorized to fight. He carries a shard that shows him a city burning — and he''s beginning to suspect the vision isn''t a warning. It''s an order.', 'images/covers/book-9.jpg', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 9),
(10, 3, 1, 'The Brass Forge', 'Book Ten', 'Protagonist: Teo Carnicus', 'Teo Carnicus spent the first nine books collecting curiosities — relics, theories, other people''s secrets. In the tenth, his research stops being academic. Cerius wants what he''s quietly built, and there''s no version of this that ends with his hands clean.', 'images/covers/book-10.jpg', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 10),
(11, 3, 1, 'The Perfect Grid', 'Book Eleven', 'The Key''s Price, continued', 'Every camera works. Every record matches. Every citizen is exactly where the system says they are — which is precisely why she doesn''t trust it. A grid this perfect isn''t built to protect anyone. It''s built to make sure nothing ever moves without permission.', 'images/covers/book-11.jpg', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 11),
(12, 3, 1, 'The Tyrant''s Crucible', 'Book Twelve', 'The Key''s Price, continued', 'Every Overlord who''s ever been toppled was tested first — quietly, deniably, by someone who wanted to know exactly how far a god could be pushed before he broke. Now it''s Cerius''s turn in the crucible, and Malric Thorne was never built to fail a test gracefully.', 'images/covers/book-12.jpg', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 12),
(13, 3, 1, 'The Empty Seat', 'Book Thirteen', 'The Nexus Veil', 'For ten thousand years the thirteenth seat at the Nexus Veil has stood empty — a warning nobody living can explain. Book Thirteen finally asks who sits in it, what it costs them, and what wakes up in the room the moment they do.', 'images/covers/book-13.jpg', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 13),
(14, 3, 1, 'The Iridescent War', 'Book Fourteen &nbsp;·&nbsp; Series Finale', 'Every thread, one table', 'Every shard-bearer who survived this far converges at last, hands raised toward the same impossible light. Twelve thrones fall. What rises depends entirely on who''s still standing when the iridescence fades — and who they''ve become by then.', 'images/covers/book-14.jpg', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 14);
