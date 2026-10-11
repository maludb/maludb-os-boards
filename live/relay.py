#!/usr/bin/env python3
"""Boards' live relay (docs/build-specs/live-relay.md, live.md — slice 3, the second exemplar; decision record §3.4, §6.1).

One asyncio process holding rooms of WebSockets in memory. It relays what must be live and nothing that must be kept: cursors,
selections, views, drag previews and the soft lock between the sockets of one room; every committed batch the moment it commits,
fanned out from PostgreSQL's LISTEN in commit order; who is here. It is NOT a writer: it connects as the records role, which holds no
write grant anywhere, and calls three SECURITY DEFINER reads — bd_live_check(), bd_live_batch(), bd_live_member(). Every edit is a POST
to a PHP handler and bd_commit_ops(). Rooms are strings and the transport knows nothing of boards: the five names below are the only
Boards-specific parts, so Spaces can run the same relay.

Run: mcp/venv/bin/python -I live/relay.py (deploy/boards-live.service). Config from config/.env (or the proofs' scratch file named by
BD_DEV_ENV when APP_ENV is not prod); the process environment wins. Logs to stderr — never a ticket, a key, a DSN or a message's contents.
SIGUSR1 writes the counters to the journal.
"""
import asyncio
import hashlib
import hmac
import json
import logging
import os
import re
import signal
import sys
import time
from pathlib import Path
from urllib.parse import parse_qs, urlparse

import asyncpg
from websockets.asyncio.server import broadcast, serve

# ---- the Boards-specific parts ----------------------------------------------------------------------------------------------
ROOM_PREFIX = 'board:'
OPS_CHANNEL = 'boards_ops'
PRESENCE_CHANNEL = 'boards_presence'
CHECK_SQL = 'SELECT bd_live_check($1::bigint, $2::uuid)'
MEMBER_SQL = 'SELECT name, kind FROM bd_live_member($1::bigint)'
BATCH_SQL = 'SELECT bd_live_batch($1::bigint)::text'
SETTINGS_SQL = ("SELECT bd_setting_int('room_max_sockets') AS room_max, bd_setting_int('live_check_seconds') AS check_seconds, "
                "bd_setting_bool('live_enabled') AS enabled")

# ---- the wire's fixed parts -------------------------------------------------------------------------------------------------
PALETTE = ['#e03131', '#1971c2', '#2f9e44', '#f08c00', '#9c36b5', '#0c8599', '#c2255c', '#5f3dc4', '#66a80f', '#e8590c', '#1864ab', '#862e9c']
LEVELS = ('view', 'comment', 'edit', 'full')
MAY_SEND = {'view': {'cursor', 'select', 'view'}, 'comment': {'cursor', 'select', 'view'},
            'edit': {'cursor', 'select', 'view', 'drag', 'editing'}, 'full': {'cursor', 'select', 'view', 'drag', 'editing'}}
MAX_MESSAGE = 64 * 1024
RATE_PER_SECOND = 30
TICKET_TTL_MAX = 120
UUID = re.compile(r'^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$')
CLIENT = re.compile(r'^[A-Za-z0-9_-]{1,64}$')

log = logging.getLogger('boards-live')


def load_env() -> dict:
    """config/.env (or the scratch file the proofs name), with the process environment over it."""
    root = Path(__file__).resolve().parent.parent
    path = root / 'config' / '.env'
    dev = os.environ.get('BD_DEV_ENV', '')
    if dev and os.environ.get('APP_ENV') != 'prod' and Path(dev).is_file():
        path = Path(dev)
    env = {}
    if path.is_file():
        for line in path.read_text().splitlines():
            line = line.strip()
            if not line or line.startswith('#') or '=' not in line:
                continue
            k, v = line.split('=', 1)
            v = v.strip()
            if len(v) >= 2 and v[0] == v[-1] and v[0] in '"\'':
                v = v[1:-1]
            env[k.strip()] = v
    env.update({k: v for k, v in os.environ.items() if k in env or k.startswith(('BOARDS_', 'DB_', 'MCP_RECORDS_'))})
    return env


