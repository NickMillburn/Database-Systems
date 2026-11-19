<?php
// Query interface: the eight questions from the proposal.
// Pick a question, adjust the time window (and the device where it applies),
// and the page runs the matching query. Every one of them joins at least two tables.
// "view" links open the Connections page filtered down to the rows behind a result.
require_once __DIR__ . "/db.php";

$questions = [
    1 => "Which device made the most outbound connections this week?",
    2 => "How much data did the media server send outside the house last month, split by access path?",
    3 => "What are the top 10 outside organizations my devices talk to?",
    4 => "Which services were reached from outside the house, and through which path?",
    5 => "What time of day is each device most active?",
    6 => "Which devices contacted an outside organization this week that they had never contacted before?",
    7 => "Which inbound connections hit a port that isn't registered as a service?",
    8 => "What IP addresses has my laptop had over the past month?",
];
$default_days = [1 => 7, 2 => 30, 3 => 30, 4 => 30, 5 => 30, 6 => 7, 7 => 7, 8 => 30];
// questions 2 and 8 are about one device; these are the defaults the proposal asked about
$default_device = [2 => "jellyfin", 8 => "nick-laptop"];

$r = (int) ($_GET["r"] ?? 1);
if (!isset($questions[$r])) {
    $r = 1;
}
$days = (int) ($_GET["days"] ?? $default_days[$r]);
$days = max(1, min(365, $days));

// device list for the dropdown, and the chosen device for questions 2 and 8
$devices = [];
foreach (rows("SELECT device_id, name FROM device ORDER BY name") as $d) {
    $devices[$d["device_id"]] = $d["name"];
}
$device_id = 0;
if (isset($default_device[$r])) {
    $device_id = (int) ($_GET["device_id"] ?? array_search($default_device[$r], $devices));
    if (!isset($devices[$device_id])) {
        $device_id = (int) array_key_first($devices);
    }
}

