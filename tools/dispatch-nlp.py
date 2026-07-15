#!/usr/bin/env python3
"""Small, JSON-only local language worker for reader-facing Dispatch drafts.

The worker never writes to the database, never fetches a network resource, and
does not generate prose. PHP remains responsible for the deterministic draft
templates and uses this process only to obtain safe spaCy/RapidFuzz hints.
"""

from __future__ import annotations

import json
import os
import re
import sys
from typing import Any


MAX_INPUT_CHARS = 24000
MAX_ITEMS = 6
GENERIC_TERMS = {
    "change", "changes", "update", "updates", "work", "project", "site",
    "system", "feature", "features", "thing", "things", "way", "area",
}
DOMAIN_PROFILES = {
    "security": "sign in account privacy permissions authentication protection browser security",
    "database": "database query index migration storage data performance",
    "performance": "speed loading cache bandwidth page response efficient delivery",
    "community": "forum member profile notification discussion moderation community",
    "content": "book world lore story character map reader translation dispatch",
    "interface": "interface navigation layout card button modal image responsive accessibility",
    "operations": "hosting deployment monitoring backup webhook cron system administration",
}


def clean_phrase(value: str) -> str:
    value = re.sub(r"\s+", " ", value).strip(" .,:;–—-\t\n")
    value = re.sub(r"^(?:the|a|an)\s+", "", value, flags=re.IGNORECASE)
    if len(value) < 3 or len(value) > 100:
        return ""
    if value.lower() in GENERIC_TERMS:
        return ""
    return value


def unique(values: list[str]) -> list[str]:
    result: list[str] = []
    seen: set[str] = set()
    for value in values:
        candidate = clean_phrase(value)
        key = candidate.lower()
        if candidate and key not in seen:
            seen.add(key)
            result.append(candidate)
        if len(result) >= MAX_ITEMS:
            break
    return result


def normalized_text(value: str) -> str:
    """Keep fuzzy comparisons bounded to ordinary reader-facing characters."""
    return re.sub(r"\s+", " ", re.sub(r"[^a-z0-9 ]+", " ", value.lower())).strip()


def lexical_similarity(left: str, right: str, fuzz: Any) -> float:
    """A conservative score for local wording and concept comparison."""
    left = normalized_text(left)
    right = normalized_text(right)
    if len(left) < 4 or len(right) < 4:
        return 0.0
    if left in right or right in left:
        return 100.0
    return max(
        float(fuzz.ratio(left, right)),
        float(fuzz.token_set_ratio(left, right)),
    )


def reviewed_fuzzy_concept(text: str, values: Any, fuzz: Any) -> dict[str, Any]:
    """Return an approved concept id only; PHP owns the corresponding prose."""
    if not isinstance(values, list):
        return {}
    matches: dict[str, float] = {}
    for item in values[:80]:
        if not isinstance(item, dict):
            continue
        concept_id = item.get("id")
        aliases = item.get("aliases")
        if not isinstance(concept_id, str) or not isinstance(aliases, list):
            continue
        for alias in aliases[:12]:
            if not isinstance(alias, str):
                continue
            score = lexical_similarity(text, alias, fuzz)
            matches[concept_id] = max(matches.get(concept_id, 0.0), score)
    ranked = sorted(matches.items(), key=lambda item: item[1], reverse=True)
    if not ranked:
        return {}
    concept_id, score = ranked[0]
    # An update that strongly names two different reviewed concepts should be
    # handled by the established PHP rules or an editor, not an arbitrary tie.
    if len(ranked) > 1 and score - ranked[1][1] < 4.0:
        return {}
    best = {"id": concept_id, "score": int(round(min(score, 100.0)))}
    # This deliberately rejects weak lexical resemblance. PHP applies a second
    # allow-list validation before a concept can influence a private draft.
    return best if int(best.get("score", 0)) >= 92 else {}


def nearest_fuzzy_similarity(text: str, values: Any, fuzz: Any) -> float:
    """Return only a score, never another translation's contents."""
    if not isinstance(values, list):
        return 0.0
    best = 0.0
    for value in values[:8]:
        if isinstance(value, str) and value.strip():
            best = max(best, lexical_similarity(text, value[:900], fuzz))
    return round(max(0.0, min(best, 100.0)), 1)