ENV = load_env()
KEY = ENV.get('BOARDS_LIVE_KEY', '')
IDLE_SECONDS = int(ENV.get('BOARDS_LIVE_IDLE_SECONDS', '90'))     # live-relay: 90 s; the proofs lower it to see the close
PORT = int(ENV.get('BOARDS_LIVE_PORT', '8851'))
SETTINGS = {'room_max': 100, 'check_seconds': 60, 'enabled': True}
ROOMS: dict[str, set] = {}
USED_TICKETS: dict[str, int] = {}
QUEUE: asyncio.Queue = asyncio.Queue()
POOL: asyncpg.Pool | None = None
STATS = {'sockets': 0, 'relayed': 0, 'dropped': 0, 'ops': 0, 'resyncs': 0, 'closed_by_check': 0, 'refused': 0}


class Peer:
    """One socket in one room: who (from the ticket and the database, never the client), at what level, and its rate bucket."""
    __slots__ = ('ws', 'member_id', 'room', 'level', 'client', 'name', 'kind', 'colour', 'editing', 'last_seen', 'tokens', 'refill_at')

    def __init__(self, ws, member_id, room, level, client, name, kind):
        self.ws, self.member_id, self.room, self.level, self.client = ws, member_id, room, level, client
        self.name, self.kind = name, kind
        self.colour = PALETTE[member_id % len(PALETTE)]
        self.editing = None
        self.last_seen = time.monotonic()
        self.tokens = float(RATE_PER_SECOND)
        self.refill_at = time.monotonic()

    def public(self) -> dict:
        return {'member_id': self.member_id, 'name': self.name, 'kind': self.kind, 'colour': self.colour, 'client': self.client, 'editing': self.editing}

    def allow(self) -> bool:
        """The 30-a-second token bucket: a message beyond it is dropped, counted, never answered."""
        now = time.monotonic()
        self.tokens = min(float(RATE_PER_SECOND), self.tokens + (now - self.refill_at) * RATE_PER_SECOND)
        self.refill_at = now
        if self.tokens < 1:
            return False
        self.tokens -= 1
        return True


def verify_ticket(ticket: str):
    """{member}.{room}.{level}.{exp}.{nonce}.{hmac} — signed with BOARDS_LIVE_KEY, unexpired, accepted ONCE. → (member, room, level) | None.
    (The nonce keeps two tickets minted in one second apart: without it a second tab's ticket was the first's string, refused as reused.)"""
    parts = ticket.split('.')
    if len(parts) != 6 or not KEY:
        return None
    member, room, level, exp, nonce, mac = parts
    if not member.isdigit() or not exp.isdigit() or level not in LEVELS or not room.startswith(ROOM_PREFIX) \
            or not UUID.match(room[len(ROOM_PREFIX):]) or not re.fullmatch(r'[0-9a-f]{16}', nonce):
        return None
    now = int(time.time())
    if int(exp) < now or int(exp) > now + TICKET_TTL_MAX:
        return None
    expected = hmac.new(KEY.encode(), f'{member}.{room}.{level}.{exp}.{nonce}'.encode(), hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, mac) or ticket in USED_TICKETS:
        return None
    USED_TICKETS[ticket] = int(exp)
    return int(member), room, level


def board_of(room: str) -> str:
    return room[len(ROOM_PREFIX):]


def send_room(room: str, message: dict, skip=None) -> None:
    peers = [p.ws for p in ROOMS.get(room, ()) if p is not skip]
    if peers:
        broadcast(peers, json.dumps(message, separators=(',', ':')))


async def close_peer(peer: Peer, code: int, reason: str) -> None:
    try:
        await peer.ws.send(json.dumps({'t': 'closing', 'code': code, 'reason': reason}))
    except Exception:
        pass
    await peer.ws.close(code, reason)


