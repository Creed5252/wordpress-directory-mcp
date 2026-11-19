# Architecture

## Pieces

- **MCP Adapter (WordPress.org plugin).** Transport and protocol: Streamable HTTP, sessions, `tools/list`, `tools/call`. It turns registered WordPress abilities into MCP tools.
- **pn-agent-feed (this plugin).** Registers the abilities, creates one custom MCP server (`/wp-json/pitch/mcp`), sets its access rule, keeps the call log, and on regional sites publishes a public export.

## Data flow

Regional site:
```
agent → POST /wp-json/pitch/mcp → transport permission (agent account, rate limit, session log)
      → adapter → ability execute_callback → club data (site option or page 18 data block) / WP_Query
      → public-field record + attribution → agent
```

Network site (pitchnetwork.org):
```
agent → POST /wp-json/pitch/mcp → find-clubs → pn_feed_network_clubs()
      → GET <region>/wp-json/pitch/v1/clubs-export (cached 6 h; last good copy kept if a site is down)
```

## Where state lives

| State | Where |
|---|---|
| Club data | Each site's own option or page; this plugin never writes it |
| SoCal Pitch parse cache | transient keyed by the directory page's modified time |
| Network cache | transients `pn_net_<code>` (6 h) and options `pn_net_last_<code>` (last good) |
| Rate limit | transient per hashed IP, 1 hour |
| MCP sessions | adapter user meta on the no-role agent account (max 32, 24 h inactivity) |
| Call log | table `<prefix>pn_mcp_log`, 180-day retention, no IP addresses |

## Site detection

`pn_feed_site()` picks the configuration from the site's own domain, so the same file deploys unchanged to all six sites.
