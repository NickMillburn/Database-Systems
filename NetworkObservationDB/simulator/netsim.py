#!/usr/bin/env python3
"""
netsim.py - fake traffic generator for the Network Observation Database.

It never touches the real network. It invents connection records that look like
a home network and inserts them into the `connection` table.

    netsim.py seed    fill in history up to now, then exit
    netsim.py run     fill in history up to now, then keep adding live rows

How it works:
  * FLOWS (below) is a list of traffic patterns, e.g. "nick-phone browses the
    web about 10 times an hour, mostly in the evening".
  * generate(t0, t1) walks the time range one hour at a time. For each flow it
    draws how many connections happened in that hour (Poisson) and builds a row
    for each one.
  * "seed" calls generate() once for the gap between the newest generated row
    and now (30 days if the table is empty).
  * "run" does the same, then calls generate() every few seconds for the few
    seconds that just passed. So history and live rows come from the same code.

Database settings come from environment variables (defaults match web/config.php):
    NETOBS_DB_HOST, NETOBS_DB_USER, NETOBS_DB_PASS, NETOBS_DB_NAME,
    NETOBS_DB_SOCKET, NETOBS_UTC_OFFSET

Needs PyMySQL:  sudo apt install python3-pymysql
"""

import argparse
import math
import os
import random
import signal
import sys
import time
import zlib
from datetime import datetime, timedelta, timezone

import pymysql

DB_HOST = os.environ.get("NETOBS_DB_HOST", "localhost")
DB_USER = os.environ.get("NETOBS_DB_USER", "jetson")
DB_PASS = os.environ.get("NETOBS_DB_PASS", "orin")
DB_NAME = os.environ.get("NETOBS_DB_NAME", "netobs")
DB_SOCKET = os.environ.get("NETOBS_DB_SOCKET", "/var/run/mysqld/mysqld.sock")
# Times are stored in UTC. This offset is only used to decide what "evening" is.
UTC_OFFSET_HOURS = float(os.environ.get("NETOBS_UTC_OFFSET", "-6"))

HISTORY_DAYS = 30        # how far back an empty database gets filled
RATE_SCALE = 2.0         # multiplies every flow's rate; about 1,100 connections a day at 2.0
RARE_NEW_ORG = 0.002     # chance an outbound connection goes to an org outside the device's usual list
EPOCH = datetime(1970, 1, 1)

# --------------------------------------------------------------------------
# The fake world. Device and organization names must match sql/seed.sql.
# --------------------------------------------------------------------------

# Activity by local hour (index 0 = midnight). 1.0 means the flow's full rate.
HUMAN = [0.15, 0.05, 0.02, 0.02, 0.02, 0.05, 0.2, 0.4, 0.4, 0.3, 0.3, 0.4,
         0.5, 0.4, 0.4, 0.5, 0.6, 0.8, 1.0, 1.0, 1.0, 0.9, 0.7, 0.4]
WORK = [0.05, 0.02, 0.02, 0.02, 0.02, 0.02, 0.1, 0.3, 0.6, 0.9, 1.0, 1.0,
        0.7, 0.9, 1.0, 0.9, 0.8, 0.6, 0.6, 0.7, 0.7, 0.5, 0.3, 0.1]
EVENING = [0.1, 0.02, 0.0, 0.0, 0.0, 0.0, 0.0, 0.05, 0.05, 0.05, 0.1, 0.15,
           0.2, 0.2, 0.2, 0.2, 0.3, 0.5, 0.8, 1.0, 1.0, 1.0, 0.7, 0.3]
SERVER = [0.5, 0.5, 0.8, 1.0, 0.8, 0.5, 0.5, 0.5, 0.5, 0.5, 0.5, 0.5,
          0.5, 0.5, 0.5, 0.5, 0.5, 0.5, 0.5, 0.5, 0.5, 0.5, 0.5, 0.5]
FLAT = [0.5] * 24
PEOPLE_PROFILES = (HUMAN, EVENING)   # these get busier on weekends

