<?php
/**
 * Kept as the original equipment-only destroy route.
 *
 * api/missions/inventory-destroy.php generalised this to every kind of item --
 * equipment, stims and salvage -- and owns the ownership and equipped-copies
 * rules. This file delegates to it rather than keeping a second copy of them,
 * so the two can never disagree about when a copy may be destroyed.
 *
 * Not deleted: a page cached before that change still posts here, and a cPanel
 * deploy copies files without ever removing one, so the route would keep
 * answering from a stale copy regardless.
 */
require __DIR__ . '/inventory-destroy.php';
