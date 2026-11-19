# wordpress-directory-mcp

A read-only [Model Context Protocol](https://modelcontextprotocol.io) server that lets AI agents search a WordPress directory and read its published pages. It is built on the official WordPress.org [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) and the Abilities API in WordPress core, so it runs on ordinary WordPress hosting at no extra cost.

It powers **The Pitch Network**, a family of independent youth soccer guides covering 1,100+ clubs across the western United States:

| Server | URL |
|---|---|
| The Pitch Network (all regions) | `https://pitchnetwork.org/wp-json/pitch/mcp` |
| SoCal Pitch | `https://socalpitch.com/wp-json/pitch/mcp` |
| NorCal Pitch | `https://norcalpitch.com/wp-json/pitch/mcp` |
| PNW Pitch | `https://pnwpitch.com/wp-json/pitch/mcp` |
| Mountain West Pitch | `https://mountainwestpitch.com/wp-json/pitch/mcp` |
| Southwest Pitch | `https://southwestpitch.com/wp-json/pitch/mcp` |

How to connect an AI app: https://pitchnetwork.org/connect-your-ai/

## Who it's for

Anyone who runs a directory or guide on WordPress (clubs, providers, venues, members, listings) and wants AI assistants to quote it accurately and link back, instead of paraphrasing it without credit.

## What the server offers

Regional sites expose six tools: `find-clubs` (area, tier, league, gender, free text), `list-areas`, `search-guides`, `get-page`, `whats-new` and `about`. The network site exposes `find-clubs`, `list-areas`, `list-sites` and `about` across every region. Every answer carries attribution, a "confirm with the club" note, and links tagged `utm_source=pitch-mcp&utm_medium=agent` so visits it sends are measurable.

## Safety model

- **Read-only.** No tool writes anything. Unlisted tools are refused.
- **The adapter's default server is switched off.** It can run any registered ability for a logged-in user; this site does not need it.
- **Anonymous agents run as one account with no role and no capabilities**, set only inside this server's route. The adapter stores sessions per user, so this is how public access works without granting anything. The check refuses to run if that account ever gains a role or capability.
- **Published content only.** Drafts, scheduled posts, password-protected and `noindex` pages are never served. Pages whose canonical points to another site are not served twice.
- **Public fields only.** Internal research notes, legal names and tax IDs in the dataset are never returned.
- **Rate limit:** 300 calls per hour per IP.
- **Usage log without IP addresses:** app name at connect, tool, filters, result count; deleted after 180 days.

## How to run it

1. WordPress 6.9 or later (Abilities API in core), PHP 8.0+.
2. Install and activate the **MCP Adapter** plugin from WordPress.org.
3. Copy `plugin/pn-agent-feed/` into `wp-content/plugins/` and activate it.
4. Create the agent account with no role: `wp user create pitch-agent-feed agent@example.com --role=subscriber && wp user remove-role pitch-agent-feed subscriber`
5. Your server is at `https://<your-site>/wp-json/pitch/mcp`.

The plugin reads club data from a site option (`<code>_clubs`, a JSON array) or, on SoCal Pitch, from the directory page's data block. Adapt `pn_feed_site()` and `pn_feed_clubs()` to your own data source. `tools/deploy_remote.sh` is the server-side install script used for the six live sites.

Usage report: `wp pn-feed stats --days=7` prints sessions by app, calls by tool, and searches that returned nothing. `tools/mcp_usage_pull.py` collects that from several sites into one CSV.

## How to test

```
python3 -m unittest discover -s tests -v
```

The tests run against the live network server by default (set `MCP_URL` to test another). They check the handshake, the tool list, a real club search, attribution, that unlisted tools are refused, and that the adapter's default server is off.

## Discovery

Each site serves a server card at `/.well-known/mcp/server-card.json`. Registry listing files are in `examples/registry/`.

## License

GPL-2.0-or-later, the same as WordPress and the MCP Adapter. Built by Colin Reed for The Pitch Network.
