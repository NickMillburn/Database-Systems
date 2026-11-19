<?php
/*
 * add_connection.php  (the ADD interface: log one connection by hand)
 *
 * WHAT THIS FILE DOES
 * Shows a form for entering one connection, and when the form is submitted,
 * inserts one row into the connection table.
 *
 * WHY IT COUNTS AS NON-TRIVIAL
 * The form only asks for what a raw log line would contain (which interface,
 * IPs, ports, bytes). A connection row also has three foreign keys, and the
 * user does not type those. The page looks them up in the other tables first:
 *   step 1  interface + device   which device the chosen MAC address belongs to
 *   step 2  connection           the interface's latest local IP, if left blank
 *   step 3  service              the service that was hit (the TARGETS relationship),
 *                                matched on device + port + protocol
 *   step 4  organization         who owns the remote IP (the BELONGS TO relationship),
 *                                taken from earlier connections to the same address
 *   step 5  INSERT into connection with those links filled in
 * This is the same shape as the bicycle reservation example in the assignment:
 * check the other tables, then insert a row that links to existing rows.
 *
 * HOW ONE FILE IS BOTH THE FORM AND THE HANDLER
 * The form posts back to this same file. A normal visit is a GET request, so the
 * big if-block in section 3 is skipped and only the empty form is printed. Pressing
 * Save sends a POST request, so the if-block runs first and then the form is
 * printed again with a success or error message above it.
 */

require_once __DIR__ . "/db.php";

// ---- Section 1: fixed rules and dropdown contents ----
// which access paths make sense for each direction
$allowed_paths = [
    "outbound" => ["internet"],
    "inbound"  => ["tailscale", "cloudflare_tunnel", "port_forward"],
    "internal" => ["lan", "tailscale"],
];

// dropdown of my interfaces: MAC address => "device / type (MAC)". Joins interface to device.
$interfaces = [];
foreach (rows(
    "SELECT i.mac_address, d.name, i.if_type
     FROM interface i JOIN device d ON d.device_id = i.device_id
     ORDER BY d.name, i.if_type"
) as $i) {
    $interfaces[$i["mac_address"]] = $i["name"] . " / " . $i["if_type"] . " (" . $i["mac_address"] . ")";
}
// dropdown of organizations. The empty value means let step 4 work it out.
$orgs = ["" => "auto (look up by remote IP)"];
foreach (rows("SELECT org_id, org_name FROM organization ORDER BY org_name") as $o) {
    $orgs[$o["org_id"]] = $o["org_name"];
}

// ---- Section 2: variables for this request ----
$error = "";
$saved = [];   // lines describing what was saved and what it got linked to
$f = [         // form values, kept so the form refills after a submit
    "mac" => "", "direction" => "inbound", "access_path" => "cloudflare_tunnel", "protocol" => "tcp",
    "local_ip" => "", "local_port" => "", "remote_ip" => "", "remote_port" => "",
    "org_id" => "", "bytes_sent" => "0", "bytes_received" => "0", "seconds" => "60",
];

