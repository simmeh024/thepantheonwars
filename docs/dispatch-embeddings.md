# Optional local sentence-embedding worker for Dispatch translations

This is a second, independent local NLP capability alongside the spaCy/
RapidFuzz worker documented in `docs/dispatch-spacy.md`. It adds real
sentence-embedding semantic similarity (via `sentence-transformers`'
`all-MiniLM-L6-v2`) so a new commit can be compared against the corpus of
previously *approved* Dispatch translations based on meaning, not shared
words. The two systems are deliberately kept separate: they use different
Python venvs, different config constants, and neither depends on the other.

The rule-based formatter (`api/dispatch-translation-drafts.php`) remains the
sole author of every reader-facing sentence and the sole authority on
auto-publication. This worker only ever contributes:

1. A graded confidence-evidence signal (the existing `semantic_context`
   weight, still capped at 5 points -- see `docs/dispatch-spacy.md`'s
   confidence-weights table).
2. An editor-facing "Similar past Dispatch" reference panel in the Dispatch
   Translations review modal, shown only when a strong match exists. A human
   can copy/adapt its wording; nothing here ever writes to a draft
   automatically.

If the worker is unconfigured, unreachable, or slow, every function in
`api/dispatch-embeddings.php` fails open to an empty result -- the
deterministic formatter produces exactly its existing fallback, with zero
change to wording or publication decisions.

## Architecture: one-shot process, not a persistent service (reversed from the original design)

This originally shipped as a persistent Flask app hosted via cPanel's
"Setup Python App" (Passenger/WSGI), loading the model once at process start
so every request after that only paid the actual encode cost. That design
was reverted after real production evidence:

- `ps -u rdy3i6my40b0 -o pid,ppid,%cpu,%mem,rss,etime,cmd` showed the
  persistent embeddings process resident at **~852MB RSS** on its own,
  on an account with roughly **1.5GB total RAM**.
- Every time a live translation actually invoked the embeddings workflow
  (i.e. an encode request happened), the existing spaCy Passenger worker --
  much lighter, previously stable -- disconnected immediately afterward.
  Restarting spaCy made it available again only until the next live
  translation. This is the classic signature of CloudLinux/Passenger's OOM
  enforcement picking a victim process once the account-wide memory ceiling
  is crossed, not an application-level bug in either worker.
- GoDaddy's process-count ceiling for this account is 50 -- also worth
  watching (`cPanel -> Metrics -> Resource Usage -> Details`), but the RSS
  evidence above is the dominant cause here.

`tools/dispatch-embeddings.py` is now a **one-shot script**, invoked via
`proc_open` from `api/dispatch-embeddings.php`, following the exact same
pattern `api/dispatch-spacy.php` already uses successfully on this same
host for the spaCy/RapidFuzz worker: a fresh Python process per call, model
loaded, one string encoded, JSON printed to stdout, process exits. This
costs a few extra seconds of `import torch` + model-load latency on every
call (the same tradeoff the spaCy worker already makes), but it means
**nothing sits resident between calls** -- the moment the process exits,
every byte of that ~850MB is released back to the account, so it can never
compete with spaCy (or anything else) for memory again.

The one-shot script also sets `TOKENIZERS_PARALLELISM=false`,
`OMP_NUM_THREADS=1`, `MKL_NUM_THREADS=1`, and calls
`torch.set_num_threads(1)`/`torch.set_num_interop_threads(1)` before loading
the model, keeping its brief CPU/thread footprint as small as possible on
top of the account's 2 allocated vCPU cores.

This worker is called far less often than spaCy: only when a Dispatch draft
is actually being generated (an admin's "Generate Draft"/"Regenerate Draft"
click, or a webhook-triggered auto-draft), and once more at
publish/edit time to cache the new translation's own embedding -- never on
every keystroke or page load.

## How "only the incoming commit needs encoding" actually works

