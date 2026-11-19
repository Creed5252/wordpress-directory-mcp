"""Live tests for a wordpress-directory-mcp server. Standard library only.

Run: python3 -m unittest discover -s tests -v
Set MCP_URL to test a server other than the Pitch Network one.
"""
import json, os, unittest, urllib.request, urllib.error

URL = os.environ.get('MCP_URL', 'https://pitchnetwork.org/wp-json/pitch/mcp')
BASE = URL.split('/wp-json/')[0]
HEAD = {'Content-Type': 'application/json', 'Accept': 'application/json, text/event-stream'}


def post(body, session=None):
    h = dict(HEAD)
    if session:
        h['Mcp-Session-Id'] = session
        h['MCP-Protocol-Version'] = '2025-06-18'
    req = urllib.request.Request(URL, data=json.dumps(body).encode(), headers=h, method='POST')
    with urllib.request.urlopen(req, timeout=60) as r:
        raw = r.read().decode()
        sid = r.headers.get('Mcp-Session-Id')
    lines = [l[6:] for l in raw.splitlines() if l.startswith('data: ')]
    return (json.loads(lines[-1]) if lines else json.loads(raw) if raw.strip() else None), sid


class LiveServer(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        init, cls.sid = post({'jsonrpc': '2.0', 'id': 1, 'method': 'initialize', 'params': {
            'protocolVersion': '2025-06-18', 'capabilities': {}, 'clientInfo': {'name': 'sterling-test', 'version': 'unittest'}}})
        cls.init = init
        post({'jsonrpc': '2.0', 'method': 'notifications/initialized'}, cls.sid)

    def call(self, name, args):
        res, _ = post({'jsonrpc': '2.0', 'id': 9, 'method': 'tools/call', 'params': {'name': name, 'arguments': args}}, self.sid)
        return res

    def test_handshake_issues_session(self):
        self.assertIsNotNone(self.sid)
        self.assertIn('serverInfo', self.init['result'])

    def test_tools_are_read_only(self):
        res, _ = post({'jsonrpc': '2.0', 'id': 2, 'method': 'tools/list'}, self.sid)
        tools = res['result']['tools']
        self.assertIn('pitch-find-clubs', [t['name'] for t in tools])
        for t in tools:
            self.assertTrue(t.get('annotations', {}).get('readOnlyHint'), t['name'])

    def test_find_clubs_returns_attributed_results(self):
        res = self.call('pitch-find-clubs', {'tier': 'Elite', 'limit': 2})
        text = res['result']['content'][0]['text']
        data = json.loads(text[text.index('{'):])
        self.assertGreater(data['data']['matches'], 0)
        self.assertIn('attribution', data)
        for club in data['data']['clubs']:
            self.assertNotIn('ein', club)
            self.assertNotIn('legal', club)
            self.assertNotIn('note', club)

    def test_unlisted_tool_is_refused(self):
        res = self.call('mcp-adapter-execute-ability', {'ability_name': 'core/get-site-info'})
        self.assertIn('error', res)

    def test_default_server_is_off(self):
        req = urllib.request.Request(BASE + '/wp-json/mcp/mcp-adapter-default-server', headers=HEAD)
        with self.assertRaises(urllib.error.HTTPError) as cm:
            urllib.request.urlopen(req, timeout=30)
        self.assertEqual(cm.exception.code, 404)


if __name__ == '__main__':
    unittest.main()
