# Stylesheet source map

`style.css` is the only stylesheet linked by pages. It imports the files below in
this exact order; do not reorder them, because their order preserves the established
CSS cascade without a build step.

- `tokens.css` — theme, spacing, radius, shadow, motion, and status custom properties.
- `base.css` — reset, document defaults, typography, and global browser chrome.
- `layout.css` — shared container, action, and ornamental framing primitives.
- `components.css` — shared header, notification, hero, card, media, and footer UI.
- `content.css` — public editorial pages: books, worlds, lore, news, and quizzes.
- `community.css` — authentication, member profiles, forum/community, dispatch, and metrics pages.
- `admin.css` — the admin console, operational dashboards, modals, visitor statistics, and controls.

When changing a source file, bump both the `style.css?v=N` page reference and every
import query in `style.css`. The site has no bundling step: preserving this file
order and versioning is what keeps production rendering deterministic.