# LAN addresses. Servers are static; client devices get a DHCP lease that changes.
STATIC_IP = {
    "pve": "192.168.0.50", "jellyfin": "192.168.0.51", "qnap01": "192.168.0.60",
    "pihome-node-01": "192.168.0.250", "pi4-display": "192.168.0.45",
    "optiplex": "192.168.0.40", "printer": "192.168.0.30",
}
# device -> (first address of its 10-address pool, days a lease lasts)
DHCP = {
    "nick-desktop": (100, 14), "nick-laptop": (110, 5), "nick-phone": (120, 6),
    "guest-phone": (130, 3), "living-room-tv": (140, 20), "smart-plug": (150, 30),
    "unknown-device": (160, 30),
}
TAILSCALE_IP = {
    "nick-laptop": "100.84.12.2", "nick-phone": "100.84.12.3", "pve": "100.84.12.5",
    "jellyfin": "100.84.12.6", "pihome-node-01": "100.84.12.8", "optiplex": "100.84.12.10",
}
FRIEND_TAILSCALE_IPS = ["100.101.44.7", "100.101.44.19", "100.93.8.61"]

# A handful of outside addresses per organization, so the same ones repeat.
ORG_IPS = {
    "Google": ["142.250.72.14", "142.250.72.46", "142.250.190.78", "142.250.191.110", "8.8.8.8"],
    "Cloudflare": ["104.16.132.229", "104.16.133.229", "172.64.41.3", "162.159.192.1", "1.1.1.1"],
    "Amazon AWS": ["52.94.236.248", "52.94.228.167", "3.218.180.21", "54.239.28.85", "18.160.41.12"],
    "Microsoft": ["20.190.151.7", "20.190.159.2", "13.107.42.14", "52.96.165.18"],
    "Netflix": ["45.57.40.1", "45.57.41.1", "198.38.96.130", "198.38.97.140"],
    "Apple": ["17.253.144.10", "17.57.144.84", "17.248.190.71"],
    "Meta": ["157.240.2.35", "157.240.18.35", "31.13.66.35"],
    "Akamai": ["23.32.238.107", "23.32.238.121", "23.59.176.40"],
    "Valve": ["162.254.193.6", "162.254.193.47", "162.254.195.71"],
    "GitHub": ["140.82.112.3", "140.82.113.4", "140.82.114.22"],
    "Fastly": ["151.101.1.69", "151.101.65.69", "151.101.129.69", "146.75.78.132"],
    "Canonical": ["185.125.190.36", "185.125.190.39", "185.125.188.54"],
    "Comcast": ["73.44.118.203", "73.176.21.90", "98.213.5.44"],
    "Hetzner": ["95.216.24.32", "95.216.151.8", "65.108.75.112"],
    "OVH": ["51.68.141.20", "51.75.64.187", "54.36.108.162"],
    "DigitalOcean": ["164.90.201.18", "164.92.77.4", "167.99.180.250"],
    "Alibaba Cloud": ["47.88.62.101", "47.254.33.193", "8.209.96.14"],
    "China Telecom": ["218.92.0.31", "218.92.0.118", "61.177.172.140"],
}
# Port scans come from these. None = an address that is not in the organization list.
SCANNER_ORGS = ["China Telecom", "DigitalOcean", "OVH", "Hetzner", "Alibaba Cloud", None]
UNLISTED_IPS = ["193.32.162.77", "45.148.10.212", "185.224.128.43"]


def lognorm(median, sigma):
    """Random size around `median`. Bigger sigma = more spread."""
    return int(random.lognormvariate(math.log(median), sigma))


def ephemeral():
    return random.randint(32768, 60999)


