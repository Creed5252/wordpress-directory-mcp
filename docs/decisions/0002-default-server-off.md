# 0002: Switch off the adapter's default server

Date: 2026-11-19

The adapter creates a default server that can discover and execute any registered ability for a logged-in user. Nothing here needs it, so it is disabled with the `mcp_adapter_create_default_server` filter. Its route returns 404 on every site.
