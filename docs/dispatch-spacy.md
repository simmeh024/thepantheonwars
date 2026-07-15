# Optional spaCy enrichment for Dispatch drafts

The Dispatch translator remains rule-based and deterministic. spaCy is an
optional local language-analysis step: it extracts verbs, noun phrases, and
named terms so a vague raw commit can retain its useful reader-facing subject.
It does not generate prose, call an AI, or send commit text outside the hosting
account. If the worker is unavailable or exceeds its 4 second budget, PHP
uses the existing rule-only translation without changing auto-publication.

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

4. Regenerate one medium or low-confidence Dispatch draft. It should retain
   reader-facing named terms more naturally. If the constants are removed or
   the virtual environment is unavailable, the site safely returns to the
   original formatter without an error or a changed publication decision.
