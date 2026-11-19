<?php
// Add / modify interface #2: the device registry, service catalog and organizations.
// Each form posts back to this page with a hidden "action" field.
//   add_device    INSERT device + its first interface, in one transaction
//   add_interface INSERT one more interface for an existing device
//   add_service   INSERT service, then UPDATE older connections that hit that port so they link to it
//   add_org       INSERT organization
//   label_ip      UPDATE every connection to one remote IP so it points at an organization
require_once __DIR__ . "/db.php";

$message = "";
$error = "";
$action = $_POST["action"] ?? "";

// "AA-BB-CC-DD-EE-FF" or "aa:bb:..." -> "aa:bb:cc:dd:ee:ff", or "" if it is not a MAC address
function clean_mac($text)
{
    $mac = strtolower(str_replace("-", ":", trim($text)));
    return preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac) ? $mac : "";
}

try {
    if ($action == "add_device") {
        $name = trim($_POST["name"] ?? "");
        $type = trim($_POST["device_type"] ?? "");
        $owner = trim($_POST["owner"] ?? "");
        $mac = clean_mac($_POST["mac_address"] ?? "");
        $if_type = $_POST["if_type"] ?? "";
        if ($name === "" || $type === "") {
            $error = "A device needs a name and a type.";
        } elseif ($mac === "") {
            $error = "MAC address must look like aa:bb:cc:dd:ee:ff.";
        } else {
            // Both inserts succeed or neither does. If the MAC is already taken,
            // the rollback also removes the device row that was just added.
            db()->begin_transaction();
            try {
                run(
                    "INSERT INTO device (name, device_type, owner, registered_on) VALUES (?, ?, NULLIF(?, ''), CURDATE())",
                    "sss",
                    [$name, $type, $owner]
                );
                $device_id = db()->insert_id;
                run(
                    "INSERT INTO interface (mac_address, device_id, if_type) VALUES (?, ?, ?)",
                    "sis",
                    [$mac, $device_id, $if_type]
                );
                db()->commit();
                $message = "Registered $name (device #$device_id) with interface $mac.";
            } catch (mysqli_sql_exception $e) {
                db()->rollback();
                throw $e;
            }
        }
    } elseif ($action == "add_interface") {
        $mac = clean_mac($_POST["mac_address"] ?? "");
        if ($mac === "") {
            $error = "MAC address must look like aa:bb:cc:dd:ee:ff.";
        } else {
            run(
                "INSERT INTO interface (mac_address, device_id, if_type) VALUES (?, ?, ?)",
                "sis",
                [$mac, (int) $_POST["device_id"], $_POST["if_type"] ?? ""]
            );
            $message = "Added interface $mac.";
        }
    } elseif ($action == "add_service") {
        $device_id = (int) $_POST["device_id"];
        $port = (int) $_POST["port"];
        $protocol = $_POST["protocol"] ?? "";
        $name = trim($_POST["service_name"] ?? "");
        if ($port < 1 || $port > 65535 || ($protocol != "tcp" && $protocol != "udp") || $name === "") {
            $error = "A service needs a port (1-65535), tcp or udp, and a name.";
        } else {
            db()->begin_transaction();
            try {
                run(
                    "INSERT INTO service (device_id, port, protocol, service_name) VALUES (?, ?, ?, ?)",
                    "iiss",
                    [$device_id, $port, $protocol, $name]
                );
                // Inbound connections that already hit this device on this port had no
                // service to point at. Now that it exists, link them (the TARGETS relationship).
                $linked = run(
                    "UPDATE connection c
                     JOIN interface i ON i.mac_address = c.mac_address
                     SET c.svc_device_id = ?, c.svc_port = ?, c.svc_protocol = ?
                     WHERE i.device_id = ?
                       AND c.direction = 'inbound'
                       AND c.local_port = ?
                       AND c.protocol = ?
                       AND c.svc_device_id IS NULL",
                    "iisiis",
                    [$device_id, $port, $protocol, $device_id, $port, $protocol]
                );
                db()->commit();
                $message = "Registered $name on port $port/$protocol and linked $linked earlier connections to it.";
            } catch (mysqli_sql_exception $e) {
                db()->rollback();
                throw $e;
            }
        }
    } elseif ($action == "add_org") {
        $name = trim($_POST["org_name"] ?? "");
        $country = strtoupper(trim($_POST["country"] ?? ""));
        $asn = trim($_POST["asn"] ?? "");
        if ($name === "" || ($country !== "" && strlen($country) != 2)) {
            $error = "An organization needs a name, and the country is a 2-letter code.";
        } else {
            run(
                "INSERT INTO organization (org_name, country, asn) VALUES (?, NULLIF(?, ''), NULLIF(?, ''))",
                "sss",
                [$name, $country, $asn]
            );
            $message = "Added organization $name.";
        }
    } elseif ($action == "label_ip") {
        $ip = trim($_POST["remote_ip"] ?? "");
        $org_id = (int) $_POST["org_id"];
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $error = "Enter a valid IP address.";
        } else {
            $changed = run(
                "UPDATE connection SET org_id = ? WHERE remote_ip = ? AND direction <> 'internal'",
                "is",
                [$org_id, $ip]
            );
            $message = "Pointed $changed connections to $ip at that organization.";
        }
    }
} catch (mysqli_sql_exception $e) {
    // duplicate MAC, duplicate name, unknown device id and so on end up here
    $error = "Database refused the change: " . $e->getMessage();
}

