-- The eight questions from the proposal as plain SQL.
-- These are the same queries the Reports page runs (web/reports.php), with the
-- page's inputs filled in, so they can be pasted into phpMyAdmin's SQL tab.
-- Times are stored in UTC, so "now" is UTC_TIMESTAMP(), not NOW().

USE netobs;

-- 1. Which device made the most outbound connections this week?
SELECT d.name, COUNT(*) AS connections, SUM(c.bytes_sent) AS bytes_sent
FROM connection c
JOIN interface i ON i.mac_address = c.mac_address
JOIN device d ON d.device_id = i.device_id
WHERE c.direction = 'outbound'
  AND c.started_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY
GROUP BY d.device_id, d.name
ORDER BY connections DESC;

-- 2. How much data did the media server send outside the house last month,
--    split by Cloudflare Tunnel vs. Tailscale?
SELECT c.access_path, COUNT(*) AS connections, SUM(c.bytes_sent) AS bytes_sent
FROM connection c
JOIN interface i ON i.mac_address = c.mac_address
JOIN device d ON d.device_id = i.device_id
WHERE d.name = 'jellyfin'
  AND c.direction = 'inbound'
  AND c.started_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY
GROUP BY c.access_path
ORDER BY bytes_sent DESC;

-- 3. What are the top 10 outside organizations my devices talk to?
SELECT o.org_name, o.country, COUNT(*) AS connections,
       COUNT(DISTINCT i.device_id) AS devices,
       SUM(c.bytes_sent + c.bytes_received) AS total_bytes
FROM connection c
JOIN organization o ON o.org_id = c.org_id
JOIN interface i ON i.mac_address = c.mac_address
WHERE c.direction = 'outbound'
  AND c.started_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY
GROUP BY o.org_id, o.org_name, o.country
ORDER BY connections DESC
LIMIT 10;

-- 4. Which services were reached from outside the house, and through which path?
SELECT d.name AS device, s.service_name, s.port, s.protocol, c.access_path,
       COUNT(*) AS connections, MAX(c.started_at) AS last_seen_utc
FROM connection c
JOIN service s ON s.device_id = c.svc_device_id
              AND s.port = c.svc_port
              AND s.protocol = c.svc_protocol
JOIN device d ON d.device_id = s.device_id
WHERE c.direction = 'inbound'
  AND c.started_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY
GROUP BY d.name, s.service_name, s.port, s.protocol, c.access_path
ORDER BY connections DESC;

-- 5. What time of day is each device most active?
--    Inner query "h" counts connections per device per local hour.
--    Inner query "m" finds each device's biggest count. Joining them keeps the busiest hour.
--    (-06:00 is US Central standard time.)
SELECT h.name, h.local_hour, h.connections
FROM (
    SELECT d.device_id, d.name,
           HOUR(CONVERT_TZ(c.started_at, '+00:00', '-06:00')) AS local_hour,
           COUNT(*) AS connections
    FROM connection c
    JOIN interface i ON i.mac_address = c.mac_address
    JOIN device d ON d.device_id = i.device_id
    WHERE c.started_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY
    GROUP BY d.device_id, d.name, local_hour
) h
JOIN (
    SELECT device_id, MAX(connections) AS busiest
    FROM (
        SELECT i.device_id,
               HOUR(CONVERT_TZ(c.started_at, '+00:00', '-06:00')) AS local_hour,
               COUNT(*) AS connections
        FROM connection c
        JOIN interface i ON i.mac_address = c.mac_address
        WHERE c.started_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY
        GROUP BY i.device_id, local_hour
    ) per_hour
    GROUP BY device_id
) m ON m.device_id = h.device_id AND m.busiest = h.connections
ORDER BY h.name;

-- 6. Which devices contacted an outside organization this week that they had
--    never contacted before?
--    NOT EXISTS throws out any device + organization pair that also shows up
--    before the week started.
SELECT d.name AS device, o.org_name, o.country,
       MIN(c.started_at) AS first_contact_utc, COUNT(*) AS connections
FROM connection c
JOIN interface i ON i.mac_address = c.mac_address
JOIN device d ON d.device_id = i.device_id
JOIN organization o ON o.org_id = c.org_id
WHERE c.direction = 'outbound'
  AND c.started_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY
  AND NOT EXISTS (
        SELECT 1
        FROM connection older
        JOIN interface oi ON oi.mac_address = older.mac_address
        WHERE oi.device_id = i.device_id
          AND older.org_id = c.org_id
          AND older.started_at < UTC_TIMESTAMP() - INTERVAL 7 DAY
  )
GROUP BY d.device_id, d.name, o.org_id, o.org_name, o.country
ORDER BY first_contact_utc DESC;

-- 7. Which inbound connections hit a port that isn't registered as a service?
--    No TARGETS link = the three svc_ columns are NULL.
--    LEFT JOIN because some outside addresses have no organization.
SELECT c.connection_id, c.started_at AS started_utc, d.name AS device,
       c.local_port, c.protocol, c.remote_ip, o.org_name, c.access_path
FROM connection c
JOIN interface i ON i.mac_address = c.mac_address
JOIN device d ON d.device_id = i.device_id
LEFT JOIN organization o ON o.org_id = c.org_id
WHERE c.direction = 'inbound'
  AND c.svc_device_id IS NULL
  AND c.started_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY
ORDER BY c.started_at DESC;

-- 8. What IP addresses has my laptop had over the past month?
--    There is no IP history table. The history comes out of the connection log.
SELECT i.if_type, i.mac_address, c.local_ip,
       MIN(c.started_at) AS first_seen_utc, MAX(c.started_at) AS last_seen_utc,
       COUNT(*) AS connections
FROM connection c
JOIN interface i ON i.mac_address = c.mac_address
JOIN device d ON d.device_id = i.device_id
WHERE d.name = 'nick-laptop'
  AND c.started_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY
GROUP BY i.if_type, i.mac_address, c.local_ip
ORDER BY first_seen_utc;