# style -> function returning (service port, protocol, bytes my side sent,
#                              bytes my side received, duration in seconds)
# Internal and inbound flows carry their own port, so those styles return None for it.
STYLES = {
    "web": lambda: (443, "udp" if random.random() < 0.25 else "tcp",
                    lognorm(4000, 1.0), lognorm(150000, 1.5), random.uniform(1, 90)),
    "dns": lambda: (53, "udp", random.randint(60, 120), random.randint(120, 500), random.uniform(0, 1)),
    "ntp": lambda: (123, "udp", 76, 76, 0),
    "update": lambda: (443, "tcp", lognorm(50000, 0.5), lognorm(40000000, 1.0), random.uniform(20, 300)),
    "stream": lambda: (443, "tcp", lognorm(300000, 0.5), lognorm(600000000, 0.7), random.uniform(900, 5400)),
    "game": lambda: (random.randint(27015, 27050), "udp", lognorm(2000000, 0.6),
                     lognorm(8000000, 0.6), random.uniform(600, 5400)),
    "keepalive": lambda: (7844, "udp", lognorm(5000, 0.4), lognorm(5000, 0.4), random.uniform(30, 300)),
    "smb": lambda: (None, None, lognorm(200000, 2.0), lognorm(2000000, 2.0), random.uniform(5, 600)),
    "webui": lambda: (None, None, lognorm(20000, 0.6), lognorm(500000, 0.8), random.uniform(10, 300)),
    "ssh": lambda: (None, None, lognorm(30000, 0.8), lognorm(80000, 0.8), random.uniform(60, 3600)),
    "rdp": lambda: (None, None, lognorm(2000000, 0.6), lognorm(60000000, 0.7), random.uniform(300, 5400)),
    "music": lambda: (None, None, lognorm(50000, 0.5), lognorm(30000000, 0.6), random.uniform(600, 3600)),
    "watch": lambda: (None, None, lognorm(300000, 0.5), lognorm(900000000, 0.7), random.uniform(1200, 7200)),
    "backup": lambda: (None, None, lognorm(3000000000, 0.8), lognorm(2000000, 0.5), random.uniform(600, 3600)),
    "print": lambda: (None, None, lognorm(500000, 0.8), lognorm(5000, 0.3), random.uniform(5, 60)),
    "serve_video": lambda: (None, None, lognorm(700000000, 0.8), lognorm(400000, 0.5), random.uniform(1200, 7200)),
    "serve_music": lambda: (None, None, lognorm(30000000, 0.6), lognorm(60000, 0.5), random.uniform(600, 3600)),
    "serve_game": lambda: (None, None, lognorm(15000000, 0.7), lognorm(5000000, 0.7), random.uniform(900, 7200)),
    "scan": lambda: (None, None, random.randint(0, 200), random.randint(60, 400), random.uniform(0, 3)),
}