// lists for the dropdowns and the tables at the bottom
$devices = [];
foreach (rows("SELECT device_id, name FROM device ORDER BY name") as $d) {
    $devices[$d["device_id"]] = $d["name"];
}
$orgs = [];
foreach (rows("SELECT org_id, org_name FROM organization ORDER BY org_name") as $o) {
    $orgs[$o["org_id"]] = $o["org_name"];
}
$if_types = ["ethernet" => "ethernet", "wifi" => "wifi", "tailscale" => "tailscale"];
$protocols = ["tcp" => "tcp", "udp" => "udp"];

$device_list = rows(
    "SELECT d.device_id AS id, d.name, d.device_type AS type, d.owner, d.registered_on,
            COUNT(i.mac_address) AS interfaces,
            GROUP_CONCAT(CONCAT(i.if_type, ' ', i.mac_address) ORDER BY i.if_type SEPARATOR ', ') AS mac_addresses
     FROM device d
     LEFT JOIN interface i ON i.device_id = d.device_id
     GROUP BY d.device_id, d.name, d.device_type, d.owner, d.registered_on
     ORDER BY d.name"
);
$service_list = rows(
    "SELECT d.name AS device, s.port, s.protocol, s.service_name AS service
     FROM service s JOIN device d ON d.device_id = s.device_id
     ORDER BY d.name, s.port"
);
$org_list = rows("SELECT org_id AS id, org_name AS organization, country, asn FROM organization ORDER BY org_name");

page_header("Manage");
?>
<?php if ($error): ?>
    <p class="error"><?php echo h($error); ?></p>
<?php endif; ?>
<?php if ($message): ?>
    <p class="ok"><?php echo h($message); ?></p>
<?php endif; ?>

<h2>Register a device and its first interface</h2>
<form class="inline panel" method="post" action="manage.php">
    <input type="hidden" name="action" value="add_device">
    <label>Name <input type="text" name="name" maxlength="60" required></label>
    <label>Type <input type="text" name="device_type" maxlength="20" placeholder="laptop, phone, ..." required></label>
    <label>Owner <input type="text" name="owner" maxlength="40"></label>
    <label>MAC address <input type="text" name="mac_address" placeholder="aa:bb:cc:dd:ee:ff" required></label>
    <label>Interface type <select name="if_type"><?php echo options($if_types, "wifi"); ?></select></label>
    <button type="submit">Register device</button>
</form>

<h2>Add another interface to a device</h2>
<form class="inline panel" method="post" action="manage.php">
    <input type="hidden" name="action" value="add_interface">
    <label>Device <select name="device_id"><?php echo options($devices, ""); ?></select></label>
    <label>MAC address <input type="text" name="mac_address" placeholder="aa:bb:cc:dd:ee:ff" required></label>
    <label>Interface type <select name="if_type"><?php echo options($if_types, "wifi"); ?></select></label>
    <button type="submit">Add interface</button>
</form>

<h2>Register a service</h2>
<p class="hint">Earlier inbound connections that hit this device on this port get linked to the new service, so they drop out of report 7.</p>
<form class="inline panel" method="post" action="manage.php">
    <input type="hidden" name="action" value="add_service">
    <label>Device <select name="device_id"><?php echo options($devices, ""); ?></select></label>
    <label>Port <input type="number" name="port" min="1" max="65535" required></label>
    <label>Protocol <select name="protocol"><?php echo options($protocols, "tcp"); ?></select></label>
    <label>Service name <input type="text" name="service_name" maxlength="60" required></label>
    <button type="submit">Register service</button>
</form>

<h2>Add an organization</h2>
<form class="inline panel" method="post" action="manage.php">
    <input type="hidden" name="action" value="add_org">
    <label>Name <input type="text" name="org_name" maxlength="100" required></label>
    <label>Country <input type="text" name="country" maxlength="2" placeholder="US"></label>
    <label>ASN <input type="number" name="asn" min="1"></label>
    <button type="submit">Add organization</button>
</form>

<h2>Label an outside IP address</h2>
<p class="hint">Sets the organization on every connection to or from this remote IP.</p>
<form class="inline panel" method="post" action="manage.php">
    <input type="hidden" name="action" value="label_ip">
    <label>Remote IP <input type="text" name="remote_ip" required></label>
    <label>Organization <select name="org_id"><?php echo options($orgs, ""); ?></select></label>
    <button type="submit">Label address</button>
</form>

<h2>Devices</h2>
<?php show_table($device_list, function ($row) {
    return "connections.php?days=30&device_id=" . $row["id"];
}); ?>

<h2>Services</h2>
<?php show_table($service_list); ?>

<h2>Organizations</h2>
<?php show_table($org_list, function ($row) {
    return "connections.php?days=30&org_id=" . $row["id"];
}); ?>
<?php
page_footer();
