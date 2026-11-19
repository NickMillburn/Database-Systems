# Network Observation Database

Database Systems course project. A MySQL database of the connections on a home
network, a PHP web interface to query it and add to it, and a simulator that
keeps it filled with fake traffic. Runs on a LAMP server (the Jetson Orin Nano).

All of the data is simulated. Nothing here reads or changes the real network.

## What is in this folder

| Path | What it is |
|------|------------|
| `sql/schema.sql` | Creates the `netobs` database and its five tables |
| `sql/seed.sql` | The devices, interfaces, services and organizations |
| `sql/queries.sql` | The eight proposal questions as plain SQL, for phpMyAdmin |
| `web/` | The PHP pages (front end) |
| `simulator/netsim.py` | Generates fake connections: a month of history, then live rows |
| `deploy/install.sh` | One-command setup for the laptop or the Jetson |
| `deploy/netobs-sim.service` | systemd unit that runs the simulator on boot |
| `deploy/run_dev.sh` | Runs the pages and simulator without Apache or systemd |
| `deploy/uninstall.sh` | Removes the service and web pages again |

## Setup

Same command on the laptop and on the Jetson (both Ubuntu):

```
sudo bash deploy/install.sh
```

It does the following, skipping anything already in place:

1. Installs missing packages: Apache, PHP, the PHP MySQL driver, PyMySQL, and
   MariaDB **only if no database server is installed yet**.
2. Creates the `netobs` database and the `jetson` / `orin` login the class uses.
3. Loads `schema.sql` and `seed.sql` if the tables do not exist yet.
4. Runs `netsim.py seed` to fill in 30 days of connections.
5. Copies `web/` to `/var/www/html/netobs/`.
6. Installs phpMyAdmin if it is missing.
7. Installs and starts the `netobs-sim` service.

Then open:

- `http://<address>/netobs/` for the project pages
- `http://<address>/phpmyadmin/` and log in as `jetson` / `orin`

Other ways to run it:

```
sudo bash deploy/install.sh --reset    # wipe the tables and start over
sudo bash deploy/install.sh --dev      # database only, no Apache / phpMyAdmin / service
bash deploy/run_dev.sh                 # after --dev: pages on http://localhost:8080/
bash deploy/run_dev.sh 20              # same, with 20x traffic
```

To move it to the Jetson: push, `git clone` (or `git pull`) on the Jetson, and run
the install command there. After pulling a change, run it again. It recopies the
pages and restarts the service without touching the data.

## The simulator service

```
systemctl status netobs-sim          # is it running
journalctl -u netobs-sim -f          # watch connections being added
sudo systemctl stop netobs-sim       # pause
sudo systemctl start netobs-sim      # resume
```

It starts on boot. Every time it starts it first fills in the gap since the
newest generated row, so if the Jetson was off for three days the three days
get filled in and "this week" reports still have data.

Normal speed is about one connection a minute (more in the evening). For a
demo where rows should visibly pour in:

```
sudo systemctl stop netobs-sim
python3 /opt/netobs/netsim.py run --speed 20      # Ctrl-C when done
sudo systemctl start netobs-sim
```

The dashboard's "Watch live" link reloads every 5 seconds to show them arriving.

## Schema

Same five tables as the UML and schema deliverable. The only changes are the
PostgreSQL types, because LAMP means MySQL:

| Deliverable (PostgreSQL) | Here (MySQL) | Why |
|---|---|---|
| `MACADDR` | `CHAR(17)` | MySQL has no MAC type; `aa:bb:cc:dd:ee:ff` is 17 characters |
| `INET` | `VARCHAR(45)` | No IP type; 45 fits the longest IPv6 text |
| `TIMESTAMPTZ` | `DATETIME` | Stored in UTC, shifted to local time when displayed |
| `VARCHAR` direction / access_path | `ENUM` | The database rejects values outside the list |

```
device(device_id, name, device_type, owner, registered_on)
interface(mac_address, device_id -> device, if_type)
service(device_id -> device, port, protocol, service_name)
organization(org_id, org_name, country, asn)
connection(connection_id, mac_address -> interface, org_id -> organization (nullable),
           (svc_device_id, svc_port, svc_protocol) -> service (nullable),
           local_ip, local_port, remote_ip, remote_port, protocol,
           started_at, ended_at, bytes_sent, bytes_received,
           direction, access_path, source)
```

How each relationship from the ER diagram became a foreign key:

- **HAS** (device 1:N interface): `interface.device_id`
- **RUNS** (device 1:N service, identifying): `service.device_id`, which is also
  part of the service's primary key because service is a weak entity
- **CONNECTS** (interface 1:N connection, total): `connection.mac_address`, NOT NULL
- **BELONGS TO** (organization 1:N connection, partial): `connection.org_id`, nullable
- **TARGETS** (service 1:N connection, partial): the three `svc_` columns, nullable.
  Three columns because the service key is three columns.

`local_*` is always my interface's end and `remote_*` is the other end. An
internal connection is stored once, from the interface that started it.

## Web pages

All pages use `mysqli` with prepared statements, the same way as the DMV pages
from class. User input only ever reaches SQL through `bind_param`.

| File | What it does |
|------|--------------|
| `config.php` | Database login and the local time offset |
| `db.php` | Opens the connection. `rows()` runs a SELECT, `run()` runs an INSERT/UPDATE, `show_table()` prints results as HTML, `page_header()` prints the nav |
| `index.php` | Dashboard: row counts, last 24 hours, newest connections |
| `reports.php` | **Query interface.** The eight questions |
| `connections.php` | The connection log with filters. Reports link here to drill down |
| `add_connection.php` | **Add interface.** Log one connection by hand |
| `manage.php` | **Add / modify interface.** Devices, interfaces, services, organizations |

