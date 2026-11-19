-- Reference data: the devices, interfaces, services and organizations.
-- All of it is made up for the simulation (the MAC and IP addresses are not real).
-- Connections are not in this file. The simulator (simulator/netsim.py) fills those in.

USE netobs;

INSERT INTO device (device_id, name, device_type, owner, registered_on) VALUES
    (1,  'nick-desktop',    'desktop', 'nick',      '2026-09-01'),
    (2,  'nick-laptop',     'laptop',  'nick',      '2026-09-01'),
    (3,  'nick-phone',      'phone',   'nick',      '2026-09-01'),
    (4,  'guest-phone',     'phone',   'guest',     '2026-09-20'),
    (5,  'pve',             'server',  'nick',      '2026-09-01'),
    (6,  'jellyfin',        'server',  'nick',      '2026-09-12'),
    (7,  'qnap01',          'nas',     'nick',      '2026-09-01'),
    (8,  'pihome-node-01',  'pi',      'nick',      '2026-09-01'),
    (9,  'pi4-display',     'pi',      'nick',      '2026-09-05'),
    (10, 'optiplex',        'server',  'nick',      '2026-09-01'),
    (11, 'living-room-tv',  'tv',      'household', '2026-09-03'),
    (12, 'printer',         'printer', 'household', '2026-09-03'),
    (13, 'smart-plug',      'iot',     'household', '2026-10-02'),
    (14, 'unknown-device',  'unknown', NULL,        '2026-11-01');

-- Tailscale interfaces get the MAC that Tailscale assigns them (02:... = locally administered).
INSERT INTO interface (mac_address, device_id, if_type) VALUES
    ('3c:7c:3f:10:00:01', 1,  'ethernet'),
    ('a0:36:bc:10:00:02', 2,  'wifi'),
    ('02:54:53:00:00:02', 2,  'tailscale'),
    ('d4:3a:2c:10:00:03', 3,  'wifi'),
    ('02:54:53:00:00:03', 3,  'tailscale'),
    ('5c:e9:1e:10:00:04', 4,  'wifi'),
    ('00:23:24:10:00:05', 5,  'ethernet'),
    ('02:54:53:00:00:05', 5,  'tailscale'),
    ('bc:24:11:10:00:06', 6,  'ethernet'),
    ('02:54:53:00:00:06', 6,  'tailscale'),
    ('24:5e:be:10:00:07', 7,  'ethernet'),
    ('2c:cf:67:10:00:08', 8,  'ethernet'),
    ('02:54:53:00:00:08', 8,  'tailscale'),
    ('dc:a6:32:10:00:09', 9,  'wifi'),
    ('f8:b1:56:10:00:10', 10, 'ethernet'),
    ('02:54:53:00:00:10', 10, 'tailscale'),
    ('64:16:66:10:00:11', 11, 'wifi'),
    ('00:1b:a9:10:00:12', 12, 'ethernet'),
    ('84:f3:eb:10:00:13', 13, 'wifi'),
    ('7a:11:9c:10:00:14', 14, 'wifi');

INSERT INTO service (device_id, port, protocol, service_name) VALUES
    (5,  8006,  'tcp', 'Proxmox web UI'),
    (5,  22,    'tcp', 'SSH'),
    (5,  445,   'tcp', 'Samba'),
    (6,  8096,  'tcp', 'Jellyfin'),
    (7,  445,   'tcp', 'Samba'),
    (7,  8080,  'tcp', 'QNAP web UI'),
    (8,  4533,  'tcp', 'Navidrome'),
    (8,  22,    'tcp', 'SSH'),
    (9,  22,    'tcp', 'SSH'),
    (10, 3389,  'tcp', 'Remote Desktop'),
    (10, 22,    'tcp', 'SSH'),
    (10, 25565, 'tcp', 'Minecraft server'),
    (12, 631,   'tcp', 'Printing (IPP)');

INSERT INTO organization (org_id, org_name, country, asn) VALUES
    (1,  'Google',         'US', 15169),
    (2,  'Cloudflare',     'US', 13335),
    (3,  'Amazon AWS',     'US', 16509),
    (4,  'Microsoft',      'US', 8075),
    (5,  'Netflix',        'US', 2906),
    (6,  'Apple',          'US', 714),
    (7,  'Meta',           'US', 32934),
    (8,  'Akamai',         'US', 20940),
    (9,  'Valve',          'US', 32590),
    (10, 'GitHub',         'US', 36459),
    (11, 'Fastly',         'US', 54113),
    (12, 'Canonical',      'GB', 41231),
    (13, 'Comcast',        'US', 7922),
    (14, 'Hetzner',        'DE', 24940),
    (15, 'OVH',            'FR', 16276),
    (16, 'DigitalOcean',   'US', 14061),
    (17, 'Alibaba Cloud',  'CN', 45102),
    (18, 'China Telecom',  'CN', 4134);
