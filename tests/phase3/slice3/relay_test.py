#!/usr/bin/env python3
"""Slice 3's relay, scripted (docs/build-specs/live.md "Proof" 1–7; live-relay.md "Proving it") — the realtime spike's measurements as gates,
every socket through the front door ($BD_BASE: the user-level Apache rendering the deploy vhost's /live line). People sign on through the
hand-off as the kernel mints it, commit through the canvas's own door (POST /boards/{board}/ops), and take their tickets from the ticket door;
tampered, expired and reused tickets are minted here with the scratch key. The database is changed as postgres on the scratch bd_dev3 only
(the owner's standing exception). Run through tests/phase3/slice3/run.sh with mcp/venv's python. Prints "  ok   …" / "  FAIL …".
"""
import asyncio
import base64
import hashlib
import hmac
import http.cookiejar
import json
import os
import re
import secrets
import statistics
import subprocess
import sys
import threading
import time
import urllib.parse
import urllib.request
import uuid
from pathlib import Path

from websockets.asyncio.client import connect

ROOT = Path(__file__).resolve().parents[3]
ENV = {}
for line in Path(os.environ['BD_DEV_ENV']).read_text().splitlines():
    if '=' in line and not line.startswith('#'):
        k, v = line.split('=', 1)
        ENV[k] = v.strip().strip('"')
BASE = os.environ.get('BD_BASE', 'http://127.0.0.1:9107')
WS = BASE.replace('http', 'ws', 1) + '/live'
SEED = json.loads(Path(os.environ['BD_S3_SEED']).read_text())
FIXTURE = json.loads((ROOT / 'bin/dev_directory.json').read_text())
RELAY_PID = int(os.environ.get('BD_RELAY_PID', '0'))
CHECK = 5
PALETTE = ['#e03131', '#1971c2', '#2f9e44', '#f08c00', '#9c36b5', '#0c8599', '#c2255c', '#5f3dc4', '#66a80f', '#e8590c', '#1864ab', '#862e9c']
passed = failed = 0


def ok(c, label):
    global passed, failed
    if c:
        passed += 1
    else:
        failed += 1
    print(('  ok   ' if c else '  FAIL ') + label, flush=True)


def pg(sql):
    r = subprocess.run(['sudo', '-n', '-u', 'postgres', 'psql', '-X', '-q', '-At', '-v', 'ON_ERROR_STOP=1', '-d', ENV['DB_NAME']], input=sql, text=True, capture_output=True)
    if r.returncode != 0:
        print('    psql: ' + r.stderr.strip(), file=sys.stderr)
    return r.stdout.strip()


# ---- people, as the kernel signs them on ---------------------------------------------------------------------------------------
class Person:
    def __init__(self, member):
        self.member = member
        self.jar = http.cookiejar.CookieJar()
        self.http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())
        key = ENV['ACTION_TOKEN_KEY']
        payload = f"{member}.{int(time.time()) + 60}.boards.{secrets.token_hex(16)}"
        token = payload + '.' + hmac.new(key.encode(), ('sso:' + payload).encode(), hashlib.sha256).hexdigest()
        claims = dict(FIXTURE['claims'][str(member)], member_id=member)
        text = base64.urlsafe_b64encode(json.dumps(claims).encode()).decode().rstrip('=')
        self.get('/sso?' + urllib.parse.urlencode({'token': token, 'claims': text + '.' + hmac.new(key.encode(), text.encode(), hashlib.sha256).hexdigest()}))
        page = self.get('/activity')[1]
        self.csrf = re.search(r'name="csrf-token" id="csrf-token-meta" content="([^"]+)"', page).group(1)

    def get(self, path):
        try:
            r = self.http.open(BASE + path, timeout=30)
            return r.status, r.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode()

    def post(self, path, body=None, form=None):
        data = json.dumps(body).encode() if body is not None else urllib.parse.urlencode(form or {}).encode()
        req = urllib.request.Request(BASE + path, data=data, method='POST', headers={
            'Accept': 'application/json', 'X-CSRF-Token': self.csrf,
            'Content-Type': 'application/json' if body is not None else 'application/x-www-form-urlencoded'})
        try:
            r = self.http.open(req, timeout=30)
            return r.status, json.loads(r.read().decode() or 'null')
        except urllib.error.HTTPError as e:
            return e.code, json.loads(e.read().decode() or 'null')

    def ticket(self, board):
        code, j = self.post(f'/boards/{board}/live-ticket', form={})
        return j.get('ticket') if code == 200 else None

    def commit(self, board, ops):
        return self.post(f'/boards/{board}/ops', {'client_batch_id': str(uuid.uuid4()), 'client_id': f'py-{self.member}', 'ops': ops})


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