# Outbound: one of my devices talks to the internet.
# (device, interface type, connections per hour, hourly profile, style, {organization: weight})
OUTBOUND = [
    ("nick-desktop", "ethernet", 8.0, WORK, "web",
     {"Google": 5, "Cloudflare": 3, "GitHub": 3, "Microsoft": 2, "Amazon AWS": 2, "Fastly": 2, "Akamai": 1}),
    ("nick-desktop", "ethernet", 3.0, WORK, "dns", {"Cloudflare": 3, "Google": 1}),
    ("nick-desktop", "ethernet", 1.0, EVENING, "game", {"Valve": 1}),
    ("nick-desktop", "ethernet", 0.3, FLAT, "update", {"Microsoft": 2, "Valve": 1}),
    ("nick-laptop", "wifi", 6.0, HUMAN, "web",
     {"Google": 5, "GitHub": 3, "Cloudflare": 3, "Microsoft": 2, "Amazon AWS": 2, "Fastly": 1}),
    ("nick-laptop", "wifi", 2.0, HUMAN, "dns", {"Cloudflare": 3, "Google": 1}),
    ("nick-laptop", "wifi", 0.15, FLAT, "update", {"Canonical": 3, "Fastly": 1}),
    ("nick-phone", "wifi", 5.0, HUMAN, "web",
     {"Google": 5, "Meta": 3, "Cloudflare": 2, "Amazon AWS": 2, "Akamai": 2, "Fastly": 1}),
    ("nick-phone", "wifi", 1.5, HUMAN, "dns", {"Google": 3, "Cloudflare": 1}),
    ("guest-phone", "wifi", 2.0, HUMAN, "web", {"Meta": 4, "Apple": 4, "Google": 3, "Netflix": 1, "Akamai": 1}),
    ("living-room-tv", "wifi", 0.6, EVENING, "stream", {"Netflix": 5, "Google": 3, "Amazon AWS": 2}),
    ("living-room-tv", "wifi", 0.5, FLAT, "web", {"Google": 2, "Akamai": 2, "Amazon AWS": 1}),
    ("pve", "ethernet", 0.15, SERVER, "update", {"Fastly": 3, "Cloudflare": 1}),
    ("pve", "ethernet", 0.5, FLAT, "ntp", {"Cloudflare": 1}),
    ("pve", "ethernet", 0.5, FLAT, "dns", {"Cloudflare": 1}),
    ("jellyfin", "ethernet", 0.3, FLAT, "web", {"Fastly": 2, "Cloudflare": 2, "Amazon AWS": 1}),
    ("jellyfin", "ethernet", 0.3, FLAT, "keepalive", {"Cloudflare": 1}),   # the tunnel's own link out
    ("qnap01", "ethernet", 0.1, SERVER, "update", {"Amazon AWS": 1}),
    ("qnap01", "ethernet", 0.3, FLAT, "ntp", {"Google": 1}),
    ("pihome-node-01", "ethernet", 0.6, FLAT, "web", {"Cloudflare": 3, "Google": 2, "GitHub": 1}),
    ("pihome-node-01", "ethernet", 0.5, FLAT, "dns", {"Cloudflare": 1}),
    ("pi4-display", "wifi", 0.3, FLAT, "ntp", {"Cloudflare": 1}),
    ("pi4-display", "wifi", 0.1, FLAT, "web", {"Google": 1, "Fastly": 1}),
    ("optiplex", "ethernet", 0.2, SERVER, "update", {"Fastly": 3, "Canonical": 1}),
    ("optiplex", "ethernet", 0.4, FLAT, "dns", {"Cloudflare": 1}),
    ("printer", "ethernet", 0.05, FLAT, "web", {"Amazon AWS": 1}),
    ("smart-plug", "wifi", 0.6, FLAT, "web", {"Amazon AWS": 1}),
]

# Internal: one of my devices talks to a service on another of my devices.
# Stored once, from the interface that started it.
# (source device, interface type, target device, port, protocol, path, per hour, profile, style)
INTERNAL = [
    ("nick-desktop", "ethernet", "qnap01", 445, "tcp", "lan", 1.5, WORK, "smb"),
    ("nick-desktop", "ethernet", "pve", 445, "tcp", "lan", 0.8, WORK, "smb"),
    ("nick-desktop", "ethernet", "pve", 8006, "tcp", "lan", 0.4, WORK, "webui"),
    ("nick-desktop", "ethernet", "optiplex", 22, "tcp", "lan", 0.3, WORK, "ssh"),
    ("nick-desktop", "ethernet", "printer", 631, "tcp", "lan", 0.08, WORK, "print"),
    ("nick-laptop", "wifi", "jellyfin", 8096, "tcp", "lan", 0.5, HUMAN, "watch"),
    ("nick-laptop", "wifi", "pihome-node-01", 22, "tcp", "lan", 0.3, HUMAN, "ssh"),
    ("nick-laptop", "tailscale", "optiplex", 3389, "tcp", "tailscale", 0.25, WORK, "rdp"),
    ("nick-laptop", "tailscale", "pihome-node-01", 4533, "tcp", "tailscale", 0.3, WORK, "music"),
    ("nick-phone", "wifi", "pihome-node-01", 4533, "tcp", "lan", 0.6, HUMAN, "music"),
    ("nick-phone", "tailscale", "jellyfin", 8096, "tcp", "tailscale", 0.15, HUMAN, "watch"),
    ("living-room-tv", "wifi", "jellyfin", 8096, "tcp", "lan", 0.5, EVENING, "watch"),
    ("guest-phone", "wifi", "printer", 631, "tcp", "lan", 0.03, HUMAN, "print"),
    ("pve", "ethernet", "qnap01", 445, "tcp", "lan", 0.2, SERVER, "backup"),
]

