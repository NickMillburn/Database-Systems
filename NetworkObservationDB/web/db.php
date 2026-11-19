<?php
// Shared by every page: the database connection, two query helpers,
// and the functions that print the page header, footer and result tables.
require_once __DIR__ . "/config.php";

// Make mysqli throw exceptions on errors (PHP 8.1+ already does; this makes older PHP match).
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// One connection per page load, opened the first time it is needed.
function db()
{
    static $conn = null;
    if ($conn === null) {
        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            $conn->set_charset("utf8mb4");
        } catch (mysqli_sql_exception $e) {
            die("Database connection failed: " . htmlspecialchars($e->getMessage()));
        }
    }
    return $conn;
}

// Run a SELECT as a prepared statement and return every row as an associative array.
// $types is the bind_param string ("i" = integer, "s" = string), one letter per "?".
function rows($sql, $types = "", $params = [])
{
    $stmt = db()->prepare($sql);
    if ($types !== "") {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $result;
}

// Run an INSERT or UPDATE as a prepared statement. Returns the number of rows it changed.
function run($sql, $types = "", $params = [])
{
    $stmt = db()->prepare($sql);
    if ($types !== "") {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    return $changed;
}

// SQL snippet that shifts a stored UTC time to local time.
function local_time($column)
{
    return "CONVERT_TZ($column, '+00:00', '" . LOCAL_OFFSET . "')";
}

// Escape text before putting it in the page.
function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES);
}

// 1536 -> "1.5 KB"
function human_bytes($n)
{
    $units = ["B", "KB", "MB", "GB", "TB"];
    $n = (float) $n;
    $u = 0;
    while ($n >= 1024 && $u < count($units) - 1) {
        $n /= 1024;
        $u++;
    }
    return ($u == 0 ? (string) (int) $n : number_format($n, 1)) . " " . $units[$u];
}

// $refresh > 0 makes the browser reload the page every that many seconds.
function page_header($title, $refresh = 0)
{
    $pages = [
        "index.php" => "Dashboard",
        "reports.php" => "Reports",
        "connections.php" => "Connections",
        "add_connection.php" => "Log a connection",
        "manage.php" => "Manage",
    ];
    $current = basename($_SERVER["SCRIPT_NAME"]);
    echo "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    if ($refresh > 0) {
        echo "<meta http-equiv=\"refresh\" content=\"" . (int) $refresh . "\">\n";
    }
    echo "<title>" . h($title) . " - Network Observation Database</title>\n";
    echo "<link rel=\"stylesheet\" href=\"style.css\">\n</head>\n<body>\n";
    echo "<header><strong>Network Observation Database</strong><nav>";
    foreach ($pages as $file => $label) {
        $class = $file == $current ? " class=\"here\"" : "";
        echo "<a href=\"$file\"$class>$label</a>";
    }
    echo "<a href=\"/phpmyadmin/\" target=\"_blank\">phpMyAdmin</a>";
    echo "</nav></header>\n<main>\n<h1>" . h($title) . "</h1>\n";
}

function page_footer()
{
    echo "</main>\n</body>\n</html>\n";
}

// Print query results as an HTML table. The column names become the headings.
//   - columns whose name starts with "_" are hidden (used to build links)
//   - columns with "bytes" in the name are shown as KB / MB / GB
//   - $link, if given, is a function that takes a row and returns a URL for a "view" link
function show_table($rows, $link = null)
{
    if (!$rows) {
        echo "<p class=\"empty\">No rows.</p>\n";
        return;
    }
    echo "<div class=\"scroll\"><table>\n<tr>";
    foreach (array_keys($rows[0]) as $col) {
        if ($col[0] != "_") {
            echo "<th>" . h(str_replace("_", " ", $col)) . "</th>";
        }
    }
    if ($link) {
        echo "<th></th>";
    }
    echo "</tr>\n";
    foreach ($rows as $row) {
        echo "<tr>";
        foreach ($row as $col => $value) {
            if ($col[0] == "_") {
                continue;
            }
            if ($value === null) {
                echo "<td class=\"null\">none</td>";
            } elseif (strpos($col, "bytes") !== false) {
                echo "<td class=\"num\">" . human_bytes($value) . "</td>";
            } elseif (is_int($value) || is_float($value)) {
                echo "<td class=\"num\">" . h($value) . "</td>";
            } else {
                echo "<td>" . h($value) . "</td>";
            }
        }
        if ($link) {
            echo "<td><a href=\"" . h($link($row)) . "\">view</a></td>";
        }
        echo "</tr>\n";
    }
    echo "</table></div>\n";
}

// <option> tags for a <select>. $options is value => label.
function options($options, $selected)
{
    $html = "";
    foreach ($options as $value => $label) {
        $sel = ((string) $value === (string) $selected) ? " selected" : "";
        $html .= "<option value=\"" . h($value) . "\"$sel>" . h($label) . "</option>";
    }
    return $html;
}
