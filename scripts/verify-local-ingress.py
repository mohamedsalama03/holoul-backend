#!/usr/bin/env python3
"""Read-only routing/security checks for the fixed loopback F1-E1 origin. No cookie values logged."""
import http.client
from html.parser import HTMLParser
import json
import os
from pathlib import Path
import socket
import ssl
import time
from urllib.error import HTTPError
from urllib.request import HTTPSHandler, HTTPRedirectHandler, Request, build_opener

ROOT = Path(__file__).resolve().parents[1]
ORIGIN = 'https://localhost:8443'


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Assets(HTMLParser):
    def __init__(self):
        super().__init__()
        self.paths = set()

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'script' or (tag == 'link' and attrs.get('rel') in ['stylesheet', 'preload', 'icon']):
            path = attrs.get('src') or attrs.get('href') or ''
            if path.startswith('/admin/'):
                self.paths.add(path)


def main():
    context = ssl.create_default_context()
    if os.environ.get('HOLOUL_E2E_ACCEPT_SELF_SIGNED_TLS') == '1':
        # There is deliberately no configurable remote URL or global TLS switch.
        context = ssl._create_unverified_context()
    opener = build_opener(HTTPSHandler(context=context), NoRedirect())
    records = []

    def request(path, expected, headers=None, method='GET', body=None):
        time.sleep(0.12)
        try:
            result = opener.open(Request(ORIGIN + path, headers=headers or {}, method=method, data=body), timeout=60)
        except HTTPError as error:
            result = error
        payload = result.read()
        records.append({'path': path, 'method': method, 'status': result.code, 'expected': expected})
        assert result.code == expected, records[-1]
        assert not result.headers.get('Access-Control-Allow-Origin'), 'Unexpected CORS header'
        return result.headers, payload

    headers, html = request('/admin/login', 200)
    assert 'text/html' in headers['Content-Type']
    policies = headers.get_all('Content-Security-Policy')
    assert len(policies) == 1 and "script-src 'self' 'nonce-" in policies[0]
    assert "default-src 'none'" not in policies[0]
    for directive in ["connect-src 'self'", "font-src 'self'", "object-src 'none'", "frame-ancestors 'none'"]:
        assert directive in policies[0]
    assert headers['X-Content-Type-Options'] == 'nosniff' and headers['X-Frame-Options'] == 'DENY'
    assets = Assets()
    assets.feed(html.decode())
    assert any('/_next/' in path and '.js' in path for path in assets.paths)
    assert any('.css' in path for path in assets.paths)
    kinds = set()
    for path in sorted(assets.paths):
        resource_headers, resource = request(path, 200)
        assert resource and 'text/html' not in resource_headers['Content-Type']
        kinds.add(resource_headers['Content-Type'].split(';')[0])
    request('/admin', 200)
    request('/administrator', 404)
    api_headers, api_body = request('/api/v1', 200)
    assert json.loads(api_body)['data']['service'] == 'HOLOUL'
    assert api_headers['Content-Security-Policy'] == "default-src 'none'; frame-ancestors 'none'"
    request('/api/v1/identity/me', 401)
    csrf_headers, _ = request('/sanctum/csrf-cookie', 204)
    cookie_headers = csrf_headers.get_all('Set-Cookie')
    assert len(cookie_headers) == 2
    cookie_flags = {}
    for cookie in cookie_headers:
        name = cookie.split('=', 1)[0]
        attributes = [item.strip().lower() for item in cookie.split(';')[1:]]
        assert 'secure' in attributes and 'samesite=lax' in attributes and 'path=/' in attributes
        assert not any(item.startswith('domain=') for item in attributes)
        assert ('httponly' in attributes) == (name == '__Host-holoul_session')
        cookie_flags[name] = {'secure': True, 'same_site': 'lax', 'host_only': True, 'http_only': 'httponly' in attributes}
    request('/sanctum/csrf-cookie', 204, {'Origin': ORIGIN})
    for origin in ['https://attacker.test', 'http://localhost:8443', 'https://localhost:9443', 'null']:
        request('/sanctum/csrf-cookie', 403, {'Origin': origin})
        admin_headers, _ = request('/admin/login', 403, {'Origin': origin})
        assert 'Content-Security-Policy' not in admin_headers
    spoofed = {'Forwarded': 'for=127.0.0.1;host=attacker.test;proto=http', 'X-Forwarded-Host': 'attacker.test',
               'X-Forwarded-Proto': 'http', 'X-Forwarded-Port': '80', 'X-Forwarded-For': '127.0.0.1'}
    request('/admin/login', 200, spoofed)
    request('/sanctum/csrf-cookie', 204, spoofed)
    for path in ['/admin/login', '/sanctum/csrf-cookie']:
        request(path, 403, {**spoofed, 'Origin': 'https://attacker.test'})
    request('/admin/login', 403, {'Host': 'attacker.test', 'X-Forwarded-Host': 'localhost:8443'})
    request('/api/v1', 400, {'Host': 'attacker.test', 'X-Forwarded-Host': 'localhost:8443'})
    request('/admin/login', 403, {'Referer': 'https://localhost:8443.attacker.test/'})
    request('/admin/login', 403, {'Sec-Fetch-Site': 'cross-site'})
    request('/api/v1/auth/login', 403, {'Origin': ORIGIN, 'Content-Type': 'application/json'}, 'POST', b'{}')

    def body_limit(path, size, expected):
        # Announce, do not upload: 100 proves ingress accepts the size; then close without a body.
        with socket.create_connection(('localhost', 8443), timeout=10) as plain:
            with context.wrap_socket(plain, server_hostname='localhost') as connection:
                connection.sendall(f'POST {path} HTTP/1.1\r\nHost: localhost:8443\r\nOrigin: {ORIGIN}\r\nContent-Length: {size}\r\nExpect: 100-continue\r\nConnection: close\r\n\r\n'.encode())
                status = int(connection.recv(4096).split(b'\r\n', 1)[0].split()[1])
                assert status == expected, (path, size, status)
                records.append({'path': path, 'announced_bytes': size, 'status': status, 'expected': expected})
    body_limit('/api/v1', 12 * 1024 * 1024 + 1, 413)
    body_limit('/admin/api/portfolio', 13 * 1024 * 1024, 100)
    body_limit('/admin/api/portfolio', 41 * 1024 * 1024 + 1, 413)
    insecure = http.client.HTTPConnection('localhost', 8080, timeout=10)
    insecure.request('GET', '/admin/login', headers={'Host': 'localhost:8443', 'X-Forwarded-Proto': 'https'})
    assert insecure.getresponse().status == 403
    insecure.close()
    report = {'passed': True, 'origin': ORIGIN, 'checks': records, 'asset_content_types': sorted(kinds),
              'cookie_flags': cookie_flags, 'tls_exception': os.environ.get('HOLOUL_E2E_ACCEPT_SELF_SIGNED_TLS') == '1',
              'http_with_forged_https_rejected': True}
    output = ROOT / 'artifacts/f1-e1/ingress.json'
    output.write_text(json.dumps(report, indent=2) + '\n')
    print(f'Local ingress passed: {len(records)} checks, {len(assets.paths)} assets. No cookie values recorded.')


if __name__ == '__main__':
    main()
