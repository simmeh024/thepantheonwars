# Optional local sentence-embedding service for Dispatch translations

This is a second, independent local NLP capability alongside the spaCy/
RapidFuzz worker documented in `docs/dispatch-spacy.md`. It adds real
sentence-embedding semantic similarity (via `sentence-transformers`'
`all-MiniLM-L6-v2`) so a new commit can be compared against the corpus of
previously *approved* Dispatch translations based on meaning, not shared
words. The two systems are deliberately kept separate: they use different
Python venvs, different config constants, and neither depends on the other.

The rule-based formatter (`api/dispatch-translation-drafts.php`) remains the
sole author of every reader-facing sentence and the sole authority on
auto-publication. This service only ever contributes:

1. A graded confidence-evidence signal (the existing `semantic_context`
   weight, still capped at 5 points -- see `docs/dispatch-spacy.md`'s
   confidence-weights table).
2. An editor-facing "Similar past Dispatch" reference panel in the Dispatch
   Translations review modal, shown only when a strong match exists. A human
   can copy/adapt its wording; nothing here ever writes to a draft
   automatically.

If the service is unconfigured, unreachable, or slow, every function in
`api/dispatch-embeddings.php` fails open to an empty result -- the
deterministic formatter produces exactly its existing fallback, with zero
change to wording or publication decisions.

## Why this runs as a persistent service, not a one-shot script

The spaCy worker (`tools/dispatch-nlp.py`) runs as a fresh Python process per
call via `proc_open`, inside a hardcoded 6-second budget
(`api/dispatch-spacy.php`). That pattern doesn't work for
`sentence-transformers`: `import torch` alone commonly costs 1-3 seconds of
pure Python startup time, before any model load. Paying that on every single
"Generate Draft" click and webhook-triggered auto-draft would routinely
exceed the existing bridge's budget -- not occasionally, but as the steady
state, silently degrading the feature to "no signal" most of the time it
runs while still spending CPU/RAM trying.

Instead, `tools/dispatch-embeddings-service.py` is a small persistent Flask
app that loads the model exactly once, at process start, via cPanel's
"Setup Python App" (a Passenger/WSGI host under the hood). Every request
after that only pays the actual encode cost -- milliseconds for a
commit-message-length string on CPU. `api/dispatch-embeddings.php` talks to
it over loopback HTTP with a 1-second connect / 2-second total timeout.

## How "only the incoming commit needs encoding" actually works

The embedding service itself is stateless: its only job is
`POST /encode {text} -> {embedding}`. It never touches MySQL and never sees a
corpus of prior translations. The cache lives entirely on the PHP side, in
the `dispatch_translation_embeddings` table (one row per approved
translation, written by `pw_dispatch_update_translation_embedding()` at
publish/edit time). At draft-generation time, PHP encodes only the *one new
commit*, then computes cosine similarity against the cached corpus itself
(`pw_dispatch_cosine_similarity()`/`pw_dispatch_nearest_embedding_match()` in
`api/dispatch-embeddings.php`) -- no network round trip per comparison, and
no prior translation's text is ever sent back to Python.

## A real difference from the spaCy worker: there is no loopback-only option

Unlike a self-managed server, cPanel's "Setup Python App" always exposes a
Python app through a real URL path on the account's own domain (e.g.
`https://thepantheonwars.com/dispatch-embeddings`) -- there is no way to get
a raw `127.0.0.1:<port>` you can reach only from PHP running on the same
box. That means, unlike the spaCy worker (which never listens on anything),
this endpoint is technically internet-reachable by anyone who finds its URL.
`DISPATCH_EMBEDDING_SERVICE_KEY` closes that gap: PHP sends it as an
`X-Dispatch-Key` header on every request, and
`tools/dispatch_embeddings_service.py` rejects `/encode` calls that don't
present it. Treat it exactly like any other secret in this project (random,
outside-webroot-only, never committed).

## cPanel setup (one time)