def clean(t: str, msg: dict):
    """The fields each message may carry, typed — nothing else is relayed. None when it is not well formed."""
    num = lambda v: isinstance(v, (int, float)) and not isinstance(v, bool) and abs(v) < 1e9
    ids = lambda v: isinstance(v, list) and len(v) <= 500 and all(isinstance(i, str) and UUID.match(i) for i in v)
    if t == 'cursor':
        return {'x': msg['x'], 'y': msg['y']} if num(msg.get('x')) and num(msg.get('y')) else None
    if t == 'select':
        return {'ids': msg['ids']} if ids(msg.get('ids')) else None
    if t == 'view':
        return {k: msg[k] for k in ('x', 'y', 'zoom')} if all(num(msg.get(k)) for k in ('x', 'y', 'zoom')) else None
    if t == 'editing':
        v = msg.get('id')
        return {'id': v} if v is None or (isinstance(v, str) and UUID.match(v)) else None
    if t == 'drag':
        if isinstance(msg.get('items'), list):
            items = msg['items']
            keys = ('x', 'y', 'width', 'height', 'rotation')
            if len(items) > 50 or not all(isinstance(i, dict) and isinstance(i.get('id'), str) and UUID.match(i['id'])
                                          and all(num(i.get(k)) for k in keys) for i in items):
                return None
            return {'items': [{'id': i['id'], **{k: i[k] for k in keys}} for i in items]}
        if ids(msg.get('ids')) and num(msg.get('dx')) and num(msg.get('dy')):
            return {'ids': msg['ids'], 'dx': msg['dx'], 'dy': msg['dy']}
    return None


async def handler(ws) -> None:
    """One socket: the ticket, the room, then its messages until it goes."""
    qs = parse_qs(urlparse(ws.request.path).query)
    claim = verify_ticket(qs.get('ticket', [''])[0])
    client = qs.get('client', [''])[0]
    if claim is None or not CLIENT.match(client):
        STATS['refused'] += 1
        await ws.close(4401, 'ticket')
        return
    member_id, room, level = claim
    if not SETTINGS['enabled']:
        await ws.close(4403, 'Live updates are off')
        return
    try:
        now_level = await POOL.fetchval(CHECK_SQL, member_id, board_of(room))
        who = await POOL.fetchrow(MEMBER_SQL, member_id)
    except Exception as exc:          # the database is unreachable: the client polls and tries again
        log.warning('admission read failed: %s', type(exc).__name__)
        await ws.close(1011, 'unavailable')
        return
    if now_level is None or who is None:
        await ws.close(4403, 'You can no longer open this board')
        return
    peers = ROOMS.setdefault(room, set())
    if len(peers) >= SETTINGS['room_max']:
        STATS['refused'] += 1
        await ws.send(json.dumps({'t': 'closing', 'code': 4429, 'reason': 'This board is full'}))
        await ws.close(4429, 'This board is full')
        if not peers:
            ROOMS.pop(room, None)
        return
    peer = Peer(ws, member_id, room, now_level, client, who['name'], who['kind'])
    peers.add(peer)
    STATS['sockets'] += 1
    try:
        await ws.send(json.dumps({'t': 'welcome', 'you': peer.public(), 'level': peer.level, 'room': room,
                                  'peers': [p.public() for p in peers if p is not peer]}))
        send_room(room, {'t': 'join', 'peer': peer.public()}, skip=peer)
        async for raw in ws:
            peer.last_seen = time.monotonic()
            if not isinstance(raw, str) or not peer.allow():
                STATS['dropped'] += 1
                continue
            try:
                msg = json.loads(raw)
            except ValueError:
                STATS['dropped'] += 1
                continue
            t = msg.get('t') if isinstance(msg, dict) else None
            if t == 'ping':
                await ws.send('{"t":"pong"}')
                continue
            if t not in MAY_SEND.get(peer.level, ()):
                STATS['dropped'] += 1
                continue
            d = clean(t, msg)
            if d is None:
                STATS['dropped'] += 1
                continue
            if t == 'editing':
                peer.editing = d['id']
            STATS['relayed'] += 1
            send_room(room, {'t': 'peer', 'k': t, 'member_id': peer.member_id, 'client': peer.client, 'd': d}, skip=peer)
    except Exception as exc:
        if type(exc).__name__ not in ('ConnectionClosedOK', 'ConnectionClosedError', 'ConnectionClosed'):
            log.warning('socket error: %s', type(exc).__name__)
    finally:
        peers.discard(peer)
        STATS['sockets'] -= 1
        if peers:
            send_room(room, {'t': 'leave', 'member_id': peer.member_id, 'client': peer.client})
        else:
            ROOMS.pop(room, None)


