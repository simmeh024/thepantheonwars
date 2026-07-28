# Salvage Sweep

A played board rather than a timer. The player sends one crew member into a
collapsed field, turns cells over one at a time, and decides when to withdraw.
A collapse ends the run and takes the haul with it.

This document is the source of truth for the flow, the systems it touches, and
every way a player can make a sweep better. Figures are read from the code
named beside them; if one of those changes, change it here too.

- Player page: `sweep.html` + `js/sweep.js` + `css/sweep.css`
- Engine: `api/missions/sweep-helpers.php`
- Endpoints: `api/missions/sweep/{state,start,pick,bank}.php`
- Authoring: Admin → Game Control → **Sweep Tiers**
  (`api/admin/sweep-tiers/{list,save,delete}.php`, `js/admin-sweep-tiers.js`)
- Balance: Admin → Game Control → **Game Tuning** → Salvage Sweep sectors
  (`api/admin/game-tuning/sweep.php`)
- Schema: `sql/migration_salvage_sweep.sql`

---

## 1. The flow, start to finish

```
Player opens sweep.html
        │
        ▼
state.php ── reputation rank ──► pick the SECTOR
        │                        (highest enabled tier at or below the rank)
        │
        ├─ no sector at or below rank ──► nothing to play; ladder shows what opens next
        │
        ▼
Player picks a crew member from the roster
        │
        ├─ crew on assignment          ──► refused
        ├─ fatigue < sector cost       ──► refused
        │
        ▼
start.php
   • re-reads the rank and re-resolves the sector (never trusts the browser)
   • charges the crew member's fatigue, sets them to on_mission
   • rolls a secret seed
   • freezes the board: size, collapses, scans, survey, brace, and the four
     research perks, all written onto the run row
        │
        ▼
   ┌───► pick.php  (one cell)
   │        │
   │        ├─ SAFE  ─► cache (credits) | item | crew | nothing
   │        │            └─ recorded against the run, NOT granted yet
   │        │
   │        └─ COLLAPSE
   │              ├─ brace available and it holds ──► run continues, brace spent
   │              └─ otherwise ──► run is LOST
   │                                 └─ Emergency Tether may return one item
   │
   ├─ scans remain and player continues ──► loop
   ├─ scans exhausted ──────────────────► run stays bankable, no more picks
   └─ player stops
        │
        ▼
bank.php
   • grants every recorded item through the normal inventory path
   • pays credits found, plus a rarity payout for any duplicate crew
   • pays XP to the crew member who ran the board
   • sends the crew member home, rest starting from now
        │
        ▼
After-action reveal
   • a banked, lost or abandoned run returns a complete field map
   • every cache, item and crew recovery is marked by row and column
   • the debrief distinguishes rewards secured from those left in the field
```

Withdrawing early keeps everything found so far. That is the whole bargain: one
more scan is one more chance to lose all of it.

---

## 2. What decides what

### The sector — set by reputation rank

One tier per rank, authored in Sweep Tiers. A rank plays the **highest enabled
sector at or below it**, so the ladder can be filled in sparsely: a single
sector at rank 1 covers every player until a second is written.

Each sector sets: field size, number of collapses, base scans, cache ceiling,
fatigue cost, XP reward, and the **loot table** every find is drawn from. A
sector with no usable loot table cannot be opened.

### The crew member — four stats, four different jobs

Read from the same stats the missions roster shows, equipment included.
Constants in `api/missions/sweep-helpers.php`.

| Stat | Buys | Rate |
|---|---|---|
| Cunning | Extra scans | 1 per 12 points (`PW_SWEEP_CUNNING_PER_PICK`) |
| Science | Survey rings — how many collapses border an opened cell | 1 ring per 18 points, max 2 (`PW_SWEEP_SCIENCE_PER_RING`) |
| Strength | Brace — one chance to survive the first collapse | 1.4% per point, capped 60% (`PW_SWEEP_STRENGTH_SHRUG_PER_POINT`, `PW_SWEEP_SHRUG_CAP`) |
| Charisma | XP from a banked sweep | +0.8% per point (`PW_SWEEP_CHARISMA_XP_PER_POINT`) |

Scans can never exceed the cells on the board: a field that cannot be failed is
not a decision.

### The board — a pure function of a secret seed

`grid_seed` is drawn per run and **never leaves the server**. The hazard layout
and every cell's contents derive from it, so a preview can be honest and a
reload cannot reroll anything. The browser is sent only the cells already
turned over while a run is active. Once the server has closed the run, its
after-action report reveals the whole field; it is then no longer possible to
make a decision from that information.

---

## 3. Everything connected to a sweep

