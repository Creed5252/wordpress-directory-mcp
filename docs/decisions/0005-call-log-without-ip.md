# 0005: Count usage on the server, without IP addresses, outside GA4

Date: 2026-11-19

Tool calls happen server to server, so GA4 never sees them; it sees only clicks on the links the server returns. The plugin keeps its own log: app name at connect, tool, filters, result count. No IP addresses. 180-day retention. Sending these to GA4 through the Measurement Protocol was considered and rejected because it would inflate the user totals the network dashboard sums. The empty-result list doubles as a list of missing clubs, towns and guides.
