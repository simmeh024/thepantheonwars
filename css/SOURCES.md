# Stylesheet source map

`public.css`, `community-bundle.css`, and `admin-bundle.css` are the page
entrypoints. Each imports only the source files required for that audience, in the
order below; do not reorder them, because their order preserves the established CSS
cascade without a build step. `style.css` remains a full compatibility bundle for
legacy or external pages.

- `tokens.css` — theme, spacing, radius, shadow, motion, and status custom properties.
- `base.css` — reset, document defaults, typography, and global browser chrome.
- `layout.css` — shared container, action, and ornamental framing primitives.
- `components.css` — shared header, notification, hero, card, media, and footer UI.
- `content.css` — public editorial pages: books, worlds, lore, news, and quizzes.
- `community.css` — authentication, member profiles, forum/community, dispatch, and metrics pages.
- `admin.css` — the admin console, operational dashboards, modals, visitor statistics, and controls.

When changing a source file, bump the active bundle `?v=N` page references and every
import query in all four entrypoints. The site has no bundling step: preserving file
order and versioning is what keeps production rendering deterministic.

## Bundle routing

- `public.css` — public editorial, world, book, privacy, soundtrack, news, quiz,
  and notification pages. It never imports `admin.css`.
- `community-bundle.css` — forum, member, profile, dispatch, and metrics pages.
- `admin-bundle.css` — the admin console. It imports the community source only for
  shared avatar/profile primitives, never the public editorial `content.css` source.
