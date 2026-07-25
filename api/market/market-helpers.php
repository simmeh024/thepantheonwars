<?php
/** Shared, server-authoritative rules for the player market. */
require_once __DIR__ . '/../missions/missions-helpers.php';

const PW_MARKET_WINDOW_SECONDS = 21600;
const PW_MARKET_GEAR_OFFERS = 6;
const PW_MARKET_CHARACTER_OFFERS = 3;

function pw_market_ready(PDO $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!pw_mission_credits_ready($db) || !pw_mission_gear_ready($db)) return $ready = false;
    try {
        foreach (['game_market_entries', 'game_market_rotations', 'game_market_rotation_items', 'game_market_purchases'] as $table) {
            $db->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
        }
        return $ready = true;
    } catch (Throwable $e) {
        return $ready = false;
    }
}

function pw_market_require_ready(PDO $db): void {
    if (!pw_market_ready($db)) {
        pw_error('The Market is being prepared. Please try again after sql/migration_market.sql has been run.', 503);
    }
}

/** The gear window is 00/06/12/18 UTC; characters are the same clock plus 1h. */
function pw_market_window(DateTimeImmutable $now, string $offerType): array {
    $offset = $offerType === 'character' ? 3600 : 0;
    $timestamp = $now->getTimestamp();
    $startTimestamp = (int)(floor(($timestamp - $offset) / PW_MARKET_WINDOW_SECONDS) * PW_MARKET_WINDOW_SECONDS + $offset);
    $start = (new DateTimeImmutable('@' . $startTimestamp))->setTimezone(new DateTimeZone('UTC'));
    return [$start, $start->modify('+' . PW_MARKET_WINDOW_SECONDS . ' seconds')];
}

function pw_market_offer_count(string $offerType): int {
    return $offerType === 'character' ? PW_MARKET_CHARACTER_OFFERS : PW_MARKET_GEAR_OFFERS;
}

/** Weighted sampling without replacement. The first request for a window is the
 * only request that performs this roll; the rows it writes are shared by all
 * players until the window closes. */
function pw_market_weighted_pick(array $entries, int $count): array {
    $picked = [];
    while ($entries && count($picked) < $count) {
        $total = 0;
        foreach ($entries as $entry) $total += max(1, (int)$entry['rotation_weight']);
        $roll = random_int(1, $total);
        $cursor = 0;
        foreach ($entries as $index => $entry) {
            $cursor += max(1, (int)$entry['rotation_weight']);
            if ($roll <= $cursor) {
                $picked[] = $entry;
                array_splice($entries, $index, 1);
                break;
            }
        }
    }
    return $picked;
}

