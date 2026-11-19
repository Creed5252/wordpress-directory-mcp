#!/usr/bin/env python3
"""Pull Pitch Network MCP usage (sessions, tool calls, empty searches) from every server.

Usage: python3 mcp_usage_pull.py [--days N] [--report]
- Appends one row per site per UTC day to pitch_mcp_daily.csv (date,site,sessions,calls), replacing
  rows for the same date and site, so reruns are safe.
- --report prints a short summary: totals, apps that connected, top tools, and searches that found nothing.
Test traffic from Sterling ("sterling-test" client) is excluded from every count.
Data comes from `wp pn-feed stats` on each server over wp.sh (SSH), so no HTTP edge and no captcha.
Added 2026-11-19 (Creed: "we need a way to track how many users are actually utilizing this").
"""
import csv, json, os, subprocess, sys, collections

HERE = os.path.dirname(os.path.abspath(__file__))
OPS = os.path.normpath(os.path.join(HERE, '..', '..', '..', '_ops'))
OUT = os.path.join(HERE, 'pitch_mcp_daily.csv')
SITES = {'pn': 'pitchnetwork.org', 'scp': 'socalpitch.com', 'ncp': 'norcalpitch.com', 'pnw': 'pnwpitch.com',
         'mw': 'mountainwestpitch.com', 'sw': 'southwestpitch.com'}
TEST = 'sterling-test'

def stats(code, days):
    root = f'~/www/{SITES[code]}/public_html'
    cmd = [os.path.join(OPS, 'wp.sh'), code, 'raw', f'cd {root} && wp --skip-plugins=grow-for-wp pn-feed stats --days={days}']
    out = subprocess.run(cmd, capture_output=True, text=True, stdin=subprocess.DEVNULL, timeout=150).stdout
    line = [l for l in out.splitlines() if l.startswith('{')]
    return json.loads(line[-1]) if line else None

def main():
    days = int(sys.argv[sys.argv.index('--days') + 1]) if '--days' in sys.argv else 2
    rows, clients, tools, zero, failed = {}, collections.Counter(), collections.Counter(), collections.Counter(), []
    for code in SITES:
        try:
            s = stats(code, days)
        except Exception as e:  # one site failing never stops the others
            s = None
        if not s:
            failed.append(code); continue
        test_sessions = sum(int(c['n']) for c in s['by_client'] if c['client'].startswith(TEST))
        for c in s['by_client']:
            if not c['client'].startswith(TEST):
                clients[c['client'].split(' ')[0] or 'unknown'] += int(c['n'])
        for t in s['by_tool']:
            tools[t['tool']] += int(t['n'])
        for z in s['zero_results']:
            zero[f"{code} {z['tool']} {z['args']}"] += int(z['n'])
        for d in s['by_day']:
            rows[(d['d'], code)] = (int(d['sessions']), int(d['calls']))
        if test_sessions and s['by_day']:  # remove test sessions from the newest day they could belong to
            last = s['by_day'][-1]['d']
            se, ca = rows[(last, code)]
            rows[(last, code)] = (max(0, se - test_sessions), ca)
    existing = {}
    if os.path.exists(OUT):
        with open(OUT) as f:
            for r in csv.DictReader(f):
                existing[(r['date'], r['site'])] = (int(r['sessions']), int(r['calls']))
    existing.update(rows)
    with open(OUT, 'w', newline='') as f:
        w = csv.writer(f); w.writerow(['date', 'site', 'sessions', 'calls'])
        for (d, site), (se, ca) in sorted(existing.items()):
            w.writerow([d, site, se, ca])
    if '--report' in sys.argv:
        tot_s = sum(v[0] for v in rows.values()); tot_c = sum(v[1] for v in rows.values())
        print(f'Last {days} day(s): {tot_s} agent sessions, {tot_c} tool calls (test traffic excluded).')
        print('Apps:', ', '.join(f'{k} {v}' for k, v in clients.most_common()) or 'none yet')
        print('Tools:', ', '.join(f'{k} {v}' for k, v in tools.most_common()) or 'none yet')
        if zero:
            print('Searches that found nothing:')
            for k, v in zero.most_common(10):
                print(f'  {v}x {k}')
    if failed:
        print('FAILED sites:', ', '.join(failed))
    print(f'wrote {OUT}')

if __name__ == '__main__':
    main()