def semantic_domains(doc: Any, nlp: Any) -> list[dict[str, Any]]:
    """Return only strong vector matches; rules remain PHP's source of truth."""
    if nlp.vocab.vectors_length == 0 or not doc.has_vector or doc.vector_norm == 0:
        return []
    scores: list[dict[str, Any]] = []
    for name, profile in DOMAIN_PROFILES.items():
        profile_doc = nlp.make_doc(profile)
        if not profile_doc.has_vector or profile_doc.vector_norm == 0:
            continue
        score = float(doc.similarity(profile_doc))
        if score >= 0.58:
            scores.append({"name": name, "score": round(score, 3)})
    return sorted(scores, key=lambda item: item["score"], reverse=True)[:2]


def nearest_translation_similarity(doc: Any, nlp: Any, values: Any) -> float:
    """Return only the strongest local vector similarity, never source text."""
    if nlp.vocab.vectors_length == 0 or not doc.has_vector or doc.vector_norm == 0:
        return 0.0
    if not isinstance(values, list):
        return 0.0
    candidates = [str(value)[:900] for value in values[:8] if isinstance(value, str) and value.strip()]
    best = 0.0
    for candidate in nlp.pipe(candidates, disable=["parser", "ner"]):
        if candidate.has_vector and candidate.vector_norm:
            best = max(best, float(doc.similarity(candidate)))
    return round(max(0.0, min(best, 1.0)), 3)


def main() -> int:
    try:
        payload = json.loads(sys.stdin.read(MAX_INPUT_CHARS + 1))
        if not isinstance(payload, dict):
            raise ValueError("Expected an object.")
        subject = str(payload.get("subject", ""))[:4000]
        body = str(payload.get("body", ""))[:8000]
        recent_translations = payload.get("recent_translations", [])
        health_check = bool(payload.get("health"))
        text = (subject + ". " + body).strip()
        if not text and not health_check:
            raise ValueError("No dispatch text supplied.")

        import spacy
        try:
            from rapidfuzz import fuzz
        except ImportError:
            # RapidFuzz is an optional advisory layer. Keep the established
            # spaCy response usable if an older virtual environment has not
            # installed the new package yet.
            fuzz = None

        model_name = os.environ.get("PW_SPACY_MODEL", "en_core_web_md")
        nlp = spacy.load(model_name, disable=["textcat"])
        if health_check:
            print(json.dumps({"ok": True, "model": model_name, "health": True}))
            return 0
        doc = nlp(text)

        actions = unique([
            token.lemma_.lower()
            for token in doc
            if token.pos_ == "VERB" and token.is_alpha and len(token.lemma_) > 2
        ])
        phrases = unique([
            chunk.text
            for chunk in doc.noun_chunks
            if not any(token.like_url or token.like_email for token in chunk)
        ])
        entities = unique([
            entity.text
            for entity in doc.ents
            if entity.label_ in {"ORG", "PRODUCT", "PERSON", "GPE", "EVENT", "WORK_OF_ART"}
        ])
        acronyms = unique([
            token.text
            for token in doc
            if token.text.isupper() and token.is_alpha and 2 <= len(token.text) <= 16
        ])

        result: dict[str, Any] = {
            "ok": True,
            "model": model_name,
            "actions": actions,
            "phrases": phrases,
            "entities": unique(entities + acronyms),
            "semantic_domains": semantic_domains(doc, nlp),
            "nearest_similarity": nearest_translation_similarity(doc, nlp, recent_translations),
            "fuzzy_concept": (
                reviewed_fuzzy_concept(text, payload.get("reviewed_concepts", []), fuzz)
                if fuzz is not None else {}
            ),
            "nearest_fuzzy_similarity": (
                nearest_fuzzy_similarity(text, recent_translations, fuzz)
                if fuzz is not None else 0.0
            ),
        }
        print(json.dumps(result, ensure_ascii=False))
        return 0
    except Exception as error:  # Caller treats any failure as an optional fallback.
        print(json.dumps({"ok": False, "error": str(error)[:160]}))
        return 0


if __name__ == "__main__":
    raise SystemExit(main())