$link = null;
switch ($r) {
    case 1:
        $sql = "SELECT d.device_id AS _device_id, d.name AS device,
       COUNT(*) AS connections, SUM(c.bytes_sent) AS bytes_sent
FROM connection c
JOIN interface i ON i.mac_address = c.mac_address
JOIN device d ON d.device_id = i.device_id
WHERE c.direction = 'outbound'
  AND c.started_at >= UTC_TIMESTAMP() - INTERVAL ? DAY
GROUP BY d.device_id, d.name
ORDER BY connections DESC";
        $types = "i";
        $params = [$days];
        $link = function ($row) use ($days) {
            return "connections.php?direction=outbound&days=$days&device_id=" . $row["_device_id"];
        };
        break;

    case 2:
        $sql = "SELECT c.access_path, COUNT(*) AS connections, SUM(c.bytes_sent) AS bytes_sent
FROM connection c
JOIN interface i ON i.mac_address = c.mac_address
WHERE i.device_id = ?
  AND c.direction = 'inbound'
  AND c.started_at >= UTC_TIMESTAMP() - INTERVAL ? DAY
GROUP BY c.access_path
ORDER BY bytes_sent DESC";
        $types = "ii";
        $params = [$device_id, $days];
        $link = function ($row) use ($days, $device_id) {
            return "connections.php?direction=inbound&days=$days&device_id=$device_id&access_path="
                 . urlencode($row["access_path"]);
        };
        break;

    case 3:
        $sql = "SELECT o.org_id AS _org_id, o.org_name AS organization, o.country,
       COUNT(*) AS connections,
       COUNT(DISTINCT i.device_id) AS devices,
       SUM(c.bytes_sent + c.bytes_received) AS total_bytes
FROM connection c
JOIN organization o ON o.org_id = c.org_id
JOIN interface i ON i.mac_address = c.mac_address
WHERE c.direction = 'outbound'
  AND c.started_at >= UTC_TIMESTAMP() - INTERVAL ? DAY
GROUP BY o.org_id, o.org_name, o.country
ORDER BY connections DESC
LIMIT 10";
        $types = "i";
        $params = [$days];
        $link = function ($row) use ($days) {
            return "connections.php?direction=outbound&days=$days&org_id=" . $row["_org_id"];
        };
        break;

    case 4:
        $sql = "SELECT d.name AS device, s.service_name AS service, s.port, s.protocol, c.access_path,
       COUNT(*) AS connections, " . local_time("MAX(c.started_at)") . " AS last_seen_local
FROM connection c
JOIN service s ON s.device_id = c.svc_device_id
              AND s.port = c.svc_port
              AND s.protocol = c.svc_protocol
JOIN device d ON d.device_id = s.device_id
WHERE c.direction = 'inbound'
  AND c.started_at >= UTC_TIMESTAMP() - INTERVAL ? DAY
GROUP BY d.name, s.service_name, s.port, s.protocol, c.access_path
ORDER BY connections DESC";
        $types = "i";
        $params = [$days];
        break;

    case 5:
        // "h" counts connections per device per local hour. "m" finds each device's
        // biggest count. Joining the two keeps only the busiest hour (ties show both).
        $hour = "HOUR(" . local_time("c.started_at") . ")";
        $sql = "SELECT h.name AS device, h.local_hour AS busiest_local_hour, h.connections
FROM (
    SELECT d.device_id, d.name, $hour AS local_hour, COUNT(*) AS connections
    FROM connection c
    JOIN interface i ON i.mac_address = c.mac_address
    JOIN device d ON d.device_id = i.device_id
    WHERE c.started_at >= UTC_TIMESTAMP() - INTERVAL ? DAY
    GROUP BY d.device_id, d.name, local_hour
) h
JOIN (
    SELECT device_id, MAX(connections) AS busiest
    FROM (
        SELECT i.device_id, $hour AS local_hour, COUNT(*) AS connections
        FROM connection c
        JOIN interface i ON i.mac_address = c.mac_address
        WHERE c.started_at >= UTC_TIMESTAMP() - INTERVAL ? DAY
        GROUP BY i.device_id, local_hour
    ) per_hour
    GROUP BY device_id
) m ON m.device_id = h.device_id AND m.busiest = h.connections
ORDER BY h.name, h.local_hour";
        $types = "ii";
        $params = [$days, $days];
        break;

    case 6:
        // NOT EXISTS throws out any device + organization pair that also appears
        // before the window started, so only first-time contacts are left.
        $sql = "SELECT d.device_id AS _device_id, o.org_id AS _org_id,
       d.name AS device, o.org_name AS organization, o.country,
       " . local_time("MIN(c.started_at)") . " AS first_contact_local,
       COUNT(*) AS connections
FROM connection c
JOIN interface i ON i.mac_address = c.mac_address
JOIN device d ON d.device_id = i.device_id
JOIN organization o ON o.org_id = c.org_id
WHERE c.direction = 'outbound'
  AND c.started_at >= UTC_TIMESTAMP() - INTERVAL ? DAY
  AND NOT EXISTS (
        SELECT 1
        FROM connection older
        JOIN interface oi ON oi.mac_address = older.mac_address
        WHERE oi.device_id = i.device_id
          AND older.org_id = c.org_id
          AND older.started_at < UTC_TIMESTAMP() - INTERVAL ? DAY
  )
GROUP BY d.device_id, d.name, o.org_id, o.org_name, o.country
ORDER BY first_contact_local DESC";
        $types = "ii";
        $params = [$days, $days];
        $link = function ($row) use ($days) {
            return "connections.php?direction=outbound&days=$days&device_id=" . $row["_device_id"]
                 . "&org_id=" . $row["_org_id"];
        };
        break;

    case 7:
        // No TARGETS link means the three svc_ columns are NULL.
        // LEFT JOIN because some outside addresses have no organization.
        $sql = "SELECT c.connection_id AS id, " . local_time("c.started_at") . " AS started_local,
       d.name AS device, c.local_port, c.protocol, c.remote_ip,
       o.org_name AS organization, c.access_path
FROM connection c
JOIN interface i ON i.mac_address = c.mac_address
JOIN device d ON d.device_id = i.device_id
LEFT JOIN organization o ON o.org_id = c.org_id
WHERE c.direction = 'inbound'
  AND c.svc_device_id IS NULL
  AND c.started_at >= UTC_TIMESTAMP() - INTERVAL ? DAY
ORDER BY c.started_at DESC
LIMIT 200";
        $types = "i";
        $params = [$days];
        break;

    case 8:
        // There is no IP history table. The history comes out of the connection log.
        $sql = "SELECT i.if_type, i.mac_address, c.local_ip,
       " . local_time("MIN(c.started_at)") . " AS first_seen_local,
       " . local_time("MAX(c.started_at)") . " AS last_seen_local,
       COUNT(*) AS connections
FROM connection c
JOIN interface i ON i.mac_address = c.mac_address
WHERE i.device_id = ?
  AND c.started_at >= UTC_TIMESTAMP() - INTERVAL ? DAY
GROUP BY i.if_type, i.mac_address, c.local_ip
ORDER BY first_seen_local";
        $types = "ii";
        $params = [$device_id, $days];
        break;
}

$result = rows($sql, $types, $params);

page_header("Reports");
?>
<ol class="questions">
<?php foreach ($questions as $n => $text): ?>
    <li<?php echo $n == $r ? ' class="here"' : ''; ?>><a href="reports.php?r=<?php echo $n; ?>"><?php echo h($text); ?></a></li>
<?php endforeach; ?>
</ol>

<h2><?php echo $r . ". " . h($questions[$r]); ?></h2>
<form class="inline panel" method="get" action="reports.php">
    <input type="hidden" name="r" value="<?php echo $r; ?>">
    <label>Look back (days)
        <input type="number" name="days" min="1" max="365" value="<?php echo $days; ?>">
    </label>
<?php if ($device_id): ?>
    <label>Device
        <select name="device_id"><?php echo options($devices, $device_id); ?></select>
    </label>
<?php endif; ?>
    <button type="submit">Run</button>
</form>

<?php show_table($result, $link); ?>

<details>
    <summary>Show the SQL this ran</summary>
    <pre><?php echo h($sql); ?></pre>
    <p class="hint">Values bound to the ? placeholders, in order: <?php echo h(implode(", ", $params)); ?></p>
</details>
<?php
page_footer();
