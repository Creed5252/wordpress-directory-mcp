# Changelog

## Unreleased (2026-11-19)
- Tool labels on the network server: no more "the The Pitch Network" or "About The Pitch Network and The Pitch Network".
- Design note rewritten from Colin Reed's dictation (draft, awaiting his read-through).

## 1.1.1 (2026-11-19)
- `find-clubs` area filter understands US state names ("Colorado" matches "Denver, CO").
- Session log records each connection once.

## 1.1.0 (2026-11-19)
- Network mode for pitchnetwork.org: one server across all regions, fed by each site's public export with caching and last-good fallback.
- `list-sites` tool on the network server.
- Call log without IP addresses, `wp pn-feed stats`, 180-day retention.
- Public export endpoint `/wp-json/pitch/v1/clubs-export` on regional sites.

## 1.0.0 (2026-11-19)
- First release on five regional sites: `find-clubs`, `list-areas`, `search-guides`, `get-page`, `whats-new`, `about`.
- Default adapter server off, no-role agent account, public fields only, canonical-only pages, rate limit.
