<?php
// Dashboard: how much is in each table, what the last 24 hours looked like,
// and the newest connections. Add ?live=1 to reload every 5 seconds and watch
// the simulator's rows arrive.
require_once __DIR__ . "/db.php";

$live = isset($_GET["live"]);

// One count per table. The table names are fixed here, not user input.
$counts = [];
foreach (["device", "interface", "service", "organization", "connection"] as $table) {
    $counts[$table] = rows("SELECT COUNT(*) AS n FROM $table")[0]["n"];
}

$last_day = rows(
    "SELECT direction, access_path, COUNT(*) AS connections,
            SUM(bytes_sent) AS bytes_sent, SUM(bytes_received) AS bytes_received
     FROM connection
     WHERE started_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY
     GROUP BY direction, access_path
     ORDER BY connections DESC"
);

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

page_header("Dashboard", $live ? 5 : 0);
?>
<div class="cards">
<?php foreach ($counts as $table => $n): ?>
    <div class="card"><b><?php echo number_format($n); ?></b><span><?php echo $table; ?> rows</span></div>
<?php endforeach; ?>
</div>

<h2>Last 24 hours by direction and access path</h2>
<?php
show_table($last_day, function ($row) {
    return "connections.php?days=1&direction=" . urlencode($row["direction"])
         . "&access_path=" . urlencode($row["access_path"]);
});
?>

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