def sticky(text='x', x=0.0, y=0.0):
    return {'op': 'create', 'item': {'id': str(uuid.uuid4()), 'type': 'sticky_note', 'x': x, 'y': y, 'width': 200, 'height': 200, 'z': 'a0',
                                     'style': {'fillColor': 'light_yellow'}, 'data': {'content': [{'type': 'text', 'text': {'content': text, 'link': None}, 'plain_text': text}]}}}


def mint(member, board, level, exp_in=60, tamper=False):
    body = f'{member}.board:{board}.{level}.{int(time.time()) + exp_in}.{secrets.token_hex(8)}'
    mac = hmac.new(ENV['BOARDS_LIVE_KEY'].encode(), body.encode(), hashlib.sha256).hexdigest()
    if tamper:
        mac = ('0' if mac[0] != '0' else '1') + mac[1:]
    return body + '.' + mac


class Sock:
    """A scripted client: its messages kept with their arrival time, its close code."""
    def __init__(self, ws, keepalive=True):
        self.ws, self.msgs, self.code, self.reason = ws, [], None, ''
        self.reader = asyncio.create_task(self.read())
        # the island pings every 25 s; the proof's idle close is 8 s, so a scripted socket pings every 3 s (all but the idle one)
        self.pinger = asyncio.create_task(self.ping_forever()) if keepalive else None

    async def ping_forever(self):
        try:
            while self.code is None:
                await asyncio.sleep(3)
                await self.ws.send('{"t":"ping"}')
        except Exception:
            pass

    async def read(self):
        try:
            async for raw in self.ws:
                self.msgs.append((time.perf_counter(), json.loads(raw)))
        except Exception:
            pass
        self.code, self.reason = self.ws.close_code, self.ws.close_reason

    def of(self, t, **match):
        return [m for _, m in self.msgs if m.get('t') == t and all(m.get(k) == v for k, v in match.items())]

    async def wait(self, pred, timeout=5.0):
        end = time.perf_counter() + timeout
        while time.perf_counter() < end:
            for at, m in self.msgs:
                if pred(m):
                    return at, m
            if self.code is not None:
                return None
            await asyncio.sleep(0.005)
        return None

    async def closed(self, timeout=10.0):
        try:
            await asyncio.wait_for(asyncio.shield(self.reader), timeout)
        except asyncio.TimeoutError:
            return None
        return self.code

    async def send(self, msg):
        await self.ws.send(json.dumps(msg))

    async def close(self):
        await self.ws.close()
        await asyncio.sleep(0)


async def open_sock(ticket, client=None, extra='', keepalive=True):
    q = urllib.parse.urlencode({'ticket': ticket or '', 'client': client or 'c' + secrets.token_hex(4)}) + extra
    ws = await connect(f'{WS}?{q}', max_size=2 ** 20, open_timeout=10)
    s = Sock(ws, keepalive)
    await s.wait(lambda m: m.get('t') == 'welcome', 3.0)
    return s


def pct(values, p):
    v = sorted(values)
    return v[min(len(v) - 1, int(len(v) * p))] if v else float('nan')


def cpu_rss(pid):
    if not pid:
        return 0.0, 0
    stat = Path(f'/proc/{pid}/stat').read_text().split()
    ticks = (int(stat[13]) + int(stat[14])) / os.sysconf('SC_CLK_TCK')
    rss = next(int(l.split()[1]) for l in Path(f'/proc/{pid}/status').read_text().splitlines() if l.startswith('VmRSS'))
    return ticks, rss


