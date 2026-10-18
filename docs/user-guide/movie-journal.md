# Personal movie and TV library (fork)

This fork adds a single-person library for maintaining trustworthy context used by an external model. It reuses Movary's configured TMDB client, image URL handling, login, CSRF protection, SQLite storage, and Docker infrastructure. It does not embed a recommendation model.

## Everyday use

Library opens as a poster grid with a remembered table alternative. Library contains confirmed watched titles only. Both views use the same search, sort, type, reaction, genre, person, language, and year filters; viewing status remains available to MCP without becoming a browsing control. Title pages show metadata, the current personal assessment, notes, scoped TV progress, and paginated viewing evidence. Genre and person links open filtered collections. Missing metadata leaves titles visible.

Add searches movies and TV by title, shows poster/year matches, and saves the reviewed form. Today is the editable default; Unknown is explicit. Reaction shortcuts are Loved, Liked, Okay, Disliked, and Strongly disliked, with free text still supported. Existing reaction words are preserved exactly; no numeric conversion is performed. Existing titles distinguish another viewing from editing an assessment. TV can use overall-show or season scope; a season response cannot replace the show assessment.

Wishlist contains explicitly saved Want to watch titles. Not interested is a separate view reachable from Settings and Wishlist. Both preferences are independent of viewing evidence; merely being unseen or recommended does not add a title to Wishlist. Taste opens readable cards in a modal sheet (a bottom sheet on mobile), separating explicit statements, approved model interpretations with supporting title IDs, and attributed imported context. Whitespace is normalized only for display; originals remain intact. Model proposals require approval; frequency is not treated as preference.

Import history retains batch recall with unknown dates by default, ambiguous matches shown for review, and exact previews before commit. Native Movary personal writers and integrations are read-only for migrated owners so they cannot maintain a competing record.

## Browse history

Add → Browse history (also in Settings) displays paginated movie or TV posters from TMDB Discover, sorted by average rating or popularity and limited to titles with at least 1,000 provider votes. These are recall aids, not personalized recommendations. Mark Watched and optionally choose a reaction; already-confirmed titles link to the library. Selections survive paging and list/type changes within the page, with an unsaved-change warning on navigation. Review up to 100 selected identities and their reactions before Save selected. Dates remain unknown, series completion is not inferred, and skipped cards create no record. Existing notes are preserved.

Catalog reads do not change personal records. Saving uses the existing confirm_seen service, exact preview/commit tokens, revision checks, checkpoints, and retry receipts. A successful batch clears its temporary selection. Use the Page field and Go (or Enter) to jump directly to pages 1–500. The URL retains the current page, media type, and list, so saving a batch and reloading return to the same catalog position. Bookmark that URL to resume later. Provider list ordering can change, so the page number does not identify a permanent set of titles. External CSV exports remain supported through reviewed reconciliation rather than the blocked legacy native importer.

Provider references: [Discover movies](https://developer.themoviedb.org/reference/discover-movie) and [Discover TV](https://developer.themoviedb.org/reference/discover-tv).

## Data and migration

Structured personal tables in the existing SQLite database are authoritative. Provider identity is `(media_type, tmdb_id)` within an owner's collection; stable local IDs preserve unresolved entries. Metadata, indexed facets/credited roles, state, viewing events, taste, audit records, and receipts are separate. Public refreshes don't change personal revisions or words. TV uses details and aggregate credits with creator/cast/crew roles and aggregate scope preserved.

Back up before migration. `bin/library-maintenance.php --user-id=<owner>` previews the exact canonical Markdown and taste sources; `--apply=<hash>` imports that reviewed source in one transaction, retaining immutable evidence. Seen imports as historical viewing evidence, not exactly one lifetime viewing. Native plays are not imported as additional events. Unique exact title/year metadata links can be proposed with `--match-exact` and committed using the returned review ID/token. Ambiguous or missing matches stay unresolved. `--enrich` refreshes resolved provider facts without adding watches.

Generated Markdown, CSV, and versioned JSON are portable exports. Full recovery requires SQLite, storage/migration evidence, the stable application secret, configuration, image digest, and fork source. Restore an isolated private copy and verify records, revisions, aliases, and constraints before deployment.

## Private MCP contract 1.0

The STDIO server is `php bin/journal-mcp.php --user-id=<owner>`, read-only by default. `--write` enables reviewed changes. SSH/OS process access authenticates the owner; the bridge keeps client STDIO alive through container restarts. This is not a public multi-user HTTP server.

| Tool | Purpose |
| --- | --- |
| get_context | Compact attributed taste, coverage, personal revision, and retrieval guidance. Taste pages contain 25 excerpts; use fullStatements and tasteOffset for more. |
| search_library | Search personal words and provider facets; 25 results by default, 100 maximum, offset/nextOffset pagination. |
| get_title | Rich title facts, exact personal state, scoped progress, provenance, metadata gaps, and a page of credits. |
| get_viewing_history | Paginated historical evidence, logged viewings, assessment changes, and scoped progress. |
| search_catalog | Provider candidates, keeping absent personal status unknown rather than explicitly unseen. |
| preview_changes | Exact reviewed proposals with a base revision and approval token; no personal mutation. |
| commit_changes | Commit the exact human-approved ID/token, reject stale proposals, and return a durable idempotent receipt. |

Changes support log_watch, edit_assessment, confirm_seen, set_interest, set_status, set_progress, resolve_identity, and save_taste. Use a stable requestId when retrying a preview. A logged viewing is an event; an assessment edit creates no viewing. Historical confirm_seen reconciles evidence without inventing a lifetime count. Approved interpretations require supporting confirmed title IDs and remain attributed to a model. Temporary conversation constraints are saved only when explicitly requested.

Legacy library_context/movie_context, preview_watched_titles/preview_watched_movies, review_watched_titles/review_watched_movies, and commit_watched_titles/commit_watched_movies remain compatible adapters. No public tunnel, model API key, or additional model subscription is required.

TMDB resources: [Movie details](https://developer.themoviedb.org/reference/movie-details), [TV details](https://developer.themoviedb.org/reference/tv-series-details), [TV aggregate credits](https://developer.themoviedb.org/reference/tv-series-aggregate-credits), [Movie keywords](https://developer.themoviedb.org/reference/movie-keywords).
