# Vendored world map SVG

`world-map.svg` is used unmodified for the Visitor Statistics "Traffic by
Country" map view (admin console). Each country is a separate `<path>`
element with its ISO 3166-1 alpha-2 code (lowercase) as its `id` attribute,
which `admin/index.html` reads directly to recolor by visit volume at
runtime -- the vendored file itself is never edited.

- Source: `flekschas/simple-world-map` on GitHub
  (`https://github.com/flekschas/simple-world-map`)
- Original authors: Al MacDonald (author), Fritz Lekschas (editor)
- License: Creative Commons Attribution-ShareAlike 3.0 Unported (CC BY-SA 3.0)
  -- `https://creativecommons.org/licenses/by-sa/3.0/`

Attribution is preserved here per the license terms. Do not replace this file
with a modified/re-licensed version without re-checking CC BY-SA 3.0's
share-alike requirements.