# Inbound: someone outside reaches one of my devices.
# (device, interface type, ports to pick from, protocol, path, per hour, profile, style, who is calling)
# "who is calling" is an organization name, "friends" (Tailscale) or "scanners".
INBOUND = [
    ("jellyfin", "ethernet", [8096], "tcp", "cloudflare_tunnel", 0.5, EVENING, "serve_video", "Cloudflare"),
    ("jellyfin", "tailscale", [8096], "tcp", "tailscale", 0.3, EVENING, "serve_video", "friends"),
    ("pihome-node-01", "tailscale", [4533], "tcp", "tailscale", 0.2, HUMAN, "serve_music", "friends"),
    ("optiplex", "ethernet", [25565], "tcp", "port_forward", 0.4, EVENING, "serve_game", "Comcast"),
    # scans of ports nothing is registered on: these are the "unregistered port" oddities
    ("optiplex", "ethernet", [23, 2323, 5900, 8080, 8443, 1433], "tcp", "port_forward", 0.25, FLAT, "scan", "scanners"),
    ("jellyfin", "ethernet", [23, 554, 8080], "tcp", "port_forward", 0.08, FLAT, "scan", "scanners"),
]


def log(msg):
    print(msg, flush=True)


def utcnow():
    return datetime.now(timezone.utc).replace(tzinfo=None)


def poisson(lam):
    """How many events happen when `lam` are expected on average (Knuth's method)."""
    if lam <= 0:
        return 0
    limit = math.exp(-lam)
    k, p = 0, 1.0
    while True:
        p *= random.random()
        if p <= limit:
            return k
        k += 1


