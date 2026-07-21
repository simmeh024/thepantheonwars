# Optional local language enrichment for Dispatch drafts

The Dispatch translator remains rule-based and deterministic. spaCy is an
optional local language-analysis step: it extracts verbs, noun phrases, and
named terms, plus conservative vector-based domain hints so a vague raw commit
can retain its useful reader-facing subject. RapidFuzz is an optional local
lexical-matching step in the same worker. It compares a commit against a small,
reviewed concept library and detects close wording against recent approved
translations.

The worker returns only a concept id and bounded scores to PHP; it never returns
another translation's text or writes reader-facing prose. PHP validates the
concept id against its own allow-list before using the corresponding approved
reader-safe object. A fuzzy concept match can shape a private draft, but it
always requires editorial review and cannot cause auto-publication. spaCy and
RapidFuzz do not call an AI or send commit text outside the hosting account. If
the worker is unavailable or exceeds its 6-second budget, PHP uses the existing
rule-only translation without changing auto-publication.

A second, independent local worker (`docs/dispatch-embeddings.md`) adds real
sentence-embedding semantic similarity alongside spaCy/RapidFuzz -- same
fail-open contract, same "PHP owns all reader-facing prose" rule, different
venv, different signal. It contributes a graded confidence signal and a
read-only "Similar past Dispatch" editor reference; it never writes a draft.

## End-to-end translation flow

This is the current implementation flow, suitable as the source for a visual
flowchart. The PHP formatter is the authority for every reader-facing sentence;
both Python workers can only return bounded, local analysis signals.

```mermaid
flowchart TD
    A[GitHub webhook, re-sync, or admin Generate/Regenerate Draft] --> B[Read raw subject, body, tag, and safe diff aggregate]
    B --> C[pw_dispatch_draft_options_for_dispatch]
    C --> C1[Load safe diff context]
    C --> C2[Load up to 20 recent approved translations]

    C --> D{spaCy/RapidFuzz worker available?}
    D -- No, error, or timeout --> D1[No linguistic hints]
    D -- Yes --> D2[spaCy: verbs, noun phrases, entities, bounded semantic-domain hint]
    D2 --> D3[RapidFuzz: compare vs reviewed concept aliases + up to 8 recent translations]
    D3 --> D4{Reviewed concept match, score >= 92, 4pt lead?}
    D4 -- Yes --> D5[Return concept id + score only; PHP validates id against allow-list]
    D4 -- No --> D6[Return bounded similarity score only]

    C --> E{Commit subject or body present?}
    E -- Yes --> E1[Encode current commit text --\none-shot proc_open call to tools/dispatch-embeddings.py]
    E1 --> E2{Encode succeeded within budget?}
    E2 -- No, timeout, or unavailable --> E3[No embedding signal -- fails open]
    E2 -- Yes --> E4[pw_dispatch_nearest_embedding_match:\ncosine similarity computed in PHP against\ncached approved-translation vectors]
    E4 --> E5{Best cached match score >= 0.75?}
    E5 -- No --> E3
    E5 -- Yes --> E6[best_semantic_match: dispatch id,\nsubject, translation text, score]

    D1 --> F[Build reader-safe BH-4 draft from PHP templates]
    D5 --> F
    D6 --> F
    E3 --> F
    E6 --> F

    F --> M2[Append separate safe file-scope paragraph when available]
    M2 --> N[Score explainable evidence]
    N --> N1["semantic_context (5pt) = spaCy domain hint\nOR embedding score >= 0.75 -- either alone\nsatisfies it, the two are not additive"]
    N1 --> O{High: at least 65% plus independent signals?}
    O -- No --> P[Create private draft for editor review]
    O -- Yes --> Q{RapidFuzz reviewed concept used?}
    Q -- Yes --> P
    Q -- No --> R[(Auto-publish to dispatch_translations)]
    P --> S["Editor review queue -- shows draft plus a\nread-only 'Similar past Dispatch' panel when\nE6 fired (copy/adapt only, never auto-applied)"]
    S --> S1[Editor approves, edits, publishes, or regenerates]
    S1 --> R
    R --> R1[pw_dispatch_update_translation_embedding:\nencode the newly-approved translation text,\nupsert into dispatch_translation_embeddings]
    R1 --> T[Public Dispatch shows end-user translation first]
```

The embedding lookup (E1-E6) and the spaCy/RapidFuzz lookup (D1-D6) run
independently off the same draft-options step and never depend on each
other's output -- either, both, or neither can contribute to a given draft.
The corpus the embedding lookup compares against only grows at R1, right
after a translation actually gets approved (whether by auto-publish or by an
editor's manual save) -- so the very first commit of a kind has nothing to
match against, but every similar commit after it does.

### What each stage is allowed to do

- **Input and scope:** commit subject/body are used only inside the hosting
  account. Stored diff context contains only a file count and approved product
  area/type labels—never raw paths, diffs, or source code.
- **Deterministic PHP planner:** identifies explicit local facts and chooses the
  approved BH-4 wording template. It remains the sole source of public prose.
- **spaCy:** supplies local verbs, noun phrases, named terms, semantic-domain
  hints, and similarity scores. These are supporting context only.
- **RapidFuzz:** runs locally in the same worker. It compares current wording
  with the reviewed aliases in `tools/dispatch-fuzzy-concepts.json` and checks
  textual similarity against recent approved translations. A concept is usable
  only when its score is at least 92 and at least four points ahead of the next
  candidate. PHP then revalidates the returned id against its own allow-list.
  It never returns another translation's prose to PHP.
- **Embeddings worker (separate, `docs/dispatch-embeddings.md`):** encodes
  only the current commit's text via a one-shot `proc_open` process. PHP
  owns the cached corpus (`dispatch_translation_embeddings`) and computes
  cosine similarity itself -- the worker never sees, stores, or returns
  another translation's text. A match at or above 0.75 contributes to the
  same `semantic_context` evidence slot spaCy's domain hint feeds (either
  alone satisfies it) and surfaces as a read-only editor reference; it never
  writes to a draft.
- **Safety gate:** a validated RapidFuzz concept can improve a private draft but
  always sets `requires_editor_review`; it cannot auto-publish. A missing,
  failing, or slow worker produces the normal deterministic result instead.
  The same fail-open contract applies to the embeddings worker independently.
- **Output:** the public page displays an approved end-user explanation first;
  the original technical record remains separately available through BH-4's
  technical analysis.

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

The visible **Confidence score** help control in Admin → Dispatch Translations
uses these exact weights: recognized subject **25**, reviewed reader-safe
dictionary **10**, commit intent **30**, body context **10**, safe changed-file
scope **20**, and optional semantic context **5**. Two independent deterministic
formatter rules establish a 65% floor for older records that do not have stored
diff context. High confidence requires at least 65% and independent evidence;
otherwise the draft is medium or low confidence and stays in the review queue.

The reviewed RapidFuzz concept library is stored in
`tools/dispatch-fuzzy-concepts.json`. It contains ids, aliases, and PHP-owned
reader-safe objects for narrow, recurring project concepts. Add an alias only
when it is a genuine synonym or likely typo variation of a reviewed concept;
do not use it as a broad keyword list. Run `php tools/test-dispatch-translator.php`
after changing this library or the matching threshold.

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