### Query interface (reports.php)

Each question is one query. All of them join at least two tables. The page has
a "Show the SQL this ran" section under every result.

| # | Tables joined | Technique worth pointing out |
|---|---|---|
| 1 | connection, interface, device | GROUP BY + COUNT / SUM |
| 2 | connection, interface | Filter on a device, group by access path |
| 3 | connection, organization, interface | COUNT(DISTINCT), LIMIT 10 |
| 4 | connection, service, device | Join on the three-column service key |
| 5 | connection, interface, device | Subqueries: count per hour, max per device, join them |
| 6 | connection, interface, device, organization | NOT EXISTS for "never before" |
| 7 | connection, interface, device, organization | NULL foreign key + LEFT JOIN |
| 8 | connection, interface | IP history rebuilt from the log with MIN / MAX |

### Add and modify (the non-trivial transactions)

**Log a connection** (`add_connection.php`). The form asks for what a log line
would contain. Before inserting, the page:

1. Looks up the chosen MAC in `interface` and `device` to find the device.
2. If no local IP was typed, reads the interface's latest one from `connection`.
3. Looks in `service` for a match on device + port + protocol. For inbound
   that is this device and the local port. For internal it first finds which
   device last used the remote IP, then checks that device's services.
4. Looks in `connection` and `organization` for who already owns the remote IP.
5. Inserts into `connection` with those links filled in.

So one insert reads from all five tables. It also refuses combinations that make no
sense (an outbound connection over `lan`, for example).

**Register a service** (`manage.php`). One transaction that does an INSERT and
an UPDATE: it adds the service, then links every earlier inbound connection
that hit that device and port (they had no service to point at before). Good
demo: open report 7, note a port such as 23 on optiplex, register it as a
service, and those rows are gone from report 7.

**Register a device** (`manage.php`). One transaction that inserts the device
and its first interface. If the MAC is already taken, the rollback removes the
device row too.

**Label an outside IP** (`manage.php`). An UPDATE that sets the organization on
every connection to that address.

## How the simulator works

`netsim.py` has a list of traffic patterns (`OUTBOUND`, `INTERNAL`, `INBOUND`),
for example "nick-phone browses the web about 10 times an hour at its evening
peak, mostly to Google and Meta". Each pattern has an hourly activity
profile, a style (which sets ports, byte sizes and duration), and a rate.

`generate(t0, t1)` walks a time range one hour at a time. For each pattern it
draws how many connections happened in that hour from a Poisson distribution
(the usual model for "events at an average rate") and builds a row for each.

- `seed` calls it once for the last 30 days, or for the gap since the newest row.
- `run` does the same, then calls it every 5 seconds for the 5 seconds that just
  passed. History and live rows come out of the same code.

Details that make the reports have something to show:

- **DHCP**: client devices change LAN address every few days (report 8).
- **Port scans**: inbound hits on ports with no registered service (report 7).
- **New organizations**: a fresh database gets a few planted first-time
  contacts in the last three days, and about 1 in 500 outbound connections goes
  somewhere the device does not normally go (report 6).
- **Unknown device**: one device of type `unknown` with a handful of connections.
- **Tailscale and Cloudflare Tunnel**: inbound Jellyfin traffic split across
  both, so report 2 has two paths to compare.

It reads devices, MACs, services and organizations from the database at
startup and once a minute, so its rows always match the tables. A device added
on the Manage page gets no simulated traffic unless a pattern is added for it.

`source` is `generator` for history, `simulator` for live rows, and `manual`
for rows entered on the web page.

## Walkthrough order for the TA

1. `sql/schema.sql`: tables, keys, how each ER relationship became a foreign key.
2. phpMyAdmin: show the tables are populated. Paste a query from `sql/queries.sql` into the SQL tab.
3. `web/db.php`, then `web/reports.php`: run report 6 or 7 and open "Show the SQL".
4. `web/add_connection.php`: log the example connection from the hint on the page and show the links it filled in.
5. `web/manage.php`: register a service and show report 7 change.
6. `journalctl -u netobs-sim -f` next to the dashboard's "Watch live".

## What was and was not tested

Tested in a clean Ubuntu 24.04 container, against both MariaDB 10.11 and
MySQL 8.0, with PHP 8.3 and Python 3.13: the schema and seed load, all eight
queries (with strict GROUP BY on), every page, every form including bad input
and the rollback, the simulator in seed and live mode, the install script
(first run, rerun, `--dev`, `--reset`), Apache serving `/netobs/`, and logging
in to phpMyAdmin as `jetson`.

Not tested, because that container has no systemd and is not a Jetson:

- The `netobs-sim` service actually starting under systemd. The unit file
  passes `systemd-analyze verify`, and the same command it runs was tested by hand.
- Anything on ARM / JetPack. The code avoids anything newer than PHP 7.4 and
  Python 3.6 syntax so the older versions JetPack ships should run it, but
  that is by design, not by test.
- `deploy/uninstall.sh` was run there with a stand-in for `systemctl`, so the
  file and database removal is tested but the service removal is not.

## Not built (was in the proposal)

- CSV upload of connection records.
- Editing or deleting existing rows from the web pages (phpMyAdmin can do it).