async def main():
    live, big, trash = SEED['live'], SEED['big'], SEED['trash']
    priya, bea, dana, lee = Person(26), Person(28), Person(30), Person(31)

    print('the ticket door and the welcome')
    t_bea = bea.ticket(live)
    parts = (t_bea or '').split('.')
    ok(len(parts) == 6 and parts[0] == '28' and parts[1] == f'board:{live}' and parts[2] == 'edit' and int(parts[3]) - time.time() <= 61
       and re.fullmatch(r'[0-9a-f]{16}', parts[4]) is not None and bea.ticket(live) != bea.ticket(live),
       'the ticket door mints {member}.board:{uuid}.{level}.{exp}.{nonce}.{hmac} for the signed-in member at their level, 60 s — two in one second differ')
    ok(dana.ticket(live).split('.')[2] == 'view' and lee.post(f'/boards/{live}/live-ticket', form={})[0] == 404, "a Viewer's ticket says view; a stranger's door: 404")
    no_csrf = urllib.request.Request(BASE + f'/boards/{live}/live-ticket', data=b'', method='POST', headers={'Accept': 'application/json'})
    try:
        code = bea.http.open(no_csrf, timeout=10).status
    except urllib.error.HTTPError as e:
        code = e.code
    ok(code == 403, 'without the page\'s CSRF token: 403')
    b1 = await open_sock(t_bea, 'tab-b1', '&name=Mallory')
    w = b1.of('welcome')
    ok(bool(w) and w[0]['you']['member_id'] == 28 and w[0]['you']['name'] == 'SMOKE Bea' and w[0]['you']['kind'] == 'human'
       and w[0]['you']['colour'] == PALETTE[28 % 12] and w[0]['level'] == 'edit' and w[0]['room'] == f'board:{live}',
       'welcome: who I am from the database (the name sent by the client is never used), my colour member_id % 12, my level, the room')
    p1 = await open_sock(priya.ticket(live), 'tab-p1')
    j = await b1.wait(lambda m: m.get('t') == 'join' and m['peer']['member_id'] == 26, 3)
    ok(j is not None and p1.of('welcome')[0]['peers'][0]['member_id'] == 28, 'a second socket: its welcome lists the first; the first is told join')
    await b1.send({'t': 'ping'})
    ok(await b1.wait(lambda m: m.get('t') == 'pong', 2) is not None, 'ping → pong')

    print('1. commit → the other tab')
    lat = []
    for i in range(200):
        code, a = priya.commit(live, [sticky(f'c{i}', i * 10)])
        answered = time.perf_counter()
        got = await b1.wait(lambda m: m.get('t') == 'ops' and m.get('seq') == a['seq'], 3)
        if got:
            lat.append(max(0.0, got[0] - answered) * 1000)
    ok(len(lat) == 200 and pct(lat, 0.95) < 50, f'{len(lat)} of 200 commits through the door, each to the other socket: p50 {statistics.median(lat):.1f} ms, p95 {pct(lat, 0.95):.1f} ms (< 50), max {max(lat):.1f}')
    m = b1.of('ops')[-1]
    ok(m['member_id'] == 26 and m['source'] == 'web' and m['client_id'] == 'py-26' and m['ops'][0]['op'] == 'create' and 'bbox' in m['summary'],
       'an ops message carries who, the source, the tab (the client skips its own echo), the ops and the summary\'s box')

    print('2. cursors: ten sockets × 20 Hz × 10 s')
    socks = [await open_sock(mint(28 if k % 2 else 26, big, 'edit'), f'cur{k}') for k in range(10)]
    cpu0, _ = cpu_rss(RELAY_PID)
    t0 = time.perf_counter()

    async def mover(k, s):
        for n in range(200):
            await s.send({'t': 'cursor', 'x': (time.perf_counter() - t0) * 1000, 'y': k})
            await asyncio.sleep(max(0.0, t0 + (n + 1) * 0.05 - time.perf_counter()))
    await asyncio.gather(*(mover(k, s) for k, s in enumerate(socks)))
    await asyncio.sleep(0.5)
    wall = time.perf_counter() - t0
    cpu1, rss = cpu_rss(RELAY_PID)
    got = []
    for s in socks:
        for at, m in s.msgs:
            if m.get('t') == 'peer' and m.get('k') == 'cursor':
                got.append((at - t0) * 1000 - m['d']['x'])
    ok(len(got) >= 0.99 * 18000 and pct(got, 0.95) < 50,
       f'{len(got)} of 18,000 cursor messages delivered, p50 {statistics.median(got):.1f} ms, p95 {pct(got, 0.95):.1f} ms (< 50); the relay {100 * (cpu1 - cpu0) / wall:.0f} % of a core, {rss // 1024} MB')
    for s in socks:
        await s.close()

    print('3. tickets and the room')
    for label, t in (('a tampered', mint(28, live, 'edit', tamper=True)), ('an expired', mint(28, live, 'edit', exp_in=-5))):
        s = await open_sock(t)
        ok(await s.closed(3) == 4401 and not s.msgs, f'{label} ticket: closed 4401 before any message')
    reused = mint(28, live, 'edit')
    first = await open_sock(reused)
    again = await open_sock(reused)
    ok(bool(first.of('welcome')) and await again.closed(3) == 4401 and not again.msgs, 'a ticket used twice: the second socket closed 4401')
    await first.close()
    forged = await open_sock('28.board:' + live + '.full.' + str(int(time.time()) + 60) + '.' + secrets.token_hex(8) + '.' + 'f' * 64)
    ok(await forged.closed(3) == 4401 and not forged.msgs, 'a forged level in a ticket breaks its signature: 4401')
    pg("UPDATE bd_settings SET room_max_sockets = 3")
    await asyncio.sleep(CHECK + 1.5)
    room = [await open_sock(mint(28, trash, 'edit'), f'r{k}') for k in range(3)]
    fourth = await open_sock(mint(28, trash, 'edit'), 'r4')
    ok(all(s.of('welcome') for s in room) and await fourth.closed(3) == 4429 and fourth.of('closing')[0]['code'] == 4429, 'a room past room_max_sockets (3): the next socket told and closed 4429')
    for s in room:
        await s.close()
    pg("UPDATE bd_settings SET room_max_sockets = 100")

    print('4. levels and the rate')
    d1 = await open_sock(dana.ticket(live), 'tab-d1')
    ok(d1.of('welcome')[0]['level'] == 'view', "Dana's socket is at view")
    n0 = len(b1.msgs)
    await d1.send({'t': 'drag', 'items': [{'id': str(uuid.uuid4()), 'x': 1, 'y': 2, 'width': 3, 'height': 4, 'rotation': 0}]})
    await d1.send({'t': 'editing', 'id': str(uuid.uuid4())})
    await d1.send({'t': 'cursor', 'x': 5, 'y': 6})
    await asyncio.sleep(0.4)
    fromd = [m for _, m in b1.msgs[n0:] if m.get('t') == 'peer' and m.get('member_id') == 30]
    ok([m['k'] for m in fromd] == ['cursor'], "a viewer's drag and editing never reach a peer; its cursor does")
    await d1.close()
    await asyncio.sleep(1.1)
    d2 = await open_sock(dana.ticket(live), 'tab-d2')
    n0 = len(b1.msgs)
    await asyncio.gather(*(d2.send({'t': 'cursor', 'x': k, 'y': 0}) for k in range(100)))
    await asyncio.sleep(0.6)
    burst = len([m for _, m in b1.msgs[n0:] if m.get('t') == 'peer' and m.get('member_id') == 30])
    ok(30 <= burst <= 32, f'100 messages at once → {burst} delivered (a 30-a-second bucket)')
    await b1.send({'t': 'cursor', 'x': 'nope', 'y': 1})
    await b1.send({'t': 'select', 'ids': ['not-a-uuid']})
    await b1.send({'t': 'shout', 'x': 1})
    await asyncio.sleep(0.3)
    ok(not [m for _, m in d2.msgs if m.get('t') == 'peer' and m.get('member_id') == 28], 'malformed and unknown messages are dropped, never relayed')
    sel = [str(uuid.uuid4())]
    await b1.send({'t': 'select', 'ids': sel})
    await b1.send({'t': 'editing', 'id': sel[0]})
    await b1.send({'t': 'drag', 'ids': sel, 'dx': 10, 'dy': -4})
    await asyncio.sleep(0.3)
    ks = [m['k'] for _, m in d2.msgs if m.get('t') == 'peer' and m.get('member_id') == 28]
    ok(ks == ['select', 'editing', 'drag'], "an editor's select, editing and drag reach the viewer's socket, as sent")
    await d2.close()

    print('5. a large batch, and order')
    big_ops = [sticky('x' * 300, k * 210) for k in range(40)]
    code, a = priya.commit(live, big_ops)
    got = await b1.wait(lambda m: m.get('t') == 'ops' and m.get('seq') == a['seq'], 3)
    ok(got is not None and len(got[1].get('ops', [])) == 40 and len(json.dumps(big_ops)) > 7500, f'a batch of {len(json.dumps(big_ops)) // 1024} kB (over NOTIFY\'s 7,500 bytes) arrives with all 40 ops, read by its seq')
    seqs = []

    def worker(text):
        seqs.append(priya.commit(live, [sticky(text)])[1]['seq'])
    for _ in range(5):
        ths = [threading.Thread(target=worker, args=(f'race{k}',)) for k in range(2)]
        for t in ths:
            t.start()
        for t in ths:
            t.join()
    await asyncio.sleep(0.5)
    arrived = [m['seq'] for _, m in b1.msgs if m.get('t') == 'ops' and m.get('seq') in seqs]
    ok(len(arrived) == 10 and arrived == sorted(arrived), 'concurrent commits on ONE board arrive in seq order (the board\'s row lock first)')

    print('6. revocation')
    b2 = await open_sock(bea.ticket(live), 'tab-b2')
    pg(f"SELECT set_config('app.member_id', '26', false) \\g /dev/null\nSELECT bd_board_share('{live}', 26, 'member', 28, 'comment') \\g /dev/null")
    t0 = time.perf_counter()
    lv = await b2.wait(lambda m: m.get('t') == 'level', CHECK + 2)
    ok(lv is not None and lv[1]['level'] == 'comment', f'her level lowered → the socket told level "comment" in {time.perf_counter() - t0:.1f} s (≤ {CHECK} + 2)')
    n0 = len(p1.msgs)
    await b2.send({'t': 'drag', 'ids': [str(uuid.uuid4())], 'dx': 1, 'dy': 1})
    await asyncio.sleep(0.3)
    ok(not [m for _, m in p1.msgs[n0:] if m.get('t') == 'peer' and m.get('k') == 'drag'], 'and her drag is dropped from then on')
    pg(f"SELECT set_config('app.member_id', '26', false) \\g /dev/null\nSELECT bd_board_share('{live}', 26, 'member', 28, 'edit') \\g /dev/null")
    t_trash = await open_sock(bea.ticket(trash), 'tab-t')
    pg(f"SELECT set_config('app.member_id', '26', false) \\g /dev/null\nSELECT bd_board_delete('{trash}', 26) \\g /dev/null")
    t0 = time.perf_counter()
    ok(await t_trash.closed(CHECK + 3) == 4403, f'the board trashed → closed 4403 in {time.perf_counter() - t0:.1f} s')
    code = subprocess.run(['php', str(ROOT / 'tests/phase3/slice3/lib.php'), 'signout', '28'], capture_output=True, text=True, env={**os.environ}).stdout.strip()
    t0 = time.perf_counter()
    c1, c2 = await asyncio.gather(b1.closed(CHECK + 3), b2.closed(CHECK + 3))
    took = time.perf_counter() - t0
    ok(code == '204' and c1 == 4403 and c2 == 4403 and took <= CHECK + 2, f'her sessions ended at the kernel (the sign-out notice, 204) → both her sockets closed 4403 in {took:.1f} s (≤ {CHECK} + 2)')
    ok(bea.post(f'/boards/{live}/live-ticket', form={})[0] in (401, 302), 'and the ticket door refuses her signed-out session')

    print('7. LISTEN lost, presence, idle')
    p2 = await open_sock(priya.ticket(live), 'tab-p2')
    pg("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = current_database() AND query LIKE 'LISTEN%' AND usename = 'boards_records_ro'")
    t0 = time.perf_counter()
    rs = await p2.wait(lambda m: m.get('t') == 'resync', 4)
    ok(rs is not None and rs[0] - t0 < 2, f'the relay\'s LISTEN backend killed → resync to the room in {((rs[0] - t0) if rs else 99):.2f} s (< 2)')
    code, a = priya.commit(live, [sticky('after the kill')])
    ok(await p2.wait(lambda m: m.get('t') == 'ops' and m.get('seq') == a['seq'], 3) is not None, 'and the next commit is delivered')
    pg(f"SELECT pg_notify('boards_presence', json_build_object('room', 'board:{live}', 'member_id', 42, 'name', 'SMOKE Facilitator', 'kind', 'agent', 'state', 'working', 'dispatch_id', 7)::text)")
    pr = await p2.wait(lambda m: m.get('t') == 'presence', 2)
    ok(pr is not None and pr[1]['member_id'] == 42 and pr[1]['state'] == 'working' and pr[1]['dispatch_id'] == 7, 'boards_presence notified → presence (an agent working) in the room')
    idle = await open_sock(priya.ticket(live), 'tab-idle', keepalive=False)
    ok(await idle.closed(int(os.environ.get('BOARDS_LIVE_IDLE_SECONDS', '90')) + 15) == 4408, 'a socket silent past the idle time (not even a ping): closed 4408')
    pg("UPDATE bd_settings SET live_enabled = false")
    await asyncio.sleep(0.2)
    code, j = priya.post(f'/boards/{live}/live-ticket', form={})
    ok(code == 200 and j.get('ticket') is None and j.get('live') is False, 'live updates off: the ticket door answers no ticket (a setting, not an error — the island polls)')
    ok(await p2.closed(CHECK + 3) == 4403 and 'off' in (p2.reason or '') + ''.join(m.get('reason', '') for m in p2.of('closing')), 'and the open sockets close 4403 "Live updates are off" at the next check')
    pg("UPDATE bd_settings SET live_enabled = true")
    await p1.close()
    print(f'{failed} FAILED ({passed} passed)' if failed else f'all {passed} passed')
    return failed


if __name__ == '__main__':
    sys.exit(1 if asyncio.run(main()) else 0)
