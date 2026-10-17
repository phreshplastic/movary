# Movie journal (personal fork)

Open **Journal** in the navigation bar. Paste confirmed watched titles, optionally using `Title (year) | reaction | YYYY-MM-DD | note`, and confirm that you watched them. Choose the correct movie versions, review the exact journal and Movary changes, then select **Save to my journal**. No terminal commands or numeric rating mapping are required.

The journal stores one row per film/version, preserving exact reaction words and unknown dates. An explicitly recalled watch can change an existing suggestion to Seen, but searches and suggestions cannot do that by themselves. If an existing reaction changes, the review shows both values. Conflicting known dates need separate review rather than inferring a rewatch.

**Download movie context** includes the durable taste profile and complete canonical Markdown. **Download CSV** preserves viewing status, reaction, date, and provenance. Both exports require the user's existing login and are generated from the live journal.

## Storage and recovery

The canonical `WATCH_HISTORY.md` and `TASTE_PROFILE.md` live in `storage/.journal/<user-id>/`, outside the public storage link. The default nginx configuration also denies dot-file paths. Initialize that directory from an explicitly confirmed source before opening Journal; the feature never bootstraps watches from search or the movie catalog. Local copies elsewhere are snapshots, not another editable database.

Every commit requires a reviewed draft hash, a matching source version, and unchanged Movary plays. Before changing plays, the SQLite-only checkpoint uses SQLite's online backup API and preserves the canonical Markdown. Files use private directory/file permissions. The ordinary full-instance backup is still needed for all storage, configuration/secrets, and application source/version. Checkpoints are not complete disaster-recovery archives.

Plays are verified inside a database transaction. An interrupted save retains its approved text and resumes without incrementing plays again. Repeating a completed commit returns its saved receipt. Unknown source dates never add another play to a film that already has a dated watch. Other changes invalidate the review rather than overwriting them.

This first version uses SQLite checkpoints; MySQL commits stop before writing. No database migrations, authentication changes, or dependency additions are required.

## Private MCP connection

`bin/journal-mcp.php --user-id=<owner-id>` exposes `movie_context` over newline-delimited STDIO using MCP protocol 2025-11-25. The parent process's OS/SSH access is the authentication boundary; there is no HTTP listener or unauthenticated network endpoint. Pin the owner in the operator's client configuration. Access is read-only by default.

An explicitly enabled `--write` connection also exposes `preview_watched_movies`, `review_watched_movies`, and `commit_watched_movies`. They call the same service as the browser. The model must show the exact preview and obtain user approval before committing the returned hash. A hash binds the data, not the human's identity or consent; client-side tool approvals remain necessary. No SQL, arbitrary files, tokens, or account-administration operations are exposed.

On a trusted private host, an MCP client can launch this command through SSH and `docker exec -i`. The SSH key is never sent to the model. Remove `--write` for a read-only connection or revoke the SSH key to remove host access. The raw SSH account and Docker access are powerful operator privileges; don't share that connection with untrusted users or workspaces.

For clients that keep STDIO open, use the optional Python-standard-library launcher `bin/journal-ssh-bridge.py --host <ssh-host> --identity <key-path> --container <name> --owner <id> [--write]`. It starts a fresh private remote process per request, so a container restart does not permanently close the local MCP connection. Drafts and receipts persist in the app. It returns a temporary error when the app is unavailable and never automatically retries writes. Client-launcher checks run with `python3 -m unittest discover -s tests/client`.

For ChatGPT, an outbound [Secure MCP Tunnel](https://developers.openai.com/api/docs/guides/secure-mcp-tunnels) can launch the STDIO process inside the private network. That connection is not configured by this feature. Restrict tunnel/workspace access and complete the chosen product's connection authorization before enabling writes. A future public HTTP transport needs a separately reviewed OAuth design; don't expose this process or the private app through an unauthenticated public tunnel.