def on_notify(_conn, _pid, channel, payload) -> None:
    """The LISTEN callback only queues: ONE consumer fans out, so a room gets batches in commit order."""
    QUEUE.put_nowait((channel, payload))


async def fan_out_ops() -> None:
    """The one consumer: a committed batch to its room (completed by seq when NOTIFY could not carry it), presence, resync."""
    while True:
        channel, payload = await QUEUE.get()
        if channel == '__resync__':
            STATS['resyncs'] += 1
            for room in list(ROOMS):
                send_room(room, {'t': 'resync'})
            continue
        try:
            data = json.loads(payload)
        except ValueError:
            continue
        room = data.get('room')
        if room not in ROOMS:
            continue
        if channel == OPS_CHANNEL:
            msg = {'t': 'ops', 'seq': data.get('seq'), 'member_id': data.get('member_id'), 'source': data.get('source'),
                   'client_id': data.get('client_id'), 'summary': data.get('summary') or {}}
            if 'ops' in data:
                msg['ops'] = data['ops']
            else:                            # over NOTIFY's 8 kB: read the batch by its seq (if that fails, the client asks ops?after=)
                try:
                    text = await POOL.fetchval(BATCH_SQL, int(data['seq']))
                    if text is not None:
                        msg['ops'] = json.loads(text)
                except Exception as exc:
                    log.warning('batch read failed: %s', type(exc).__name__)
            STATS['ops'] += 1
            send_room(room, msg)
        elif channel == PRESENCE_CHANNEL:
            send_room(room, {'t': 'presence', 'member_id': data.get('member_id'), 'name': data.get('name'), 'kind': data.get('kind', 'agent'),
                             'state': data.get('state'), 'dispatch_id': data.get('dispatch_id')})


async def connect() -> asyncpg.Connection:
    return await asyncpg.connect(host=ENV.get('DB_HOST', '127.0.0.1'), port=int(ENV.get('DB_PORT', '5432')), database=ENV.get('DB_NAME'),
                                 user=ENV.get('MCP_RECORDS_DB_USER'), password=ENV.get('MCP_RECORDS_DB_PASSWORD'), ssl=False)


async def listen_forever() -> None:
    """LISTEN on both channels; when the connection goes, reconnect (0.5 s, then up to 5 s) and tell every room to resync."""
    delay = 0.5
    first = True
    while True:
        conn = None
        try:
            conn = await connect()
            lost = asyncio.get_running_loop().create_future()
            conn.add_termination_listener(lambda _c: lost.done() or lost.set_result(None))
            await conn.add_listener(OPS_CHANNEL, on_notify)
            await conn.add_listener(PRESENCE_CHANNEL, on_notify)
            if not first:
                log.info('LISTEN reconnected')
                QUEUE.put_nowait(('__resync__', ''))
            first = False
            delay = 0.5
            while not lost.done():                       # a killed backend ends the connection; check it as well as wait for it
                try:
                    await asyncio.wait_for(asyncio.shield(lost), 1.0)
                except asyncio.TimeoutError:
                    if conn.is_closed():
                        break
            log.warning('LISTEN connection lost')
        except Exception as exc:
            log.warning('LISTEN failed: %s', type(exc).__name__)
        finally:
            if conn is not None and not conn.is_closed():
                conn.terminate()
        await asyncio.sleep(delay)
        delay = min(5.0, delay * 2)


