"""Every pw_* function an endpoint calls must be defined in a file that
endpoint actually requires.

Written because a check that only asked "does this function exist anywhere
under api/" passed while the endpoint fataled: pw_research_player_effects()
existed, but nothing in the sweep's require graph loaded it. Existing and being
reachable are different questions, and only the second one matters at runtime.

Calls guarded by function_exists() are exempt -- that is the deliberate pattern
missions-helpers.php uses for optional features.
"""
import io
import os
import re

ROOT = 'C:/The Pantheon Wars/'
ENDPOINTS = [
    'api/missions/sweep/state.php',
    'api/missions/sweep/start.php',
    'api/missions/sweep/pick.php',
    'api/missions/sweep/bank.php',
    'api/admin/sweep-tiers/list.php',
    'api/admin/sweep-tiers/save.php',
    'api/admin/sweep-tiers/delete.php',
]


def strip(src):
    src = re.sub(r'/\*.*?\*/', '', src, flags=re.S)
    return re.sub(r'//[^\n]*', '', src)


def resolve(path, seen):
    """Follow require_once __DIR__ . '...' transitively."""
    path = os.path.normpath(path)
    if path in seen or not os.path.exists(path):
        return
    seen.add(path)
    src = strip(io.open(path, encoding='utf-8', errors='replace').read())
    # Only requires at the start of a line. api/helpers.php has two inside a
    # function body, which run when that function is called and not at load --
    # counting those is what made this check pass on the very bug it exists
    # for. Every top-level require in this codebase sits at column 0.
    for rel in re.findall(r"^require(?:_once)?\s+__DIR__\s*\.\s*'([^']+)'", src, re.M):
        resolve(os.path.join(os.path.dirname(path), rel.lstrip('/')), seen)


failures = []
for endpoint in ENDPOINTS:
    graph = set()
    resolve(ROOT + endpoint, graph)
    defined = set()
    for f in graph:
        defined |= set(re.findall(r'\nfunction\s+(pw_\w+)\s*\(',
                                  io.open(f, encoding='utf-8', errors='replace').read()))
    src = strip(io.open(ROOT + endpoint, encoding='utf-8').read())
    # Only the endpoint's own body: the helpers it pulls in are each covered by
    # whichever endpoint requires them.
    guarded = set(re.findall(r"function_exists\('(pw_\w+)'\)", src))
    called = set(re.findall(r'(?<![>:$\w])(pw_\w+)\s*\(', src))
    missing = sorted(called - defined - guarded)
    print('%-5s %-44s %d files, %d calls%s'
          % ('FAIL' if missing else 'ok', endpoint.replace('api/', ''), len(graph), len(called),
             '  MISSING: ' + ', '.join(missing) if missing else ''))
    if missing:
        failures.append((endpoint, missing))

# The helpers themselves, checked against their own graph.
for helper in ['api/missions/sweep-helpers.php']:
    graph = set()
    resolve(ROOT + helper, graph)
    defined = set()
    for f in graph:
        defined |= set(re.findall(r'\nfunction\s+(pw_\w+)\s*\(',
                                  io.open(f, encoding='utf-8', errors='replace').read()))
    src = strip(io.open(ROOT + helper, encoding='utf-8').read())
    guarded = set(re.findall(r"function_exists\('(pw_\w+)'\)", src))
    called = set(re.findall(r'(?<![>:$\w])(pw_\w+)\s*\(', src))
    missing = sorted(called - defined - guarded)
    print('%-5s %-44s %d files, %d calls%s'
          % ('FAIL' if missing else 'ok', helper.replace('api/', ''), len(graph), len(called),
             '  MISSING: ' + ', '.join(missing) if missing else ''))
    if missing:
        failures.append((helper, missing))

print('\n%d checked, %d with unreachable calls' % (len(ENDPOINTS) + 1, len(failures)))
