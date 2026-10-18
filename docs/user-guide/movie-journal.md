# Personal movie and TV library (fork)

This fork adds a single-person library for maintaining trustworthy context used by an external model. It reuses Movary's configured TMDB client, image URL handling, login, CSRF protection, SQLite storage, and Docker infrastructure. It does not embed a recommendation model.

## Everyday use

Library opens as a poster grid with a remembered table alternative. Both views use the same search, sort, status, type, reaction, genre, person, language, and year filters. Title pages show metadata, the current personal assessment, notes, scoped TV progress, and paginated viewing evidence. Genre and person links open filtered collections. Missing metadata leaves titles visible.

Log a watch searches movies and TV by title, shows poster/year matches, and saves the reviewed form. Today is the editable default; Unknown is explicit. Qualitative reactions support preset shortcuts and free text, without numeric ratings. Existing titles distinguish another viewing from editing an assessment. TV can use overall-show or season scope; a season response cannot replace the show assessment.

Watchlist preferences (Want to watch / Not interested) are independent of viewing evidence. Taste separates explicit statements, approved model interpretations with supporting title IDs, and attributed imported context. Model proposals require approval; frequency is not treated as preference.

Import history retains batch recall with unknown dates by default, ambiguous matches shown for review, and exact previews before commit. Native Movary personal writers and integrations are read-only for migrated owners so they cannot maintain a competing record.

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