def lan_ip(device, ts):
    """The LAN address a device had at time ts. DHCP clients move around their pool."""
    if device in STATIC_IP:
        return STATIC_IP[device]
    first, lease_days = DHCP[device]
    lease_no = int((ts - EPOCH).total_seconds() // (lease_days * 86400))
    # crc32 is just a repeatable way to turn (device, lease number) into a pool slot
    slot = zlib.crc32(("%s-%d" % (device, lease_no)).encode()) % 10
    return "192.168.0.%d" % (first + slot)


def device_ip(device, if_type, ts):
    if if_type == "tailscale":
        return TAILSCALE_IP[device]
    return lan_ip(device, ts)


class World:
    """The reference tables, read from the database so ids and MACs always match it."""

    def __init__(self, conn):
        with conn.cursor() as cur:
            cur.execute("SELECT device_id, name FROM device")
            self.device_id = {name: dev_id for dev_id, name in cur.fetchall()}
            cur.execute("SELECT i.mac_address, d.name, i.if_type FROM interface i "
                        "JOIN device d ON d.device_id = i.device_id ORDER BY i.mac_address")
            self.mac = {}
            for mac, name, if_type in cur.fetchall():
                self.mac.setdefault((name, if_type), mac)
            cur.execute("SELECT device_id, port, protocol FROM service")
            self.services = set(cur.fetchall())
            cur.execute("SELECT org_id, org_name FROM organization")
            self.org_id = {name: org_id for org_id, name in cur.fetchall()}

    def service_key(self, device, port, protocol):
        """The (device_id, port, protocol) key if that service is registered, else three NULLs."""
        key = (self.device_id.get(device), port, protocol)
        return key if key in self.services else (None, None, None)


def build_row(world, kind, flow, end, source):
    """Turn one flow into one `connection` row ending at time `end`."""
    if kind == "outbound":
        device, if_type, _, _, style, orgs = flow
        port, protocol, sent, received, seconds = STYLES[style]()
        if random.random() < RARE_NEW_ORG:
            org = random.choice(list(ORG_IPS))            # the occasional never-seen-before organization
        else:
            org = random.choices(list(orgs), weights=list(orgs.values()))[0]
        if style == "dns":
            remote_ip = ORG_IPS[org][-1]                  # the public resolver (1.1.1.1 / 8.8.8.8)
        else:
            remote_ip = random.choice(ORG_IPS[org])
        start = end - timedelta(seconds=seconds)
        return (world.mac[(device, if_type)], world.org_id.get(org), None, None, None,
                device_ip(device, if_type, start), ephemeral(), remote_ip, port, protocol,
                start, end, sent, received, "outbound", "internet", source)

    if kind == "internal":
        device, if_type, target, port, protocol, path, _, _, style = flow
        _, _, sent, received, seconds = STYLES[style]()
        start = end - timedelta(seconds=seconds)
        target_ip = device_ip(target, "tailscale" if path == "tailscale" else "ethernet", start)
        return (world.mac[(device, if_type)], None) + world.service_key(target, port, protocol) + (
            device_ip(device, if_type, start), ephemeral(), target_ip, port, protocol,
            start, end, sent, received, "internal", path, source)

    # inbound
    device, if_type, ports, protocol, path, _, _, style, caller = flow
    _, _, sent, received, seconds = STYLES[style]()
    start = end - timedelta(seconds=seconds)
    port = random.choice(ports)
    org = None
    if caller == "friends":
        remote_ip = random.choice(FRIEND_TAILSCALE_IPS)
    else:
        org = random.choice(SCANNER_ORGS) if caller == "scanners" else caller
        remote_ip = random.choice(ORG_IPS[org]) if org else random.choice(UNLISTED_IPS)
    return (world.mac[(device, if_type)], world.org_id.get(org)) + world.service_key(device, port, protocol) + (
        device_ip(device, if_type, start), port, remote_ip, ephemeral(), protocol,
        start, end, sent, received, "inbound", path, source)


def usable_flows(world):
    """Every (kind, flow) whose devices and interfaces exist in the database."""
    flows = []
    for kind, table in (("outbound", OUTBOUND), ("internal", INTERNAL), ("inbound", INBOUND)):
        for flow in table:
            if (flow[0], flow[1]) not in world.mac:
                continue
            if kind == "internal" and flow[2] not in world.device_id:
                continue
            flows.append((kind, flow))
    return flows


def generate(world, t0, t1, source, speed=1.0):
    """All the fake connections that ended between t0 and t1 (UTC)."""
    rows = []
    flows = usable_flows(world)
    bucket = t0
    while bucket < t1:
        # work one clock hour (or the part of it inside the range) at a time
        next_hour = bucket.replace(minute=0, second=0, microsecond=0) + timedelta(hours=1)
        bucket_end = min(next_hour, t1)
        span = (bucket_end - bucket).total_seconds()
        local = bucket + timedelta(hours=UTC_OFFSET_HOURS)
        weekend = local.weekday() >= 5
        for kind, flow in flows:
            per_hour = flow[6] if kind == "internal" else flow[2] if kind == "outbound" else flow[5]
            profile = flow[7] if kind == "internal" else flow[3] if kind == "outbound" else flow[6]
            rate = per_hour * profile[local.hour] * RATE_SCALE * speed
            if weekend and profile in PEOPLE_PROFILES:
                rate *= 1.3
            for _ in range(poisson(rate * span / 3600.0)):
                end = bucket + timedelta(seconds=random.uniform(0, span))
                rows.append(build_row(world, kind, flow, end.replace(microsecond=0), source))
        bucket = bucket_end
    rows.sort(key=lambda r: r[11])   # by ended_at, so ids go up with time
    return rows


def planted_oddities(world, now):
    """A few things for the 'unusual activity' reports to find. Only added to a fresh database."""
    rows = []
    plants = [
        # (device, interface, organization, how many, within the last N hours)
        ("smart-plug", "wifi", "Alibaba Cloud", 6, 72),
        ("living-room-tv", "wifi", "Hetzner", 4, 48),
        ("unknown-device", "wifi", "China Telecom", 9, 40),
        ("unknown-device", "wifi", "Google", 7, 40),
    ]
    for device, if_type, org, count, hours in plants:
        if (device, if_type) not in world.mac:
            continue
        flow = (device, if_type, 0, FLAT, "web", {org: 1})
        for _ in range(count):
            end = now - timedelta(seconds=random.uniform(60, hours * 3600))
            rows.append(build_row(world, "outbound", flow, end.replace(microsecond=0), "generator"))
    return rows


INSERT_SQL = (
    "INSERT INTO connection (mac_address, org_id, svc_device_id, svc_port, svc_protocol, "
    "local_ip, local_port, remote_ip, remote_port, protocol, started_at, ended_at, "
    "bytes_sent, bytes_received, direction, access_path, source) "
    "VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)"
)


def insert(conn, rows):
    with conn.cursor() as cur:
        for i in range(0, len(rows), 2000):
            cur.executemany(INSERT_SQL, rows[i:i + 2000])
    conn.commit()


def connect():
    """Use the local socket when it is there (what a LAMP box has), otherwise TCP."""
    if DB_HOST in ("localhost", "127.0.0.1") and os.path.exists(DB_SOCKET):
        return pymysql.connect(unix_socket=DB_SOCKET, user=DB_USER, password=DB_PASS, database=DB_NAME)
    return pymysql.connect(host=DB_HOST, user=DB_USER, password=DB_PASS, database=DB_NAME)


def catch_up(conn, world):
    """Fill the gap between the newest generated row and now. Returns the time it filled up to."""
    now = utcnow().replace(microsecond=0)
    with conn.cursor() as cur:
        cur.execute("SELECT MAX(ended_at) FROM connection WHERE source IN ('generator', 'simulator')")
        newest = cur.fetchone()[0]
    oldest_allowed = now - timedelta(days=HISTORY_DAYS)
    fresh = newest is None
    start = oldest_allowed if fresh else max(newest, oldest_allowed)
    rows = generate(world, start, now, "generator")
    if fresh:
        rows += planted_oddities(world, now)
        rows.sort(key=lambda r: r[11])
    insert(conn, rows)
    log("caught up: %d connections from %s to %s UTC%s"
        % (len(rows), start, now, " (fresh database, added planted oddities)" if fresh else ""))
    return now


def describe(world, row):
    """One log line for a live connection."""
    names = {mac: "%s/%s" % key for key, mac in world.mac.items()}
    return "%-8s %-17s %-28s %s:%d -> %s:%d %s" % (
        row[14], row[15], names.get(row[0], row[0]), row[5], row[6], row[7], row[8], row[9])


def run(speed, tick):
    """Catch up, then add live connections forever. Reconnects if the database goes away."""
    stopping = []
    signal.signal(signal.SIGTERM, lambda *_: stopping.append(True))
    signal.signal(signal.SIGINT, lambda *_: stopping.append(True))
    while not stopping:
        try:
            conn = connect()
            world = World(conn)
            last = catch_up(conn, world)
            log("live: adding connections every %ds at %.1fx speed" % (tick, speed))
            loaded = time.time()
            while not stopping:
                time.sleep(tick)
                if time.time() - loaded > 60:      # pick up devices/services added on the Manage page
                    world = World(conn)
                    loaded = time.time()
                now = utcnow().replace(microsecond=0)
                rows = generate(world, last, now, "simulator", speed)
                if rows:
                    insert(conn, rows)
                    for row in rows:
                        log(describe(world, row))
                else:
                    conn.ping(reconnect=True)
                last = now
            conn.close()
        except pymysql.MySQLError as err:
            log("database problem (%s), retrying in 10s" % err)
            time.sleep(10)
    log("stopped")


def main():
    parser = argparse.ArgumentParser(description="Fake traffic for the Network Observation Database")
    sub = parser.add_subparsers(dest="command")
    sub.add_parser("seed", help="fill in history up to now, then exit")
    run_parser = sub.add_parser("run", help="fill in history, then keep adding live connections")
    run_parser.add_argument("--speed", type=float, default=1.0,
                            help="traffic multiplier for live mode (try 10 for a demo)")
    run_parser.add_argument("--tick", type=int, default=5, help="seconds between live batches")
    args = parser.parse_args()

    if args.command == "seed":
        conn = connect()
        catch_up(conn, World(conn))
        conn.close()
    elif args.command == "run":
        run(args.speed, max(1, args.tick))
    else:
        parser.print_help()
        sys.exit(1)


if __name__ == "__main__":
    main()
