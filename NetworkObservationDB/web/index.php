<?php
/*
 * index.php  (the Dashboard page)
 *
 * WHAT THIS FILE DOES
 * The landing page. It is read-only and shows three things:
 *   1. how many rows are in each of the five tables
 *   2. a summary of the last 24 hours, grouped by direction and access path
 *   3. the 15 newest connections
 * Adding ?live=1 to the address makes the page reload every 5 seconds, which is
 * how to watch the simulator's rows arrive.
 *
 * HOW IT IS LAID OUT (every page follows this same pattern)
 *   top half:    PHP that runs the queries and stores the results in variables
 *   bottom half: HTML that prints those variables
 */

// Load the shared functions (db connection, rows(), show_table(), page_header() ...).
require_once __DIR__ . "/db.php";

// ---- Section 1: read the address bar ----
// $_GET holds whatever comes after the ? in the URL. isset() is true if "live" is there.
$live = isset($_GET["live"]);

// ---- Section 2: query 1, row counts ----
// One count per table. The table names are fixed here, not user input.
$counts = [];
foreach (["device", "interface", "service", "organization", "connection"] as $table) {
    // rows() returns a list of rows. [0] is the first row and ["n"] is its COUNT(*) column.
    $counts[$table] = rows("SELECT COUNT(*) AS n FROM $table")[0]["n"];
}

// ---- Section 3: query 2, last 24 hours ----
// One table (connection). GROUP BY makes one output row per direction + access path pair.
// UTC_TIMESTAMP() is used instead of NOW() because the stored times are UTC.
$last_day = rows(
    "SELECT direction, access_path, COUNT(*) AS connections,
            SUM(bytes_sent) AS bytes_sent, SUM(bytes_received) AS bytes_received
     FROM connection
     WHERE started_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY
     GROUP BY direction, access_path
     ORDER BY connections DESC"
);

// ---- Section 4: query 3, the 15 newest connections ----
// Joins all five tables: connection -> interface -> device gives the device name,
// connection -> organization gives the org name, connection -> service gives the service name.
// LEFT JOINs because a connection may have no organization and no matched service.
$latest = rows(
    "SELECT c.connection_id AS id, " . local_time("c.ended_at") . " AS ended_local,
            d.name AS device, c.direction, c.access_path,
            c.remote_ip, c.remote_port, c.protocol,
            o.org_name AS organization, s.service_name AS service,
            c.bytes_sent, c.bytes_received, c.source
     FROM connection c
     JOIN interface i ON i.mac_address = c.mac_address
     JOIN device d ON d.device_id = i.device_id
     LEFT JOIN organization o ON o.org_id = c.org_id
     LEFT JOIN service s ON s.device_id = c.svc_device_id
                        AND s.port = c.svc_port
                        AND s.protocol = c.svc_protocol
     ORDER BY c.connection_id DESC
     LIMIT 15"
);

// ---- Section 5: output ----
// Everything below prints HTML. The second argument is the auto-reload time (0 = off).
page_header("Dashboard", $live ? 5 : 0);
?>
<?php /* The row of count boxes. One box per entry in $counts. */ ?>
<div class="cards">
<?php foreach ($counts as $table => $n): ?>
    <div class="card"><b><?php echo number_format($n); ?></b><span><?php echo $table; ?> rows</span></div>
<?php endforeach; ?>
</div>

<?php /* The 24-hour summary table. The function passed to show_table builds each row's
   "view" link, which opens connections.php filtered to that direction and path. */ ?>
<h2>Last 24 hours by direction and access path</h2>
<?php
show_table($last_day, function ($row) {
    return "connections.php?days=1&direction=" . urlencode($row["direction"])
         . "&access_path=" . urlencode($row["access_path"]);
});
?>

<?php /* The newest-connections table, with the link that turns live reload on or off. */ ?>
<h2>Newest connections</h2>
<p class="hint">
<?php if ($live): ?>
    Reloading every 5 seconds. <a href="index.php">Stop</a>
<?php else: ?>
    <a href="index.php?live=1">Watch live</a> (reloads every 5 seconds; new rows show up while the simulator service is running)
<?php endif; ?>
</p>
<?php
show_table($latest);
page_footer();
