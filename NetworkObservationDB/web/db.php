<?php
/*
 * db.php
 *
 * WHAT THIS FILE DOES
 * The shared toolbox. Every page starts with require_once "db.php" and then
 * uses the functions defined here. Nothing in this file prints anything on its
 * own. It only defines functions.
 *
 * WHAT IS IN IT
 *   db()           opens the MySQL connection (once per page load)
 *   rows()         runs a SELECT and returns the result rows
 *   run()          runs an INSERT or UPDATE and returns how many rows changed
 *   local_time()   SQL snippet that converts stored UTC time to local time
 *   h()            escapes text so it is safe to put in HTML
 *   human_bytes()  turns 1536 into "1.5 KB"
 *   page_header()  prints the top of the page, the navigation bar, and the note
 *                  saying how the page meets the assignment requirements
 *   page_footer()  prints the closing tags
 *   show_table()   prints query results as an HTML table
 *   options()      builds the <option> tags for a dropdown
 *
 * THE IMPORTANT IDEA: PREPARED STATEMENTS
 * rows() and run() never glue user input into the SQL text. The SQL is written
 * with ? placeholders and the values are sent separately with bind_param. The
 * database treats those values as data only, so a user cannot type SQL into a
 * form field and have it run (SQL injection).
 */

// Pull in the DB_HOST / DB_USER / DB_PASS / DB_NAME constants.
require_once __DIR__ . "/config.php";

//
// ---- Section 1: the database connection ----
// Make mysqli throw exceptions on errors (PHP 8.1+ already does; this makes older PHP match).
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// One connection per page load, opened the first time it is needed.
function db()
{
    // static means $conn keeps its value between calls. The first call connects.
    // Every later call on the same page load skips the if and returns the same connection.
    static $conn = null;
    if ($conn === null) {
        try {
            // open the connection using the constants from config.php
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            $conn->set_charset("utf8mb4");
        // if the login fails, stop the page here with a readable message
        } catch (mysqli_sql_exception $e) {
            die("Database connection failed: " . htmlspecialchars($e->getMessage()));
        }
    }
    return $conn;
}

// ---- Section 2: query helpers (used for every query on every page) ----
//
// Run a SELECT as a prepared statement and return every row as an associative array.
// $types is the bind_param string ("i" = integer, "s" = string), one letter per "?".
function rows($sql, $types = "", $params = [])
{
    // step 1, prepare: send the SQL with its ? placeholders to the database
    $stmt = db()->prepare($sql);
    if ($types !== "") {
        // step 2, bind: attach one value to each ?. The ... spreads the array into separate arguments.
        $stmt->bind_param($types, ...$params);
    }
    // step 3, execute: run it
    $stmt->execute();
    // step 4, fetch: every row comes back as an array keyed by column name, e.g. $row["name"]
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $result;
}

// Run an INSERT or UPDATE as a prepared statement. Returns the number of rows it changed.
function run($sql, $types = "", $params = [])
{
    // same prepare / bind / execute steps as rows()
    $stmt = db()->prepare($sql);
    if ($types !== "") {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    // there are no rows to fetch for an INSERT or UPDATE, so report how many rows it touched
    $changed = $stmt->affected_rows;
    $stmt->close();
    return $changed;
}

// ---- Section 3: small formatting helpers ----
//
// SQL snippet that shifts a stored UTC time to local time.
function local_time($column)
{
    // e.g. local_time("c.started_at") gives CONVERT_TZ(c.started_at, '+00:00', '-06:00')
    return "CONVERT_TZ($column, '+00:00', '" . LOCAL_OFFSET . "')";
}

// Escape text before putting it in the page.
function h($value)
{
    // turns < > & and quotes into harmless codes, so data from the database cannot inject HTML
    return htmlspecialchars((string) $value, ENT_QUOTES);
}

// 1536 -> "1.5 KB"
function human_bytes($n)
{
    $units = ["B", "KB", "MB", "GB", "TB"];
    $n = (float) $n;
    $u = 0;
    // keep dividing by 1024 and moving up a unit until the number is small
    while ($n >= 1024 && $u < count($units) - 1) {
        $n /= 1024;
        $u++;
    }
    return ($u == 0 ? (string) (int) $n : number_format($n, 1)) . " " . $units[$u];
}

// ---- Section 4: page layout (top and bottom of every page) ----
//
// $refresh > 0 makes the browser reload the page every that many seconds.
// $about is the short note shown under the title that says how the page meets
// the assignment requirements. Leave it out and no note is shown.
function page_header($title, $refresh = 0, $about = "")
{
    // the navigation bar: file name => link text
    $pages = [
        "index.php" => "Dashboard",
        "reports.php" => "Reports",
        "connections.php" => "Connections",
        "add_connection.php" => "Log a connection",
        "manage.php" => "Manage",
    ];
    // the file being viewed right now, so its link can be highlighted
    $current = basename($_SERVER["SCRIPT_NAME"]);
    // the HTML <head>: title and the shared stylesheet
    echo "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    if ($refresh > 0) {
        echo "<meta http-equiv=\"refresh\" content=\"" . (int) $refresh . "\">\n";
    }
    echo "<title>" . h($title) . " - Network Observation Database</title>\n";
    echo "<link rel=\"stylesheet\" href=\"style.css\">\n</head>\n<body>\n";
    // the dark bar across the top with one link per page
    echo "<header><strong>Network Observation Database</strong><nav>";
    foreach ($pages as $file => $label) {
        $class = $file == $current ? " class=\"here\"" : "";
        echo "<a href=\"$file\"$class>$label</a>";
    }
    echo "<a href=\"/phpmyadmin/\" target=\"_blank\">phpMyAdmin</a>";
    echo "</nav></header>\n<main>\n<h1>" . h($title) . "</h1>\n";
    // the blue note under the title: how this page meets the assignment requirements
    if ($about !== "") {
        echo "<p class=\"about\"><b>How this page meets the requirements:</b> " . h($about) . "</p>\n";
    }
}

function page_footer()
{
    echo "</main>\n</body>\n</html>\n";
}

// ---- Section 5: HTML output helpers ----
//
// Print query results as an HTML table. The column names become the headings.
//   - columns whose name starts with "_" are hidden (used to build links)
//   - columns with "bytes" in the name are shown as KB / MB / GB
//   - $link, if given, is a function that takes a row and returns a URL for a "view" link
function show_table($rows, $link = null)
{
    // nothing came back from the query
    if (!$rows) {
        echo "<p class=\"empty\">No rows.</p>\n";
        return;
    }
    // header row: one <th> per column, taken from the first row's keys
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
    // body: one <tr> per result row, one <td> per column
    foreach ($rows as $row) {
        echo "<tr>";
        foreach ($row as $col => $value) {
            if ($col[0] == "_") {
                continue;
            }
            // pick how to show the value: NULL, a byte count, a number, or plain text
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
            // extra last cell: the drill-down link built from this row
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
        // mark the option that should be pre-selected
        $sel = ((string) $value === (string) $selected) ? " selected" : "";
        $html .= "<option value=\"" . h($value) . "\"$sel>" . h($label) . "</option>";
    }
    return $html;
}