| System | Connection |
|---|---|
| **Reputation** | Rank picks the sector. Nothing else gates entry. |
| **Crew** | One crew member per run. Their four stats set the board's difficulty and reward; they are `on_mission` for the duration. |
| **Fatigue** | The entry cost. Charged at launch; rest starts when the crew member returns, not when they left. |
| **Equipment** | Folded into the crew stats before the board is built, so gear raises scans, survey, brace and XP. |
| **Loot tables** | The sector's manifest. Mark one **Salvage Sweep manifest** in Loot Table Management and it stops dropping from missions and cannot be attached to one. |
| **Inventory** | Banked items go through the same store path a mission claim uses, so the quartermaster ceilings apply and anything that will not fit is reported. |
| **Credits** | Caches pay credits, and a duplicate crew find pays its rarity value instead. |
| **Crew roster** | A crew find joins the roster. A duplicate pays credits; one with no berth becomes a held recruit offer. |
| **Research** | Eight effects, below. |
| **Game Tuning** | Reads the whole ladder and flags sectors that cannot be failed, are almost never banked, or pay more than twice the median per fatigue. |

---

## 4. How a player improves a sweep

Four levers, in the order a player meets them.

### 1. Send a better crew member

The fastest improvement and the only one available immediately. A higher-level
crew member of a rarer tier has more of every stat, and equipment adds more on
top. Rarity multiplies the stats a level grants — ×1 common, ×1.25 uncommon,
×1.5 rare, ×1.75 epic, ×2 legendary.

### 2. Raise reputation

A higher rank opens a higher sector, with a richer manifest. This is the only
thing that changes *what can be found*; everything else changes how much of it
survives the trip.

### 3. Research

Eight effects in `pw_research_effect_types()`. **Four scale** — author as many
nodes as the tree needs and they add up. **Four are graded** — an effect
declaring `accumulate: max` takes the highest owned, so Emergency Tether II at
15% replaces Tether I at 10% rather than making 25%.

| Effect | Does | Account cap |
|---|---|---|
| **Shoring** (`sweep_collapse`) | Removes collapses from the field. One always remains. | 50% |
| **Scan capacity** (`sweep_scans`) | Flat extra scans, on top of the sector base and Cunning. | +10 |
| **Brace tuning** (`sweep_brace`) | Raises the brace chance as a percentage of what Strength already bought — worth nothing with no Strength. | +100% |
| **Survey tuning** (`sweep_survey`) | Cuts the Science each survey ring costs, so hints come sooner. | 60% |
| **Emergency Tether** (`sweep_tether`) — *graded* | On a collapse, the chance to keep one item already recovered. Saves an item, never the credits. | 100% |
| **Cache Recognition** (`sweep_recognition`) | Chance an unopened cell is identified as material, credits, equipment or unknown. Never which item. | 60% |
| **Momentum Recovery** (`sweep_momentum`) | Each safe reveal raises the credits the rest of the sweep pays, compounding until it is banked or lost. | +10% per reveal |
| **Field Stabiliser** (`sweep_stabiliser`) — *graded* | Percentage points off the collapse risk of the **first scan only**. | 20 points |

Two deliberate limits worth knowing:

- **Shoring can never clear a field.** One collapse always remains, or there is
  no decision left to make.
- **Recognition caps at 60%, below its authoring ceiling of 100%.** It
  identifies only safe cells, so at very high coverage an *un*identified cell
  would simply mean "collapse" and the hint would have become a detector.

### 4. Play the board better

Nothing to unlock. Withdraw before the odds turn; use survey hints to pick away
from collapses; remember that Momentum makes late caches worth more than early
ones, which is an argument for staying, and that the risk compounds, which is an
argument for leaving.

---

## 5. Rules that are load-bearing

Change any of these and the mechanic changes with it.

1. **Nothing found exists until the run is banked.** A pick records what a cell
   held; only withdrawing turns that into inventory, credits and XP. Granting on
   reveal would make withdrawing meaningless.
2. **A collapse pays nothing** except what the Emergency Tether returns.
3. **The board is frozen at launch.** Unlocking a protocol mid-sweep must not
   change a field already being walked.
4. **One board at a time.** Otherwise a player could open several, spend every
   crew member's fatigue at once, and bank only the luckiest.
5. **The seed never leaves the server**, and unopened cells are sent as nothing
   at all rather than as masked values — a masked value still says how many
   cells hold something. The only exception is the after-action report for a
   closed run, when its full field map cannot influence play.
6. **The crew member always comes home**, whether the run is banked, abandoned
   or lost. Only the haul is at stake.
7. **The sector is re-resolved from the held rank on every launch.** Nothing the
   browser sends chooses a board.

---

## 6. Authoring a sector

1. Build the loot table in **Loot Table Management** and tick **Salvage Sweep
   manifest**, which withdraws it from missions. Use **Copy this table** to base
   the next one on it.
2. In **Sweep Tiers**, add a sector for the rank it should open at. Set the
   field size, collapses, base scans, cache ceiling, fatigue cost and XP, and
   name the manifest.
3. Check it in **Game Tuning → Salvage Sweep sectors**. Survival is the column
   that matters: a sector nobody loses and a sector nobody banks are both
   broken, in opposite directions, and neither is visible from the authored
   numbers alone.

A rank with no sector simply has no sweep, so the ladder can be filled in over
time and in any order.
