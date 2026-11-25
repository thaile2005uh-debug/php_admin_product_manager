"""Read-only HTTP checks against a freshly seeded local classroom installation.
Usage: python3 tests/http_smoke.py [http://localhost:8090]
Logs in using seed accounts; does not create or post inventory documents.
"""
import html
import http.cookiejar
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

base = sys.argv[1] if len(sys.argv) > 1 else 'http://localhost:8090'
checks = 0

def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

def request(opener, path, data=None):
    encoded = None if data is None else urllib.parse.urlencode(data).encode()
    try:
        with opener.open(base + path, encoded, timeout=20) as response:
            return response.status, response.read().decode(), response.url
    except urllib.error.HTTPError as response:
        return response.code, response.read().decode(), response.url

def check(condition, message):
    global checks
    assert condition, message
    checks += 1
    print('PASS:', message)

def login(username, password):
    opener = client()
    status, body, _ = request(opener, '/login')
    token = re.search(r'name="_csrf_token" value="([^"]+)"', body).group(1)
    status, body, url = request(opener, '/login', {'username': username, 'password': password, '_csrf_token': token})
    check(status == 200 and '/dashboard' in url, username + ' logs in')
    return opener

anonymous = client()
check('/login' in request(anonymous, '/inventory')[2], 'anonymous stock request redirects to login')
admin = login('admin', 'admin123')
for path in ['/dashboard', '/inventory', '/documents', '/documents/create?type=receipt', '/documents/create?type=issue',
             '/products', '/products/create', '/products/edit?id=1', '/categories', '/prices',
             '/warehouse-catalog?tab=warehouses', '/warehouse-catalog?tab=locations',
             '/warehouse-catalog?tab=lots', '/warehouse-catalog?tab=units', '/access']:
    status, body, _ = request(admin, path)
    check(status == 200 and 'Warning:' not in body and 'Fatal error' not in body, 'admin GET ' + path)
check(request(admin, '/products/delete')[0] == 405, 'GET cannot delete a product')
check(request(admin, '/products/delete', {'id': 1})[0] == 419, 'POST without CSRF is rejected')
status, body, _ = request(admin, '/documents')
match = re.search(r'/documents/show\?id=(\d+)', html.unescape(body))
check(match is not None, 'opening inventory slip is listed')
if match:
    check(request(admin, '/documents/print?id=' + match.group(1))[0] == 200, 'posted opening slip has a print view')
viewer = login('viewer', 'viewer123')
for path in ['/access', '/prices', '/warehouse-catalog', '/products/create', '/documents/create?type=receipt', '/documents/print?id=1']:
    check(request(viewer, path)[0] == 403, 'viewer denied ' + path)
check(request(viewer, '/products/delete', {'id': 1})[0] == 403, 'crafted viewer mutation is forbidden')
clerk = login('clerk', 'clerk123')
status, body, _ = request(clerk, '/documents')
token = re.search(r'name="_csrf_token" value="([^"]+)"', body).group(1)
check(request(clerk, '/documents/post', {'id': 1, '_csrf_token': token})[0] == 403, 'clerk cannot post even with a valid CSRF token')
print(f'\n{checks} HTTP checks passed.')
