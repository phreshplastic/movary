# Watched library (personal fork)

Open **Library** to search confirmed watched movies and TV shows, exact reactions, and notes. The type and status filters keep movie/TV records and confirmed/unconfirmed viewing distinct. Recommendations are made in a connected chat client using this stored context.

Choose **Add watched**, select Movies or TV shows, and paste one title per line. Include years when you know them. Pick the correct matches, enter optional reactions/dates/notes in normal fields, review the exact changes, then select **Save changes**. A pipe-separated paste format remains optional. Original reaction words and unknown dates are preserved.

TV records are show-level: “watched” doesn't infer that every season or episode is finished. Use notes for the portion you remember watching. TV favorites or taste mentions are never imported as watches. Upstream Movary is movie-only; this fork keeps TV in the canonical library and never writes TV IDs into movie plays.

**Export → Context for chat** includes durable taste and complete canonical Markdown. **CSV** includes Type, Title, Year, Status, Reaction, Watch date, and Evidence / note. Exports require login. The old `/journal` URL still opens Add watched. Original Movary pages remain available through **Settings → Advanced movie tools**.

## Storage and recovery

The canonical `WATCH_HISTORY.md` and `TASTE_PROFILE.md` live in `storage/.journal/<user-id>/`, outside the public storage link. The default nginx configuration also denies dot-file paths. Initialize that directory from an explicitly confirmed source before opening Journal; the feature never bootstraps watches from search or the movie catalog. Movie rows keep their original table. TV rows use a trailing `## TV shows` table in that same file, created only by an explicitly reviewed TV save. Local copies elsewhere are snapshots, not another editable database.

Every commit requires a reviewed draft hash, a matching source version, and unchanged Movary plays. Before changing plays, the SQLite-only checkpoint uses SQLite's online backup API and preserves the canonical Markdown. Files use private directory/file permissions. The ordinary full-instance backup is still needed for all storage, configuration/secrets, and application source/version. Checkpoints are not complete disaster-recovery archives.

Plays are verified inside a database transaction. An interrupted save retains its approved text and resumes without incrementing plays again. Repeating a completed commit returns its saved receipt. Unknown source dates never add another play to a film that already has a dated watch. Other changes invalidate the review rather than overwriting them.

This first version uses SQLite checkpoints; MySQL commits stop before writing. No database migrations, authentication changes, or dependency additions are required.

## Private MCP connection

`bin/journal-mcp.php --user-id=<owner-id>` exposes `library_context` over newline-delimited STDIO using MCP protocol 2025-11-25. The parent process's OS/SSH access is the authentication boundary; there is no HTTP listener or unauthenticated network endpoint. Pin the owner in the operator's client configuration. Access is read-only by default.

An explicitly enabled `--write` connection also exposes `preview_watched_titles`, `review_watched_titles`, and `commit_watched_titles`. Preview accepts `mediaType` (`movie` by default, or `tv`); review accepts optional row details (`reaction`, `date`, `note`). Context returns `films`, `shows`, and `taste`. The older movie-named calls remain callable for compatibility. They call the same service as the browser. The model must show the exact preview and obtain user approval before committing the returned hash. A hash binds the data, not the human's identity or consent; client-side tool approvals remain necessary. No SQL, arbitrary files, tokens, or account-administration operations are exposed.

On a trusted private host, an MCP client can launch this command through SSH and `docker exec -i`. The SSH key is never sent to the model. Remove `--write` for a read-only connection or revoke the SSH key to remove host access. The raw SSH account and Docker access are powerful operator privileges; don't share that connection with untrusted users or workspaces.

For clients that keep STDIO open, use the optional Python-standard-library launcher `bin/journal-ssh-bridge.py --host <ssh-host> --identity <key-path> --container <name> --owner <id> [--write]`. It starts a fresh private remote process per request, so a container restart does not permanently close the local MCP connection. Drafts and receipts persist in the app. It returns a temporary error when the app is unavailable and never automatically retries writes. Client-launcher checks run with `python3 -m unittest discover -s tests/client`.

For ChatGPT, an outbound [Secure MCP Tunnel](https://developers.openai.com/api/docs/guides/secure-mcp-tunnels) can launch the STDIO process inside the private network. That connection is not configured by this feature. Restrict tunnel/workspace access and complete the chosen product's connection authorization before enabling writes. A future public HTTP transport needs a separately reviewed OAuth design; don't expose this process or the private app through an unauthenticated public tunnel.

## Verified metadata sources

[Movary FAQ](https://docs.movary.org/faq/) documents the upstream movie-only scope. [TMDB TV search](https://developer.themoviedb.org/reference/search-tv) documents `/search/tv` and `first_air_date_year`. TV matching uses the existing configured TMDB client/key.
