<?php
/*
 * reports.php  (the QUERY interface)
 *
 * WHAT THIS FILE DOES
 * Answers the eight questions from the project proposal. The user clicks a
 * question, optionally changes the time window or the device, and the page runs
 * one SELECT and shows the result as a table. This page only reads data.
 *
 * WHY IT COUNTS AS NON-TRIVIAL
 * Every one of the eight queries joins at least two tables. Several join four.
 *
 * HOW IT WORKS, TOP TO BOTTOM
 *   1. The list of questions and their default settings.
 *   2. Read which question was picked (?r=6) and the inputs (?days=7) from the URL.
 *   3. A switch statement with one case per question. Each case fills in:
 *        $sql     the query text, with ? where a user value goes
 *        $types   one letter per ? ("i" = integer, "s" = string)
 *        $params  the values for those ?s, in the same order
 *        $link    optional: a function that builds the "view" link for a row
 *   4. Run the query with rows().
 *   5. Print the page: question list, input form, result table, and the SQL that ran.
 *
 * The "view" links go to connections.php with filters in the URL, which shows the
 * individual connections behind a summary row (the drill-down).
 */

require_once __DIR__ . "/db.php";

// ---- Section 1: the questions and their defaults ----
// question number => the text shown on the page
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
// question number => the tables that question's query joins (shown on the page)
$tables_used = [
    1 => "connection, interface, device",
    2 => "connection, interface",
    3 => "connection, organization, interface",
    4 => "connection, service, device",
    5 => "connection, interface, device (with subqueries)",
    6 => "connection, interface, device, organization (with a NOT EXISTS subquery)",
    7 => "connection, interface, device, organization",
    8 => "connection, interface",
];
// question number => how many days back it looks unless the user changes it
$default_days = [1 => 7, 2 => 30, 3 => 30, 4 => 30, 5 => 30, 6 => 7, 7 => 7, 8 => 30];
// questions 2 and 8 are about one device; these are the defaults the proposal asked about
$default_device = [2 => "jellyfin", 8 => "nick-laptop"];

// ---- Section 2: read the inputs from the URL ----
// ?? means "use the right side if the left side is missing". (int) forces a number,
// so nothing but a number can get through. An unknown question number falls back to 1.
$r = (int) ($_GET["r"] ?? 1);
if (!isset($questions[$r])) {
    $r = 1;
}
$days = (int) ($_GET["days"] ?? $default_days[$r]);
// clamp the window to 1..365 days
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

// ---- Section 3: build the query for the chosen question ----
// Each case below sets $sql, $types and $params (and sometimes $link).
// Table aliases used throughout: c = connection, i = interface, d = device,
// o = organization, s = service.
$link = null;
switch ($r) {
    case 1:
        // Q1. connection -> interface -> device (3 tables).
        // Keep outbound rows inside the window, then GROUP BY device and count them.
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
        // Q2. connection -> interface (2 tables).
        // Inbound traffic to one device, grouped by access path. bytes_sent is from my
        // device's side, so it is how much the device sent out to the people watching.
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
        // Q3. connection -> organization, connection -> interface (3 tables).
        // COUNT(DISTINCT i.device_id) = how many different devices talked to that org.
        // ORDER BY ... DESC LIMIT 10 keeps the ten biggest.
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
        // Q4. connection -> service -> device (3 tables).
        // The join to service uses all three key columns because service has a 3-column
        // primary key. An inner JOIN drops connections that matched no service.
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
        // Q5. connection -> interface -> device (3 tables), using two subqueries.
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
        // Q6. connection -> interface -> device, connection -> organization (4 tables) plus a subquery.
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
        // Q7. connection -> interface -> device, connection -> organization (4 tables).
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
        // Q8. connection -> interface (2 tables).
        // GROUP BY interface + local_ip gives one row per address the device has used.
        // MIN and MAX of started_at give when each address was first and last seen.
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

// ---- Section 4: run it ----
// rows() prepares $sql, binds $params to the ?s, executes, and returns the rows.
$result = rows($sql, $types, $params);

// ---- Section 5: output ----
// The third argument is the note shown under the title.
page_header(
    "Reports",
    0,
    "This is my query interface. It displays information retrieved from the database. "
    . "It is non-trivial because every one of these eight questions joins at least two tables, "
    . "and some join four. The tables each question uses are listed under it."
);
?>
<?php /* The numbered list of questions. Each is a link back to this page with ?r=N. */ ?>
<ol class="questions">
<?php foreach ($questions as $n => $text): ?>
    <li<?php echo $n == $r ? ' class="here"' : ''; ?>><a href="reports.php?r=<?php echo $n; ?>"><?php echo h($text); ?></a></li>
<?php endforeach; ?>
</ol>

<?php /* The chosen question and its input form. method="get" puts the inputs in the URL,
   so a report can be bookmarked or reloaded. The hidden field keeps the question number. */ ?>
<h2><?php echo $r . ". " . h($questions[$r]); ?></h2>
<p class="hint">Tables this question joins: <?php echo h($tables_used[$r]); ?></p>
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

<?php /* The result table. $link (if set) adds the "view" column. */ ?>
<?php show_table($result, $link); ?>

<?php /* Collapsed section showing the exact SQL and the values that were bound to the ?s. */ ?>
<details>
    <summary>Show the SQL this ran</summary>
    <pre><?php echo h($sql); ?></pre>
    <p class="hint">Values bound to the ? placeholders, in order: <?php echo h(implode(", ", $params)); ?></p>
</details>
<?php
page_footer();
