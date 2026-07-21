"""One-shot local sentence-embedding worker for Dispatch Translation semantic
similarity. See docs/dispatch-embeddings.md.

Deliberately NOT a persistent Flask/Passenger service (an earlier version of
this file was). Live production evidence on this shared-hosting account: a
persistent sentence-transformers/torch process sat at ~850MB resident memory
just from loading the model, on an account with roughly 1.5GB total RAM.
Requesting an encode pushed the account over its CloudLinux memory ceiling,
and the OOM killer took out the (much lighter) spaCy Passenger worker every
time -- confirmed by `ps -u ... --sort=-rss` showing the embeddings process
alone at ~852MB RSS, and by spaCy reliably disconnecting immediately after
any live translation that invoked the embeddings workflow.

This file now follows tools/dispatch-nlp.py's exact shape instead: a fresh
Python process per call, invoked via proc_open from api/dispatch-embeddings.php,
paying the torch import + model load cost (a few seconds) on every call but
releasing every byte of that memory the moment the process exits. On this
host, a few extra seconds of latency on an admin-triggered or webhook-
triggered draft generation is a far better trade than an ~850MB process
sitting resident 24/7 and periodically taking spaCy down with it.
"""

from __future__ import annotations

import json
import os
import sys

# Keep this one-shot process's own CPU/memory footprint as small as possible
# -- these must be set before numpy/torch are imported. Fewer BLAS/OpenMP
# threads means less thread-stack memory and no risk of this short-lived
# process briefly grabbing more of the account's 2 allocated vCPUs than a
# single commit-message-length encode actually needs.
os.environ.setdefault("TOKENIZERS_PARALLELISM", "false")
os.environ.setdefault("OMP_NUM_THREADS", "1")
os.environ.setdefault("MKL_NUM_THREADS", "1")

MODEL_NAME = "all-MiniLM-L6-v2"
MAX_TEXT_CHARS = 4000


def main() -> int:
    try:
        payload = json.loads(sys.stdin.read(MAX_TEXT_CHARS + 4000))
        if not isinstance(payload, dict):
            raise ValueError("Expected an object.")

        health_check = bool(payload.get("health"))
        text = str(payload.get("text", ""))[:MAX_TEXT_CHARS].strip()
        if not text and not health_check:
            raise ValueError("Missing or empty 'text'.")

        import torch
        from sentence_transformers import SentenceTransformer

        torch.set_num_threads(1)
        torch.set_num_interop_threads(1)

        model = SentenceTransformer(MODEL_NAME)
        model.eval()

        if health_check:
            print(json.dumps({"ok": True, "model": MODEL_NAME, "health": True}))
            return 0

        with torch.inference_mode():
            vector = model.encode(text, normalize_embeddings=True, show_progress_bar=False)

        print(json.dumps({
            "ok": True,
            "model": MODEL_NAME,
            # normalize_embeddings=True means these are already unit vectors,
            # so PHP's cosine-similarity lookup can use a plain dot product.
            "embedding": vector.tolist(),
        }, ensure_ascii=False))
        return 0
    except Exception as error:  # Caller treats any failure as an optional fallback.
        print(json.dumps({"ok": False, "error": str(error)[:160]}))
        return 0


if __name__ == "__main__":
    raise SystemExit(main())
