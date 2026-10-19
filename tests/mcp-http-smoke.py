#!/usr/bin/env python3
"""Local-only MCP HTTP smoke test with a disposable account and credential.

Example: python3 tests/mcp-http-smoke.py --user directorist_mcp_test_ui
Pass --socket for Local's MySQL socket and --ca for a trusted local certificate.
The named test account must already exist; its application password is revoked.
"""
import argparse
import base64
import json
import os
import re
import selectors
import ssl
import subprocess
import tempfile
import time
import urllib.error
import urllib.request


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--user', required=True)
    parser.add_argument('--url', default='https://directorist.local/mcp-server/wp-json/directorist/mcp')
    parser.add_argument('--socket')
    parser.add_argument('--ca')
    parser.add_argument('--wp', default='/usr/local/bin/wp')
    parser.add_argument('--php', default='php')
    parser.add_argument('--bridge', action='store_true', help='Also verify the pinned official stdio bridge.')
    args = parser.parse_args()
    if not re.fullmatch(r'(directorist_)?mcp_[A-Za-z0-9_]+', args.user):
        raise ValueError('Use a disposable MCP test account, never a real user.')
    if not args.url.startswith('https://'):
        raise ValueError('TLS verification is required.')
    wp = [args.php]
    if args.socket:
        wp += ['-d', 'mysqli.default_socket=' + args.socket]
    wp += [args.wp, 'eval']

    def php(code):
        run = subprocess.run(wp + [code], capture_output=True, text=True, check=True)
        return json.loads(run.stdout)

    fixture = php('if(wp_get_environment_type()!=="local"){throw new Exception("Local test site required");}'
                  '$u=get_user_by("login",' + json.dumps(args.user) + ');'
                  'if(!$u){throw new Exception("Fixture account does not exist");}'
                  '$r=WP_Application_Passwords::create_new_application_password($u->ID,["name"=>"Disposable Directorist MCP HTTP test"]);'
                  'if(is_wp_error($r)){throw new Exception("Could not create fixture credential");}'
                  'echo wp_json_encode(["id"=>$u->ID,"password"=>$r[0],"uuid"=>$r[1]["uuid"]]);')
    context = ssl.create_default_context(cafile=args.ca)
    authorization = base64.b64encode((args.user + ':' + fixture['password']).encode()).decode()
    headers = {'Authorization': 'Basic ' + authorization, 'Content-Type': 'application/json', 'Accept': 'application/json, text/event-stream'}

    def call(body):
        request = urllib.request.Request(args.url, data=json.dumps(body).encode(), headers=headers)
        try:
            with urllib.request.urlopen(request, context=context, timeout=20) as response:
                raw = response.read().decode()
                if response.headers.get('Mcp-Session-Id'):
                    headers['Mcp-Session-Id'] = response.headers['Mcp-Session-Id']
                if raw.startswith(('event:', 'data:')):
                    raw = '\n'.join(line[5:].strip() for line in raw.splitlines() if line.startswith('data:'))
                return response.status, json.loads(raw) if raw.strip() else None
        except urllib.error.HTTPError as error:
            return error.code, json.loads(error.read().decode())

    def revoke():
        php('echo wp_json_encode(WP_Application_Passwords::delete_application_password(' + str(fixture['id']) + ',' + json.dumps(fixture['uuid']) + '));')

    try:
        status, result = call({'jsonrpc': '2.0', 'id': 1, 'method': 'initialize', 'params': {
            'protocolVersion': '2025-11-25', 'capabilities': {}, 'clientInfo': {'name': 'directorist-integration-test', 'version': '1'}}})
        assert status == 200 and 'result' in result, 'MCP initialization failed.'
        headers['MCP-Protocol-Version'] = result['result']['protocolVersion']
        print('PASS: HTTPS initialization and protocol negotiation.')
        status, _ = call({'jsonrpc': '2.0', 'method': 'notifications/initialized'})
        assert status in (200, 202, 204), 'Initialized notification failed.'
        status, result = call({'jsonrpc': '2.0', 'id': 2, 'method': 'tools/list', 'params': {}})
        names = [tool['name'] for tool in result.get('result', {}).get('tools', [])]
        assert status == 200 and len(names) == 53 and 'directorist-get-context' in names, 'Expected 53 Directorist tools, got ' + str(len(names))
        print('PASS: 53-tool discovery and wire-name mapping.')
        status, result = call({'jsonrpc': '2.0', 'id': 3, 'method': 'tools/call', 'params': {'name': 'directorist-get-context', 'arguments': {}}})
        assert status == 200 and not result.get('error') and not result.get('result', {}).get('isError'), 'Context execution failed.'
        print('PASS: authenticated MCP tool execution.')
        reads = {
            'get-dashboard-schema': {},
            'get-dashboard-overview': {'sections': ['listings', 'views']},
            'get-dashboard-series': {'metric': 'published_listings'},
            'list-dashboard-activity': {'type': 'listing'},
            'get-dashboard-guidance': {},
            'discover-site-health-info': {},
            'get-site-health-info': {'section_id': 'runtime'},
            'get-site-health-report': {'format': 'json'},
        }
        for index, (tool, arguments) in enumerate(reads.items(), 20):
            status, result = call({'jsonrpc': '2.0', 'id': index, 'method': 'tools/call',
                                   'params': {'name': 'directorist-' + tool, 'arguments': arguments}})
            assert status == 200 and not result.get('error') and not result.get('result', {}).get('isError'), tool + ' failed over HTTPS.'
        print('PASS: all eight dashboard and Site Health reads over HTTPS.')
        status, result = call({'jsonrpc': '2.0', 'id': 4, 'method': 'tools/call', 'params': {'name': 'directorist-get-settings', 'arguments': {'keys': ['map_api_key']}}})
        assert result.get('error') or result.get('result', {}).get('isError'), 'Secret read must fail.'
        print('PASS: sensitive settings remain protected over MCP.')
        if args.bridge:
            with tempfile.TemporaryDirectory(prefix='directorist-mcp-bridge-') as config_dir:
                env = dict(os.environ, WP_API_URL=args.url, WP_API_USERNAME=args.user,
                           WP_API_PASSWORD=fixture['password'], OAUTH_ENABLED='false',
                           WP_MCP_CONFIG_DIR=config_dir, LOG_LEVEL='0')
                for key in ('JWT_TOKEN', 'CUSTOM_HEADERS', 'LOG_FILE'):
                    env.pop(key, None)
                if args.ca:
                    env['NODE_EXTRA_CA_CERTS'] = args.ca
                bridge = subprocess.Popen(['npx', '-y', '@automattic/mcp-wordpress-remote@0.5.1'],
                                          stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                          stderr=subprocess.DEVNULL, env=env)
                selector = selectors.DefaultSelector()
                selector.register(bridge.stdout, selectors.EVENT_READ)
                pending = b''

                def bridge_call(body, reply=True):
                    nonlocal pending
                    bridge.stdin.write((json.dumps(body) + '\n').encode())
                    bridge.stdin.flush()
                    if not reply:
                        return None
                    deadline = time.monotonic() + 90
                    while time.monotonic() < deadline:
                        while b'\n' in pending:
                            line, pending = pending.split(b'\n', 1)
                            try:
                                message = json.loads(line)
                            except (ValueError, UnicodeDecodeError):
                                continue
                            if message.get('id') == body['id']:
                                return message
                        if selector.select(timeout=1):
                            chunk = os.read(bridge.stdout.fileno(), 65536)
                            if not chunk:
                                raise AssertionError('Bridge stopped before responding.')
                            pending += chunk
                    raise AssertionError('Bridge response timed out.')

                try:
                    initialized = bridge_call({'jsonrpc': '2.0', 'id': 11, 'method': 'initialize', 'params': {
                        'protocolVersion': '2025-11-25', 'capabilities': {},
                        'clientInfo': {'name': 'directorist-bridge-test', 'version': '1'}}})
                    assert 'result' in initialized and not initialized['result'].get('capabilities', {}).get('experimental', {}).get('connectionFailed'), 'Bridge initialization failed.'
                    bridge_call({'jsonrpc': '2.0', 'method': 'notifications/initialized'}, False)
                    discovery = bridge_call({'jsonrpc': '2.0', 'id': 12, 'method': 'tools/list', 'params': {}})
                    assert len(discovery.get('result', {}).get('tools', [])) == 53, 'Bridge tool discovery failed.'
                    context_result = bridge_call({'jsonrpc': '2.0', 'id': 13, 'method': 'tools/call', 'params': {'name': 'directorist-get-context', 'arguments': {}}})
                    assert 'result' in context_result and not context_result['result'].get('isError'), 'Bridge context call failed.'
                    print('PASS: pinned stdio bridge 0.5.1 initialization, discovery and context over verified TLS.')
                finally:
                    selector.close()
                    bridge.stdin.close()
                    bridge.terminate()
                    try:
                        bridge.wait(timeout=5)
                    except subprocess.TimeoutExpired:
                        bridge.kill()
                        bridge.wait()
        revoke()
        status, result = call({'jsonrpc': '2.0', 'id': 5, 'method': 'tools/call', 'params': {'name': 'directorist-get-context', 'arguments': {}}})
        assert status in (401, 403), 'Revoked credentials must not authorize an existing session.'
        print('PASS: credential revocation, including an existing adapter session.')
    finally:
        revoke()


if __name__ == '__main__':
    main()