// ---- Section 3: handle a submitted form ----
// Only runs when Save was pressed. A plain page visit skips all of this.
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // copy what the user typed over the defaults. trim() removes stray spaces.
    foreach ($f as $key => $default) {
        $f[$key] = trim($_POST[$key] ?? $default);
    }
    // numbers are forced to integers. max(0, ...) stops negative byte counts.
    $local_port  = (int) $f["local_port"];
    $remote_port = (int) $f["remote_port"];
    $sent        = max(0, (int) $f["bytes_sent"]);
    $received    = max(0, (int) $f["bytes_received"]);
    $seconds     = max(0, (int) $f["seconds"]);
    $local_ip    = $f["local_ip"];

    // step 1: the interface must exist; it tells us the device
    $device = rows(
        "SELECT d.device_id, d.name
         FROM interface i JOIN device d ON d.device_id = i.device_id
         WHERE i.mac_address = ?",
        "s",
        [$f["mac"]]
    );

    // step 2: no local IP typed in -> use the last one this interface was seen with
    if ($device && $local_ip === "") {
        $last = rows(
            "SELECT local_ip FROM connection WHERE mac_address = ? ORDER BY started_at DESC LIMIT 1",
            "s",
            [$f["mac"]]
        );
        $local_ip = $last ? $last[0]["local_ip"] : "";
    }

    // validation: checked in order, and the first one that fails sets $error.
    // Only when every check passes does the final else run and save anything.
    if (!$device) {
        $error = "Pick an interface.";
    } elseif (!isset($allowed_paths[$f["direction"]])) {
        $error = "Pick a direction.";
    } elseif (!in_array($f["access_path"], $allowed_paths[$f["direction"]])) {
        $error = "A connection that is " . $f["direction"] . " can only use: "
               . implode(", ", $allowed_paths[$f["direction"]]) . ".";
    } elseif ($f["protocol"] != "tcp" && $f["protocol"] != "udp") {
        $error = "Protocol must be tcp or udp.";
    } elseif ($local_ip === "") {
        $error = "This interface has no earlier connections to copy a local IP from, so type one in.";
    } elseif (!filter_var($local_ip, FILTER_VALIDATE_IP) || !filter_var($f["remote_ip"], FILTER_VALIDATE_IP)) {
        $error = "Local and remote IP must be valid IP addresses.";
    } elseif ($local_port < 1 || $local_port > 65535 || $remote_port < 1 || $remote_port > 65535) {
        $error = "Ports must be between 1 and 65535.";
    } else {
        $device_id = $device[0]["device_id"];
        $saved[] = "Interface " . $f["mac"] . " belongs to " . $device[0]["name"] . ".";

        // step 3: find the service that was hit (the TARGETS relationship)
        $svc_device_id = null;
        $svc_port = null;
        $svc_protocol = null;
        if ($f["direction"] == "inbound") {
            // someone reached this device, so the service is on this device at the local port
            $look_device = $device_id;
            $look_port = $local_port;
        } elseif ($f["direction"] == "internal") {
            // this device reached another one of mine: find which device last used the remote IP
            $target = rows(
                "SELECT i.device_id
                 FROM connection c JOIN interface i ON i.mac_address = c.mac_address
                 WHERE c.local_ip = ?
                 ORDER BY c.started_at DESC LIMIT 1",
                "s",
                [$f["remote_ip"]]
            );
            $look_device = $target ? $target[0]["device_id"] : 0;
            $look_port = $remote_port;
        } else {
            $look_device = 0;   // outbound connections do not hit one of my services
            $look_port = 0;
        }
        if ($look_device) {
            $service = rows(
                "SELECT s.service_name, d.name
                 FROM service s JOIN device d ON d.device_id = s.device_id
                 WHERE s.device_id = ? AND s.port = ? AND s.protocol = ?",
                "iis",
                [$look_device, $look_port, $f["protocol"]]
            );
            if ($service) {
                $svc_device_id = $look_device;
                $svc_port = $look_port;
                $svc_protocol = $f["protocol"];
                $saved[] = "Matched the service " . $service[0]["service_name"] . " on " . $service[0]["name"] . ".";
            }
        }
        if ($f["direction"] != "outbound" && $svc_device_id === null) {
            $saved[] = "No registered service on that port, so the service link was left empty.";
        }

        // step 4: who owns the remote address (the BELONGS TO relationship)
        $org_id = null;
        if ($f["direction"] != "internal") {
            if ($f["org_id"] !== "") {
                $org_id = (int) $f["org_id"];
            } else {
                $known = rows(
                    "SELECT c.org_id, o.org_name
                     FROM connection c JOIN organization o ON o.org_id = c.org_id
                     WHERE c.remote_ip = ?
                     ORDER BY c.started_at DESC LIMIT 1",
                    "s",
                    [$f["remote_ip"]]
                );
                if ($known) {
                    $org_id = $known[0]["org_id"];
                    $saved[] = "Earlier connections say " . $f["remote_ip"] . " belongs to " . $known[0]["org_name"] . ".";
                } else {
                    $saved[] = "No organization known for " . $f["remote_ip"] . ", so that link was left empty.";
                }
            }
        }

        // step 5: insert. The connection ended now and started $seconds ago, both in UTC.
        try {
            run(
                "INSERT INTO connection
                    (mac_address, org_id, svc_device_id, svc_port, svc_protocol,
                     local_ip, local_port, remote_ip, remote_port, protocol,
                     started_at, ended_at, bytes_sent, bytes_received,
                     direction, access_path, source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                         UTC_TIMESTAMP() - INTERVAL ? SECOND, UTC_TIMESTAMP(), ?, ?, ?, ?, 'manual')",
                // bind_param types, one letter per ? above in order: s = string, i = integer.
                // A PHP null is sent as SQL NULL, which is how the optional links are left empty.
                "siiissisisiiiss",
                [$f["mac"], $org_id, $svc_device_id, $svc_port, $svc_protocol,
                 $local_ip, $local_port, $f["remote_ip"], $remote_port, $f["protocol"],
                 $seconds, $sent, $received, $f["direction"], $f["access_path"]]
            );
            // insert_id is the auto-increment id MySQL just assigned
            array_unshift($saved, "Saved connection #" . db()->insert_id . ".");
        } catch (mysqli_sql_exception $e) {
            $saved = [];
            $error = "Insert failed: " . $e->getMessage();
        }
    }
}