The embedding worker itself is stateless: its only job per invocation is
turning one string into one vector. It never touches MySQL and never sees a
corpus of prior translations. The cache lives entirely on the PHP side, in
the `dispatch_translation_embeddings` table (one row per approved
translation, written by `pw_dispatch_update_translation_embedding()` at
publish/edit time). At draft-generation time, PHP encodes only the *one new
commit*, then computes cosine similarity against the cached corpus itself
(`pw_dispatch_cosine_similarity()`/`pw_dispatch_nearest_embedding_match()` in
`api/dispatch-embeddings.php`) -- no prior translation's text is ever sent
to the Python process.

## cPanel setup

If you already created a "Setup Python App" instance for this
(`dispatch-embeddings-app`) while this was still a persistent service:
**stop and delete that Passenger app now** -- cPanel's Setup Python App
page -> the app's row -> Stop / Delete. That's what was holding the ~850MB
resident. You do not need a Passenger app, an Application URL, or a
Passenger log file for the one-shot design; the existing venv it already
created for you is exactly what you keep and reuse below.

1. If you don't already have the venv (skip this if you already ran through
   the earlier Passenger setup and just want to keep its venv): create one
   via cPanel's "Setup Python App" **only to get a venv provisioned**, or
   directly via SSH/Terminal:

   ```bash
   python3.11 -m venv /home/rdy3i6my40b0/virtualenv/dispatch-embeddings-app/3.11
   source /home/rdy3i6my40b0/virtualenv/dispatch-embeddings-app/3.11/bin/activate
   python -m pip install --upgrade pip
   python -m pip install -r /home/rdy3i6my40b0/public_html/tools/requirements-dispatch-embeddings.txt
   ```

   (Keeping this isolated from the spaCy venv means a problem with the much
   heavier `torch`/`sentence-transformers` dependency chain can never affect
   the already-working spaCy worker.)

2. In `/home/rdy3i6my40b0/pantheonwars-secrets/config.php`, add:

   ```php
   define('DISPATCH_EMBEDDING_PYTHON_BIN', '/home/rdy3i6my40b0/virtualenv/dispatch-embeddings-app/3.11/bin/python');
   ```

   (Confirm the exact interpreter path with `ls
   /home/rdy3i6my40b0/virtualenv/dispatch-embeddings-app/3.11/bin/` --
   cPanel sometimes names the real binary `python3.11` with `python` as a
   symlink to it; either works as long as the path exists.)

3. Apply `sql/migration_dispatch_translation_embeddings.sql` in phpMyAdmin,
   if it hasn't already been applied.

4. Run the one-off backfill so existing approved translations get an
   embedding cached (safe to re-run; skips anything already cached):

   ```bash
   php tools/backfill-dispatch-embeddings.php
   ```

No web server, Application URL, Passenger log file, or shared-secret key is
needed anymore -- this worker never listens on anything and is never
network-reachable, exactly like the spaCy worker.

## Verifying real resource usage before trusting it

This account's documented constraints (see `CLAUDE.md`) are a 75 GiB
whole-account disk quota (shared with everything else -- not the bottleneck
here) and 2 allocated vCPU cores, with roughly 1.5GB of usable account RAM
in practice (confirmed empirically above, not documented anywhere on the
host itself). A `torch`-backed process is meaningfully heavier on both CPU
(BLAS thread init) and resident memory than the existing lightweight spaCy
worker, but only for the few seconds each call runs:

- Watch System Status's **Embedding Service** row (System > System Status >
  Security and Scripts) over a few days of normal admin use. It should stay
  Connected the vast majority of the time now, and a transient Disconnected
  reading (a single call that happened to hit the 10-second proc_open
  budget under load) should no longer correlate with spaCy going down too.
- If you want to double-check live memory behavior during a call, run
  `ps -u rdy3i6my40b0 -o pid,ppid,%cpu,%mem,rss,etime,cmd --sort=-rss` while
  triggering a "Generate Draft" -- you should see a `dispatch-embeddings.py`
  process appear briefly at a few hundred MB and then disappear completely,
  never staying resident between calls.

If it turns out even the brief one-shot spike is too much for this account,
disabling the whole feature costs nothing: remove
`DISPATCH_EMBEDDING_PYTHON_BIN` from the secrets config. No PHP code revert
or redeploy is needed -- every function in `api/dispatch-embeddings.php`
already fails open when that constant is undefined.
