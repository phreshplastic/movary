<?php declare(strict_types=1);

namespace Movary\Service\Journal;

/** Portable DDL: JSON is stored as text so the private schema works on SQLite and MySQL. */
class LibrarySchema
{
    /** @return list<string> */
    public static function statements() : array
    {
        return [
            'CREATE TABLE journal_collection (user_id INTEGER NOT NULL PRIMARY KEY, revision INTEGER NOT NULL, source_hash VARCHAR(64) NOT NULL, migrated_at VARCHAR(32) NOT NULL)',
            "CREATE TABLE journal_title (id VARCHAR(32) NOT NULL PRIMARY KEY, user_id INTEGER NOT NULL, media_type VARCHAR(8) NOT NULL, tmdb_id INTEGER DEFAULT NULL, title VARCHAR(512) NOT NULL, release_year VARCHAR(4) NOT NULL, aliases TEXT NOT NULL, metadata TEXT NOT NULL, refreshed_at VARCHAR(32) DEFAULT NULL, viewing_status VARCHAR(16) NOT NULL, interest VARCHAR(16) NOT NULL, reaction TEXT NOT NULL, note TEXT NOT NULL, version INTEGER NOT NULL, updated_at VARCHAR(32) NOT NULL, provenance TEXT NOT NULL)",
            'CREATE UNIQUE INDEX journal_provider_identity ON journal_title (user_id, media_type, tmdb_id)',
            'CREATE INDEX journal_collection_filter ON journal_title (user_id, viewing_status, interest, media_type, release_year)',
            'CREATE TABLE journal_facet (title_id VARCHAR(32) NOT NULL, kind VARCHAR(16) NOT NULL, provider_id VARCHAR(32) NOT NULL, label VARCHAR(512) NOT NULL, role VARCHAR(128) NOT NULL, scope VARCHAR(32) NOT NULL)',
            'CREATE INDEX journal_facet_lookup ON journal_facet (kind, provider_id, title_id)',
            'CREATE INDEX journal_facet_title ON journal_facet (title_id)',
            'CREATE TABLE journal_event (id VARCHAR(32) NOT NULL PRIMARY KEY, user_id INTEGER NOT NULL, title_id VARCHAR(32) NOT NULL, kind VARCHAR(32) NOT NULL, scope VARCHAR(16) NOT NULL, season INTEGER DEFAULT NULL, watched_on VARCHAR(10) DEFAULT NULL, reaction TEXT NOT NULL, note TEXT NOT NULL, progress VARCHAR(32) NOT NULL, provenance TEXT NOT NULL, recorded_at VARCHAR(32) NOT NULL, personal_revision INTEGER NOT NULL)',
            'CREATE INDEX journal_event_history ON journal_event (user_id, title_id, recorded_at, id)',
            'CREATE INDEX journal_scope_lookup ON journal_event (user_id, title_id, kind, scope, season, personal_revision)',
            'CREATE TABLE journal_taste (id VARCHAR(32) NOT NULL PRIMARY KEY, user_id INTEGER NOT NULL, kind VARCHAR(32) NOT NULL, statement TEXT NOT NULL, evidence TEXT NOT NULL, provenance TEXT NOT NULL, version INTEGER NOT NULL)',
            'CREATE TABLE journal_change (id VARCHAR(64) NOT NULL PRIMARY KEY, user_id INTEGER NOT NULL, base_revision INTEGER NOT NULL, approval VARCHAR(64) NOT NULL, payload TEXT NOT NULL, expires INTEGER NOT NULL, receipt TEXT DEFAULT NULL)',
            'CREATE INDEX journal_change_owner ON journal_change (user_id)',
            'CREATE TABLE journal_audit (id VARCHAR(32) NOT NULL PRIMARY KEY, user_id INTEGER NOT NULL, revision INTEGER NOT NULL, change_id VARCHAR(64) NOT NULL, before_state TEXT NOT NULL, after_state TEXT NOT NULL, recorded_at VARCHAR(32) NOT NULL)',
        ];
    }
}
