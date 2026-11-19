<?php
/*
 * config.php
 *
 * WHAT THIS FILE DOES
 * Holds the settings every page needs. There is no logic in here, only constants.
 * db.php includes this file, and every page includes db.php, so these constants
 * are available everywhere.
 *
 * WHY IT IS ITS OWN FILE
 * If the database login ever changes, this is the only PHP file that has to be edited.
 */

// ---- Section 1: database login ----
// define(NAME, value) creates a constant. These four are handed to mysqli in db.php.
// jetson / orin is the same account the class pages use. localhost means the
// database is on the same machine as the web server.
// The simulator has the same defaults (see the top of simulator/netsim.py).
define("DB_HOST", "localhost");
define("DB_USER", "jetson");
define("DB_PASS", "orin");
define("DB_NAME", "netobs");

// ---- Section 2: time zone ----
// Times are stored in UTC. Pages show them shifted by this much (US Central standard time).
define("LOCAL_OFFSET", "-06:00");