1. In cPanel, open **Setup Python App** → **Create Application** and fill in:
   - **Python version:** 3.11 or 3.12 (whatever the picker defaults to is fine).
   - **Application root:** a new folder name *outside* `public_html`, e.g.
     `dispatch-embeddings-app` (creates `/home/rdy3i6my40b0/dispatch-embeddings-app`).
     Do not reuse the existing `dispatch-nlp` app/venv -- keeping this
     isolated means a problem with the much heavier `torch`/
     `sentence-transformers` dependency chain can never affect the
     already-working spaCy worker.
   - **Application URL:** leave the domain as `thepantheonwars.com` and give
     it a path segment, e.g. `dispatch-embeddings` (this becomes the real
     URL -- see above for why `DISPATCH_EMBEDDING_SERVICE_KEY` matters).
   - **Application startup file:** `passenger_wsgi.py`
   - **Application Entry point:** `application`
   - **Passenger log file:** e.g. `/home/rdy3i6my40b0/logs/dispatch-embeddings-passenger.log`
   Click Create.
2. cPanel shows a command to enter the app's virtual environment. Run it,
   then install the committed dependencies:

   ```bash
   python -m pip install --upgrade pip
   python -m pip install -r /home/rdy3i6my40b0/public_html/tools/requirements-dispatch-embeddings.txt
   ```

3. cPanel creates a starter `passenger_wsgi.py` inside the application root.
   Replace its contents with:

   ```python
   import sys
   sys.path.insert(0, '/home/rdy3i6my40b0/public_html/tools')
   from dispatch_embeddings_service import app as application
   ```

   (`tools/dispatch_embeddings_service.py` -- underscores, not hyphens, since
   Python can't import a hyphenated module name -- is deployed into
   `public_html/tools` by the normal git push/cPanel-deploy flow, same as
   every other file in `tools/`; nothing extra needs to be copied into the
   application root itself.)
4. In the app's cPanel page, add an **environment variable**
   `DISPATCH_EMBEDDING_SERVICE_KEY` with a freshly generated random value
   (`php -r "echo bin2hex(random_bytes(24));"` from any existing PHP
   context, or any long random string). Restart the app so it picks up the
   dependencies, the environment variable, and loads the model.
5. In `/home/rdy3i6my40b0/pantheonwars-secrets/config.php`, add the *same*
   key value plus the app's real URL from step 1:

   ```php
   define('DISPATCH_EMBEDDING_SERVICE_URL', 'https://thepantheonwars.com/dispatch-embeddings');
   define('DISPATCH_EMBEDDING_SERVICE_KEY', 'THE_SAME_RANDOM_VALUE_FROM_STEP_4');
   ```

6. Run the one-off backfill so existing approved translations get an
   embedding cached (safe to re-run; skips anything already cached):

   ```bash
   php tools/backfill-dispatch-embeddings.php
   ```

7. Apply `sql/migration_dispatch_translation_embeddings.sql` in phpMyAdmin
   before step 6, if it hasn't already been applied.

## Verifying real resource usage before trusting it

This account's documented constraints (see `CLAUDE.md`) are a 75 GiB
whole-account disk quota (shared with everything else -- not expected to be
the bottleneck here) and 2 allocated vCPU cores, but there is **no documented
PHP/process memory limit or LVE RAM ceiling** anywhere for this host. A
`torch`-backed process is meaningfully heavier on both CPU (BLAS thread
init) and resident memory (typically 300-500MB+ just from the import, more
with the model loaded) than the existing lightweight spaCy worker. Don't
assume this fits -- check it:

- After the app has been running for a while, find its PID (cPanel's Setup
  Python App page, or `ps aux | grep dispatch_embeddings`) and read
  `/proc/<pid>/status`'s `VmRSS` line for real resident memory.
- Use `du -sb` against the venv directory to confirm real install size
  against the account's actual quota (not `disk_free_space()` -- see
  `CLAUDE.md`'s note on why that PHP function is unreliable on this host).
- Watch System Status's new **Embedding Service** row (System > System
  Status > Security and Scripts) over a few days of normal admin use for any
  Disconnected periods that might indicate the app is being killed/restarted
  under memory pressure.

If it turns out to get OOM-killed, hit a Passenger process-slot limit, or
otherwise misbehave in practice, disabling it costs nothing: remove
`DISPATCH_EMBEDDING_SERVICE_URL` from the secrets config (optionally also
stop the Passenger app in cPanel). No PHP code revert or redeploy is needed
-- every function in `api/dispatch-embeddings.php` already fails open when
that constant is undefined.
