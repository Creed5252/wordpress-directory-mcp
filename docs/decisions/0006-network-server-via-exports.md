# 0006: Network server reads each region's public export

Date: 2026-11-19

The sites run on separate hosting accounts, so the network server cannot read their databases. Each regional site publishes `/wp-json/pitch/v1/clubs-export` (public fields only). The network server caches each export for six hours and keeps the last good copy if a region is unreachable, so one site being down never empties the network's answers.