async def read_settings() -> None:
    try:
        row = await POOL.fetchrow(SETTINGS_SQL)
        SETTINGS.update(room_max=int(row['room_max']), check_seconds=int(row['check_seconds']), enabled=bool(row['enabled']))
    except Exception as exc:
        log.warning('settings read failed: %s', type(exc).__name__)


async def check_forever() -> None:
    """Every live_check_seconds: each (member, board) among the open sockets asked bd_live_check() once — gone → 4403, a new level →
    `level`; live updates off → 4403; the settings re-read; expired tickets swept."""
    while True:
        await asyncio.sleep(SETTINGS['check_seconds'])
        await read_settings()
        now = int(time.time())
        for t in [t for t, exp in USED_TICKETS.items() if exp < now]:
            del USED_TICKETS[t]
        pairs: dict[tuple, list] = {}
        for room, peers in list(ROOMS.items()):
            for p in list(peers):
                pairs.setdefault((p.member_id, board_of(room)), []).append(p)
        for (member_id, board), peers in pairs.items():
            if not SETTINGS['enabled']:
                for p in peers:
                    await close_peer(p, 4403, 'Live updates are off')
                continue
            try:
                level = await POOL.fetchval(CHECK_SQL, member_id, board)
            except Exception as exc:
                log.warning('check failed: %s', type(exc).__name__)
                continue
            for p in peers:
                if level is None:
                    STATS['closed_by_check'] += 1
                    log.info('closed by the check: member %s, board %s', member_id, board)
                    await close_peer(p, 4403, 'You can no longer open this board')
                elif level != p.level:
                    p.level = level
                    if p.level not in ('edit', 'full'):
                        p.editing = None
                    try:
                        await p.ws.send(json.dumps({'t': 'level', 'level': level}))
                    except Exception:
                        pass


async def sweep_forever() -> None:
    """A socket silent for 90 s (not even a ping) is closed 4408."""
    while True:
        await asyncio.sleep(10)
        cutoff = time.monotonic() - IDLE_SECONDS
        for peers in list(ROOMS.values()):
            for p in [p for p in peers if p.last_seen < cutoff]:
                await close_peer(p, 4408, 'idle')


def report(*_args) -> None:
    log.info('stats: rooms %d, %s', len(ROOMS), ', '.join(f'{k} {v}' for k, v in STATS.items()))


async def main() -> None:
    global POOL
    logging.basicConfig(level=logging.INFO, stream=sys.stderr, format='%(asctime)s %(levelname)s %(message)s')
    logging.getLogger('websockets').setLevel(logging.WARNING)      # no line a connection: the journal keeps starts, reconnects, closes by the check
    if not KEY or len(KEY) < 32:
        raise SystemExit('BOARDS_LIVE_KEY is not configured')
    POOL = await asyncpg.create_pool(host=ENV.get('DB_HOST', '127.0.0.1'), port=int(ENV.get('DB_PORT', '5432')), database=ENV.get('DB_NAME'),
                                     user=ENV.get('MCP_RECORDS_DB_USER'), password=ENV.get('MCP_RECORDS_DB_PASSWORD'), ssl=False,
                                     min_size=1, max_size=4)
    await read_settings()
    asyncio.get_running_loop().add_signal_handler(signal.SIGUSR1, report)
    async with serve(handler, '127.0.0.1', PORT, max_size=MAX_MESSAGE, ping_interval=20, ping_timeout=20):
        log.info('listening on 127.0.0.1:%d', PORT)
        # every background task HELD: create_task keeps only a weak reference (the spike's lost LISTEN); one ending raises → systemd restarts
        tasks = [asyncio.create_task(f()) for f in (listen_forever, fan_out_ops, check_forever, sweep_forever)]
        await asyncio.gather(*tasks)


if __name__ == '__main__':
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        pass
