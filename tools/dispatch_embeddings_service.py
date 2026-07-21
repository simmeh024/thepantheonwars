"""
Persistent local sentence-embedding service for Dispatch Translation semantic
similarity. See docs/dispatch-embeddings.md for the cPanel "Setup Python App"
setup this file is meant to run under.

Deliberately kept as a persistent Flask/Passenger app rather than a one-shot
script invoked via proc_open (the pattern tools/dispatch-nlp.py uses): loading
sentence-transformers pulls in torch, whose import cost alone is commonly
1-3 seconds even before any model load. Paying that on every single draft
generation would routinely exceed api/dispatch-spacy.php's existing
6-second proc_open budget. Loading the model once here, at process start,
means every request after that only pays the actual encode cost --
milliseconds for a commit-message-length string on CPU.

This service is intentionally simple and stateless: it knows nothing about
Dispatches, MySQL, or approved translations. Its only job is turning one
string into one vector. PHP (api/dispatch-embeddings.php) owns the corpus of
cached approved-translation embeddings and computes cosine similarity itself;
this keeps "only the incoming commit needs encoding" literally true and never
hands a corpus of prior translation text to this process.
"""

import os

from flask import Flask, jsonify, request
from sentence_transformers import SentenceTransformer

MODEL_NAME = "all-MiniLM-L6-v2"
MAX_TEXT_CHARS = 4000

# cPanel's "Setup Python App" exposes this service at a real URL path routed
# through the account's own domain (there is no raw loopback-port access the
# way a self-managed server would offer) -- so unlike the spaCy worker, which
# never leaves the process, this endpoint is reachable by anyone who finds
# the URL unless it checks a shared secret. Set via the Passenger app's
# "Environment variables" section (see docs/dispatch-embeddings.md); if left
# unset, no check is enforced -- convenient for local development, but this
# must always be set in production.
EXPECTED_KEY = os.environ.get("DISPATCH_EMBEDDING_SERVICE_KEY", "")

app = Flask(__name__)

# Loaded once, at process start -- this is the whole point of running as a
# persistent service instead of a one-shot script.
_model = SentenceTransformer(MODEL_NAME)


@app.route("/health", methods=["GET"])
def health():
    return jsonify({"ok": True, "model": MODEL_NAME})


@app.route("/encode", methods=["POST"])
def encode():
    if EXPECTED_KEY and request.headers.get("X-Dispatch-Key", "") != EXPECTED_KEY:
        return jsonify({"ok": False, "error": "Unauthorized."}), 401

    payload = request.get_json(silent=True)
    if not isinstance(payload, dict):
        return jsonify({"ok": False, "error": "Expected a JSON object."}), 400

    text = payload.get("text")
    if not isinstance(text, str) or text.strip() == "":
        return jsonify({"ok": False, "error": "Missing or empty 'text'."}), 400

    text = text[:MAX_TEXT_CHARS]
    vector = _model.encode(text, normalize_embeddings=True)

    return jsonify({
        "ok": True,
        "model": MODEL_NAME,
        # normalize_embeddings=True means these are already unit vectors, so
        # PHP's cosine-similarity lookup can use a plain dot product.
        "embedding": vector.tolist(),
    })


if __name__ == "__main__":
    # Local development only. In production this runs under cPanel's
    # Passenger/WSGI host, which imports `app` directly (see
    # docs/dispatch-embeddings.md) and never executes this block.
    app.run(host="127.0.0.1", port=5001)
