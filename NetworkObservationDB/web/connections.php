<?php
// Browse the connection log with filters. The Reports page links here to show
// the individual connections behind a summary row (the drill-down).
// The WHERE clause is built from whichever filters are filled in. User values
// only ever go in through bind_param, never into the SQL text.
require_once __DIR__ . "/db.php";

$directions = ["" => "any", "inbound" => "inbound", "outbound" => "outbound", "internal" => "internal"];
$paths = ["" => "any", "lan" => "lan", "internet" => "internet", "tailscale" => "tailscale",
          "cloudflare_tunnel" => "cloudflare_tunnel", "port_forward" => "port_forward"];

$devices = ["" => "any"];
foreach (rows("SELECT device_id, name FROM device ORDER BY name") as $d) {
    $devices[$d["device_id"]] = $d["name"];
}
$orgs = ["" => "any"];
foreach (rows("SELECT org_id, org_name FROM organization ORDER BY org_name") as $o) {
    $orgs[$o["org_id"]] = $o["org_name"];
}

// read the filters from the URL
$days         = max(1, min(365, (int) ($_GET["days"] ?? 7)));
$device_id    = (int) ($_GET["device_id"] ?? 0);
$org_id       = (int) ($_GET["org_id"] ?? 0);
$direction    = $_GET["direction"] ?? "";
$access_path  = $_GET["access_path"] ?? "";
$remote_ip    = trim($_GET["remote_ip"] ?? "");
$unregistered = isset($_GET["unregistered"]);

// each filter that is set adds one condition and its bound value
$where  = ["c.started_at >= UTC_TIMESTAMP() - INTERVAL ? DAY"];
$types  = "i";
$params = [$days];
if ($device_id) {
    $where[] = "i.device_id = ?";
    $types .= "i";
    $params[] = $device_id;
}
if ($org_id) {
    $where[] = "c.org_id = ?";
    $types .= "i";
    $params[] = $org_id;
}
if ($direction !== "" && isset($directions[$direction])) {
    $where[] = "c.direction = ?";
    $types .= "s";
    $params[] = $direction;
}
if ($access_path !== "" && isset($paths[$access_path])) {
    $where[] = "c.access_path = ?";
    $types .= "s";
    $params[] = $access_path;
}
if ($remote_ip !== "") {
    $where[] = "c.remote_ip = ?";
    $types .= "s";
    $params[] = $remote_ip;
}
if ($unregistered) {
    $where[] = "c.direction = 'inbound' AND c.svc_device_id IS NULL";
}
$where_sql = implode("\n  AND ", $where);

$joins = "FROM connection c
JOIN interface i ON i.mac_address = c.mac_address
JOIN device d ON d.device_id = i.device_id
LEFT JOIN organization o ON o.org_id = c.org_id
LEFT JOIN service s ON s.device_id = c.svc_device_id
                   AND s.port = c.svc_port
                   AND s.protocol = c.svc_protocol";

$totals = rows(
    "SELECT COUNT(*) AS n, COALESCE(SUM(c.bytes_sent), 0) AS sent, COALESCE(SUM(c.bytes_received), 0) AS received
     $joins WHERE $where_sql",
    $types,
    $params
)[0];

$sql = "SELECT c.connection_id AS id, " . local_time("c.started_at") . " AS started_local,
       TIMESTAMPDIFF(SECOND, c.started_at, c.ended_at) AS seconds,
       d.name AS device, i.if_type AS interface, c.direction, c.access_path,
       CONCAT(c.local_ip, ':', c.local_port) AS local_end,
       CONCAT(c.remote_ip, ':', c.remote_port) AS remote_end,
       c.protocol, o.org_name AS organization, s.service_name AS service,
       c.bytes_sent, c.bytes_received, c.source
$joins
WHERE $where_sql
ORDER BY c.started_at DESC
LIMIT 200";
$result = rows($sql, $types, $params);

page_header("Connections");
?>
<form class="inline panel" method="get" action="connections.php">
    <label>Look back (days)
        <input type="number" name="days" min="1" max="365" value="<?php echo $days; ?>">
    </label>
    <label>Device
        <select name="device_id"><?php echo options($devices, $device_id ?: ""); ?></select>
    </label>
    <label>Direction
        <select name="direction"><?php echo options($directions, $direction); ?></select>
    </label>
    <label>Access path
        <select name="access_path"><?php echo options($paths, $access_path); ?></select>
    </label>
    <label>Organization
        <select name="org_id"><?php echo options($orgs, $org_id ?: ""); ?></select>
    </label>
    <label>Remote IP
        <input type="text" name="remote_ip" value="<?php echo h($remote_ip); ?>">
    </label>
    <label><input type="checkbox" name="unregistered" style="display:inline;width:auto;min-width:0"<?php echo $unregistered ? " checked" : ""; ?>>
        Unregistered port only
    </label>
    <button type="submit">Filter</button>
</form>

<p class="hint">
    <?php echo number_format($totals["n"]); ?> connections match,
    <?php echo human_bytes($totals["sent"]); ?> sent and <?php echo human_bytes($totals["received"]); ?> received.
    Showing the newest <?php echo count($result); ?>.
</p>
<?php
show_table($result);
page_footer();
