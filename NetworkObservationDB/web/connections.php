<?php
/*
 * connections.php  (the connection log, also the drill-down page)
 *
 * WHAT THIS FILE DOES
 * Lists individual rows from the connection table, newest first, with a filter
 * form at the top (days, device, direction, access path, organization, remote IP,
 * unregistered port only). The Reports page links here with the filters already
 * filled in, to show the connections behind one summary row.
 *
 * THE INTERESTING PART: A WHERE CLAUSE BUILT AT RUN TIME
 * The filters are optional, so the WHERE clause cannot be written ahead of time.
 * Instead three things are built side by side:
 *   $where   a list of conditions, e.g. "i.device_id = ?"
 *   $types   the bind_param letters, e.g. "iis"
 *   $params  the values, e.g. [7, 6, "inbound"]
 * Each filter that is filled in adds one entry to all three. Only fixed text with
 * ? placeholders goes into the SQL. The user's values only go in through
 * bind_param, so the page is still safe from SQL injection.
 */

require_once __DIR__ . "/db.php";

// ---- Section 1: choices for the dropdowns ----
// value => label. The empty value "" means no filter. Devices and organizations
// are read from the database so new ones show up automatically.
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

// ---- Section 2: read the filters ----
// read the filters from the URL
$days         = max(1, min(365, (int) ($_GET["days"] ?? 7)));
$device_id    = (int) ($_GET["device_id"] ?? 0);
$org_id       = (int) ($_GET["org_id"] ?? 0);
$direction    = $_GET["direction"] ?? "";
$access_path  = $_GET["access_path"] ?? "";
$remote_ip    = trim($_GET["remote_ip"] ?? "");
$unregistered = isset($_GET["unregistered"]);

// ---- Section 3: build the WHERE clause ----
// The time window is always there, so the lists start with it.
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
// isset(...) checks the value is one of the known choices before it is used
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
// no ? needed here: this condition has no user value in it
if ($unregistered) {
    $where[] = "c.direction = 'inbound' AND c.svc_device_id IS NULL";
}
// glue the conditions together: cond1 AND cond2 AND cond3
$where_sql = implode("\n  AND ", $where);

// ---- Section 4: the two queries ----
// The FROM / JOIN part is shared by both queries below. All five tables.
// LEFT JOIN keeps a connection even when it has no organization or no service.
$joins = "FROM connection c
JOIN interface i ON i.mac_address = c.mac_address
JOIN device d ON d.device_id = i.device_id
LEFT JOIN organization o ON o.org_id = c.org_id
LEFT JOIN service s ON s.device_id = c.svc_device_id
                   AND s.port = c.svc_port
                   AND s.protocol = c.svc_protocol";

// query 1: how many rows match the filters, and their byte totals
$totals = rows(
    "SELECT COUNT(*) AS n, COALESCE(SUM(c.bytes_sent), 0) AS sent, COALESCE(SUM(c.bytes_received), 0) AS received
     $joins WHERE $where_sql",
    $types,
    $params
)[0];

// query 2: the newest 200 matching rows. Same $types and $params as query 1.
// TIMESTAMPDIFF gives the duration. CONCAT glues IP and port into one column.
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

// ---- Section 5: output ----
page_header("Connections");
?>
<?php /* The filter form. method="get" puts the filters in the URL, which is what lets
   the Reports page link straight to a filtered view. Each field is pre-filled
   with the current filter value. */ ?>
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

<?php /* The totals line, then the table of rows. */ ?>
<p class="hint">
    <?php echo number_format($totals["n"]); ?> connections match,
    <?php echo human_bytes($totals["sent"]); ?> sent and <?php echo human_bytes($totals["received"]); ?> received.
    Showing the newest <?php echo count($result); ?>.
</p>
<?php
show_table($result);
page_footer();