function pw_market_rotation(PDO $db, string $offerType, DateTimeImmutable $now): array {
    if (!in_array($offerType, ['gear', 'character'], true)) throw new InvalidArgumentException('Unknown market offer type.');
    [$start, $ends] = pw_market_window($now, $offerType);
    $startText = pw_missions_datetime($start);
    $lookup = $db->prepare('SELECT id, offer_type, window_started_at, window_ends_at FROM game_market_rotations WHERE offer_type = ? AND window_started_at = ? FOR UPDATE');
    $lookup->execute([$offerType, $startText]);
    $rotation = $lookup->fetch();
    if ($rotation) return $rotation;

    $sourceColumn = $offerType === 'gear' ? 'loot_definition_id' : 'crew_definition_id';
    $sourceTable = $offerType === 'gear' ? 'game_loot_definitions' : 'game_crew_definitions';
    /* Starter crew are granted automatically when a player first enters
     * Missions, so presenting one as a paid recruitment lead would create a
     * misleading offer for every established player. */
    $sourceWhere = $offerType === 'gear' ? "d.is_enabled = 1 AND d.slot <> ''" : 'd.is_enabled = 1 AND d.is_starter = 0';
    $candidates = $db->prepare(
        'SELECT e.id, e.credit_price, e.required_reputation_level, e.rotation_weight, e.stock_per_rotation
         FROM game_market_entries e
         JOIN ' . $sourceTable . ' d ON d.id = e.' . $sourceColumn . '
         WHERE e.offer_type = ? AND e.is_enabled = 1 AND ' . $sourceWhere
    );
    $candidates->execute([$offerType]);
    $chosen = pw_market_weighted_pick($candidates->fetchAll(), pw_market_offer_count($offerType));

    try {
        $insert = $db->prepare('INSERT INTO game_market_rotations (offer_type, window_started_at, window_ends_at) VALUES (?, ?, ?)');
        $insert->execute([$offerType, $startText, pw_missions_datetime($ends)]);
        $rotation = ['id' => (int)$db->lastInsertId(), 'offer_type' => $offerType, 'window_started_at' => $startText, 'window_ends_at' => pw_missions_datetime($ends)];
    } catch (PDOException $e) {
        // A second request may have won the unique window insert while this
        // worker rolled candidates. Read that canonical result instead.
        $lookup->execute([$offerType, $startText]);
        $rotation = $lookup->fetch();
        if (!$rotation) throw $e;
        return $rotation;
    }

    if ($chosen) {
        $insertItem = $db->prepare(
            'INSERT INTO game_market_rotation_items
             (market_rotation_id, market_entry_id, credit_price, required_reputation_level, stock_initial, stock_remaining, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($chosen as $position => $entry) {
            $stock = max(1, (int)$entry['stock_per_rotation']);
            $insertItem->execute([(int)$rotation['id'], (int)$entry['id'], (int)$entry['credit_price'], (int)$entry['required_reputation_level'], $stock, $stock, $position + 1]);
        }
    }
    return $rotation;
}

function pw_market_current_rotations(PDO $db, DateTimeImmutable $now): array {
    $started = false;
    if (!$db->inTransaction()) { $db->beginTransaction(); $started = true; }
    try {
        $rotations = [
            'gear' => pw_market_rotation($db, 'gear', $now),
            'character' => pw_market_rotation($db, 'character', $now),
        ];
        if ($started) $db->commit();
        return $rotations;
    } catch (Throwable $e) {
        if ($started && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function pw_market_reputation_level(int $points): int {
    $info = pw_reputation_info($points);
    return max(0, (int)($info['level_number'] ?? 0));
}

function pw_market_public_offers(PDO $db, int $rotationId, string $offerType, int $reputationLevel): array {
    $isGear = $offerType === 'gear';
    $details = $isGear
        ? 'd.name, d.slug, d.description, d.tier, d.slot, d.icon_url, d.bonus_strength, d.bonus_cunning, d.bonus_science, d.bonus_charisma, d.required_level, d.required_role'
        : 'd.name, d.slug, d.description, d.role, d.portrait_url, d.starting_level, d.world_affinity';
    $table = $isGear ? 'game_loot_definitions' : 'game_crew_definitions';
    $column = $isGear ? 'loot_definition_id' : 'crew_definition_id';
    $stmt = $db->prepare(
        'SELECT i.id, i.credit_price, i.required_reputation_level, i.stock_initial, i.stock_remaining, i.sort_order, ' . $details . '
         FROM game_market_rotation_items i
         JOIN game_market_entries e ON e.id = i.market_entry_id AND e.is_enabled = 1
         JOIN ' . $table . ' d ON d.id = e.' . $column . ' AND d.is_enabled = 1' . ($isGear ? '' : ' AND d.is_starter = 0') . '
         WHERE i.market_rotation_id = ? AND e.offer_type = ? AND i.required_reputation_level <= ?
         ORDER BY i.sort_order ASC, i.id ASC'
    );
    $stmt->execute([$rotationId, $offerType, $reputationLevel]);
    return array_map(static function ($row) use ($isGear) {
        foreach (['id', 'credit_price', 'required_reputation_level', 'stock_initial', 'stock_remaining', 'sort_order'] as $field) $row[$field] = (int)$row[$field];
        if ($isGear) foreach (['bonus_strength', 'bonus_cunning', 'bonus_science', 'bonus_charisma', 'required_level'] as $field) $row[$field] = (int)$row[$field];
        else $row['starting_level'] = (int)$row['starting_level'];
        return $row;
    }, $stmt->fetchAll());
}

function pw_market_next_rank_unlocks(PDO $db, int $rankNumber, int $threshold, string $accent): array {
    try {
        $stmt = $db->prepare(
            'SELECT e.offer_type, e.credit_price, e.stock_per_rotation, l.name, l.tier, l.slot, l.icon_url, NULL AS role, NULL AS portrait_url
             FROM game_market_entries e JOIN game_loot_definitions l ON l.id = e.loot_definition_id
             WHERE e.offer_type = "gear" AND e.is_enabled = 1 AND l.is_enabled = 1 AND e.required_reputation_level = ?
             UNION ALL
             SELECT e.offer_type, e.credit_price, e.stock_per_rotation, c.name, NULL AS tier, NULL AS slot, NULL AS icon_url, c.role, c.portrait_url
             FROM game_market_entries e JOIN game_crew_definitions c ON c.id = e.crew_definition_id
             WHERE e.offer_type = "character" AND e.is_enabled = 1 AND c.is_enabled = 1 AND c.is_starter = 0 AND e.required_reputation_level = ?
             ORDER BY offer_type ASC, name ASC'
        );
        $stmt->execute([$rankNumber, $rankNumber]);
        $unlocks = [];
        foreach ($stmt->fetchAll() as $row) {
            $isGear = $row['offer_type'] === 'gear';
            $unlocks[] = [
                'type' => $isGear ? 'market_gear' : 'market_character',
                'title' => (string)$row['name'],
                'eyebrow' => $isGear ? 'Market equipment' : 'Market character',
                'description' => $isGear
                    ? ucfirst((string)$row['tier']) . ' ' . ((string)$row['slot'] !== '' ? str_replace('-', ' ', (string)$row['slot']) : 'gear') . ' enters your eligible market rotation. Price: ' . number_format((int)$row['credit_price']) . ' credits.'
                    : ((string)$row['role'] !== '' ? (string)$row['role'] . ' recruit' : 'Recruit') . ' enters your eligible character rotation. Price: ' . number_format((int)$row['credit_price']) . ' credits.',
                'accent' => $accent,
                'threshold' => $threshold,
                'market_type' => $row['offer_type'],
            ];
        }
        return $unlocks;
    } catch (Throwable $e) {
        // Reputation can roll out ahead of the Market migration without losing
        // its established rank preview.
        return [];
    }
}
