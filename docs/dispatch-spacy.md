# Optional spaCy enrichment for Dispatch drafts

The Dispatch translator remains rule-based and deterministic. spaCy is an
optional local language-analysis step: it extracts verbs, noun phrases, and
named terms, plus conservative vector-based domain hints so a vague raw commit
can retain its useful reader-facing subject. For repetition avoidance it may
compare a new commit against a maximum of eight recent approved translations,
but returns only the strongest similarity score to PHP, never another
translation's text. It does not generate prose, call an AI, or send commit text
outside the hosting account. If the worker is unavailable or exceeds its 6
second budget, PHP uses the existing rule-only translation without changing
auto-publication.

## Draft planning and confidence

PHP builds a reader-safe plan before writing each draft: intent, affected
domain, allow-listed changed-file scope, and optional semantic support. It then
uses a domain-specific BH-4 voice for security, database, performance,
community, content, interface, and operations work. No raw paths, diffs, or
commit-body copy is exposed in the public draft.

Confidence is evidence-based and explainable. Recognized subject, commit
intent, body context, changed-file scope, and semantic context contribute to a
score; vector context is capped at a small supporting weight and can never make
an unsupported draft high confidence on its own. Vector domains resolve only a
genuinely unclassified commit and never replace an explicit local domain cue.
Named worlds, maps, districts, books, and worldbuilding scope are decisive
content cues before broad technical terms are evaluated.

World-release records headed by `Unlock <World>` have an additional deterministic
planner. It uses only stated facts—world name, full map, clickable district count,
and landmarks—to produce a concrete public update rather than generic content
language.

For every domain, the formatter preserves an action-led source title before it
applies a reader-safe replacement. If a replacement becomes a noun phrase, the
original action is retained and the new phrase becomes its object; this prevents
phrases such as “made unlock…” or “fixed fix…” across the engine. Run
`php tools/test-dispatch-translator.php` on the server after translator changes.
High confidence still requires multiple independent signals, preserving the
existing auto-publication safety gate.

## Reader-safe terminology dictionary

Before the generic action templates run, PHP applies a narrow, reviewed
dictionary of recurring project terminology. It converts known technical commit
subjects into reader-safe objects and gives recurring product areas (accounts,
navigation, analytics, privacy, security, backups, performance, styling, and
Dispatch tooling) a concise BH-4 explanation. These entries are deterministic:
they do not infer facts from an external service or write raw code details into
the public update. Add a dictionary entry only for a specific, recurring commit
pattern and add a matching regression case whenever it protects against a
previously observed awkward phrase. A matched entry is also worth 10 points of
explicit confidence evidence. It raises the score only for reviewed project
vocabulary and does not replace the existing two-signal high-confidence gate.
For legacy `Area: change` titles, the same reviewed dictionary also checks the
complete title after the formatter has separated its area prefix; only the
first, most specific scoped match is used.

When a safe changed-file aggregate is available, the formatter adds it as a
separate final paragraph: `Total files edited: N in <allow-listed scope>.`
This keeps the main BH-4 explanation readable while retaining a concise,
privacy-safe sense of the work's scope.

## cPanel setup (one time)

1. In cPanel, open **Setup Python App** and create a Python **3.11 or 3.12**
   application outside `public_html`.
2. In the virtual environment's terminal, install the committed dependencies:

   ```bash
   python -m pip install --upgrade pip
   python -m pip install -r /home/rdy3i6my40b0/public_html/tools/requirements-dispatch-nlp.txt
   ```

3. In `/home/rdy3i6my40b0/pantheonwars-secrets/config.php`, add the venv's
   interpreter path (adjust the app name/path shown by cPanel):

   ```php
   define('SPACY_PYTHON_BIN', '/home/rdy3i6my40b0/virtualenv/dispatch-nlp/3.11/bin/python');
   define('SPACY_MODEL', 'en_core_web_sm');
   ```

4. Regenerate one medium or low-confidence Dispatch draft. Keep the small model
   as the production default: the deterministic PHP planner remains responsible
   for reader-facing wording, while spaCy supplies only bounded local context.
   The medium model is an optional experiment for vector-based domain hints; it
   is slower and should be kept only if a measured review demonstrates a benefit.
   If the constants are removed or
   the virtual environment is unavailable, the site safely returns to the
   original formatter without an error or a changed publication decision.