// ---- Section 4: output ----
// The third argument is the note shown under the title.
page_header(
    "Log a connection",
    0,
    "This is my add interface. It adds one row to the connection table. It is non-trivial "
    . "because I only type what a raw log line would have. The page looks up the device, the "
    . "service and the organization in the other tables to fill in the three foreign keys "
    . "before it inserts."
);
?>
<p class="hint">
    Example: pick jellyfin / ethernet, inbound, cloudflare_tunnel, tcp, local port 8096,
    remote 104.16.132.229 port 51000. The page links it to the Jellyfin service and to Cloudflare on its own.
    Change the local port to 23 and it is saved with no service, which makes it show up in report 7.
</p>

<?php /* Message area: a red box for an error, or a green box listing what was saved and linked. */ ?>
<?php if ($error): ?>
    <p class="error"><?php echo h($error); ?></p>
<?php endif; ?>
<?php if ($saved): ?>
    <div class="ok">
        <?php foreach ($saved as $line): ?>
            <?php echo h($line); ?><br>
        <?php endforeach; ?>
        <a href="connections.php?days=1&amp;remote_ip=<?php echo urlencode($f["remote_ip"]); ?>">See it in the connection log</a>
    </div>
<?php endif; ?>

<?php /* The form. method="post" because it changes data. Each input's name="..." is the
   key it arrives under in $_POST. value=... refills what the user typed, so a
   failed validation does not wipe the form. */ ?>
<form class="grid panel" method="post" action="add_connection.php">
    <label>My interface
        <select name="mac" required><?php echo options(["" => "choose..."] + $interfaces, $f["mac"]); ?></select>
    </label>
    <label>Direction
        <select name="direction"><?php echo options(["inbound" => "inbound (outside reached me)",
            "outbound" => "outbound (I reached outside)", "internal" => "internal (both ends mine)"], $f["direction"]); ?></select>
    </label>
    <label>Access path
        <select name="access_path"><?php echo options(["lan" => "lan", "internet" => "internet", "tailscale" => "tailscale",
            "cloudflare_tunnel" => "cloudflare_tunnel", "port_forward" => "port_forward"], $f["access_path"]); ?></select>
    </label>
    <label>Protocol
        <select name="protocol"><?php echo options(["tcp" => "tcp", "udp" => "udp"], $f["protocol"]); ?></select>
    </label>
    <label>Local IP (blank = interface's latest)
        <input type="text" name="local_ip" value="<?php echo h($f["local_ip"]); ?>">
    </label>
    <label>Local port
        <input type="number" name="local_port" min="1" max="65535" required value="<?php echo h($f["local_port"]); ?>">
    </label>
    <label>Remote IP
        <input type="text" name="remote_ip" required value="<?php echo h($f["remote_ip"]); ?>">
    </label>
    <label>Remote port
        <input type="number" name="remote_port" min="1" max="65535" required value="<?php echo h($f["remote_port"]); ?>">
    </label>
    <label>Organization
        <select name="org_id"><?php echo options($orgs, $f["org_id"]); ?></select>
    </label>
    <label>Bytes sent
        <input type="number" name="bytes_sent" min="0" value="<?php echo h($f["bytes_sent"]); ?>">
    </label>
    <label>Bytes received
        <input type="number" name="bytes_received" min="0" value="<?php echo h($f["bytes_received"]); ?>">
    </label>
    <label>Lasted (seconds, ending now)
        <input type="number" name="seconds" min="0" value="<?php echo h($f["seconds"]); ?>">
    </label>
    <button type="submit">Save connection</button>
</form>
<?php
page_footer();
