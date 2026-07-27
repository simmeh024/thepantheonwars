"""Every claim in docs/salvage-sweep.md that names a number or a file, checked
against the code. Documentation that drifts is worse than none, and this one is
about to become a flowchart.
"""
import io
import os
import re

ROOT = 'C:/The Pantheon Wars/'
DOC = io.open(ROOT + 'docs/salvage-sweep.md', encoding='utf-8').read()
SWEEP = io.open(ROOT + 'api/missions/sweep-helpers.php', encoding='utf-8').read()
RES = io.open(ROOT + 'api/research/research-helpers.php', encoding='utf-8').read()
checks = []


def ck(label, ok):
    checks.append((label, ok))


# --- every file the doc names must exist ------------------------------------
for path in re.findall(r'`(api/[\w/{},.-]+\.php|js/[\w-]+\.js|css/[\w-]+\.css|sql/[\w_]+\.sql|sweep\.html)`', DOC):
    if '{' in path:
        base, names = path.split('{')
        for name in names.rstrip('}.php').split(','):
            ck('file exists: ' + base + name + '.php', os.path.exists(ROOT + base + name + '.php'))
        continue
    ck('file exists: ' + path, os.path.exists(ROOT + path))

# --- the crew stat rates ------------------------------------------------------
def const(name):
    return float(re.search(r'const ' + name + r' = ([\d.]+);', SWEEP).group(1))


ck('Cunning: doc says 1 per 12 and the code agrees',
   '1 per 12 points' in DOC and const('PW_SWEEP_CUNNING_PER_PICK') == 12)
ck('Science: doc says 1 ring per 18, max 2',
   '1 ring per 18 points, max 2' in DOC and const('PW_SWEEP_SCIENCE_PER_RING') == 18
   and 'min(2,' in SWEEP)
ck('Strength: doc says 1.4% per point capped 60%',
   '1.4% per point, capped 60%' in DOC and const('PW_SWEEP_STRENGTH_SHRUG_PER_POINT') == 1.4
   and const('PW_SWEEP_SHRUG_CAP') == 60.0)
ck('Charisma: doc says +0.8% per point',
   '+0.8% per point' in DOC and const('PW_SWEEP_CHARISMA_XP_PER_POINT') == 0.8)

# --- the research caps --------------------------------------------------------
caps = dict((m.group(2), float(m.group(1)))
            for m in re.finditer(r"min\(([\d.]+), \$effects\['(sweep_\w+)'\]", RES))
scans_cap = re.search(r"\$effects\['sweep_scans'\] = \(int\)min\((\d+),", RES)
ck('Shoring cap 50% matches', caps.get('sweep_collapse_percent') == 50.0 and '| 50% |' in DOC)
ck('Scan capacity cap +10 matches', scans_cap and int(scans_cap.group(1)) == 10 and '| +10 |' in DOC)
ck('Brace tuning cap +100% matches', caps.get('sweep_brace_percent') == 100.0 and '| +100% |' in DOC)
ck('Survey tuning cap 60% matches', caps.get('sweep_survey_percent') == 60.0)
ck('Tether cap 100% matches', caps.get('sweep_tether_percent') == 100.0)
ck('Recognition cap 60% matches, and is below its authoring ceiling',
   caps.get('sweep_recognition_percent') == 60.0
   and 'Recognition caps at 60%, below its authoring ceiling of 100%' in DOC
   and "'sweep_recognition' =>" in RES and "'max' => 100" in RES.split("'sweep_recognition' =>")[1][:400])
ck('Momentum cap +10% matches', caps.get('sweep_momentum_percent') == 10.0 and '| +10% per reveal |' in DOC)
ck('Stabiliser cap 20 points matches', caps.get('sweep_stabiliser_points') == 20.0 and '| 20 points |' in DOC)

# --- all eight effects are documented, and none is invented -------------------
in_code = set(re.findall(r"'(sweep_\w+)' => \['label'", RES))
in_doc = set(re.findall(r'\(`(sweep_\w+)`\)', DOC))
ck('the doc lists exactly the eight effects the code has (%d/%d)' % (len(in_doc), len(in_code)),
   in_doc == in_code and len(in_code) == 8)

# --- the graded pair ----------------------------------------------------------
graded = set(re.findall(r"'(sweep_\w+)' => \['label'[^\n]*'accumulate' => 'max'", RES))
ck('the doc marks exactly the two graded effects',
   graded == {'sweep_tether', 'sweep_stabiliser'} and DOC.count('*graded*') == 2)

# --- crew rarity multipliers --------------------------------------------------
MISS = io.open(ROOT + 'api/missions/missions-helpers.php', encoding='utf-8').read()
mults = dict((m.group(1), float(m.group(2))) for m in re.finditer(
    r"'(\w+)' => \['stat_multiplier' => ([\d.]+)", MISS))
ck('the rarity multipliers in the doc match the engine',
   mults == {'common': 1.0, 'uncommon': 1.25, 'rare': 1.5, 'epic': 1.75, 'legendary': 2.0}
   and 'x1 common, x1.25 uncommon,' in DOC.replace('\u00d7', 'x'))

# --- the load-bearing rules are real ------------------------------------------
PICK = io.open(ROOT + 'api/missions/sweep/pick.php', encoding='utf-8').read()
START = io.open(ROOT + 'api/missions/sweep/start.php', encoding='utf-8').read()
BANK = io.open(ROOT + 'api/missions/sweep/bank.php', encoding='utf-8').read()
ck('rule: nothing is granted before banking',
   'pw_missions_store_loot' in BANK and 'pw_missions_store_loot' not in PICK)
ck('rule: one board at a time', 'status = "active" FOR UPDATE' in START)
ck('rule: the sector is re-resolved at launch', 'pw_sweep_tier($db, $rank)' in START)
ck('rule: the crew member always comes home',
   'pw_sweep_release_crew' in PICK and 'pw_sweep_release_crew' in BANK)
ck('rule: the seed is never in a payload',
   'grid_seed' not in SWEEP.split('function pw_sweep_run_payload')[1].split('return [')[1])
ck('rule: the board is frozen at launch',
   'tether_percent, recognition_percent, momentum_percent, stabiliser_points' in START)
ck('rule: the sector is the highest at or below the rank',
   'tier.rank_number <= ?' in SWEEP and 'ORDER BY tier.rank_number DESC' in SWEEP)
ck('rule: shoring always leaves one collapse', 'return max(1, $hazards - $removed);' in SWEEP)

failed = [l for l, ok in checks if not ok]
for label, ok in checks:
    print('%-5s %s' % ('ok' if ok else 'FAIL', label))
print('\n%d claims checked, %d wrong' % (len(checks), len(failed)))
