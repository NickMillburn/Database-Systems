<?php
// Database login. Same account the class pages use.
// The simulator has the same defaults (see the top of simulator/netsim.py).
define("DB_HOST", "localhost");
define("DB_USER", "jetson");
define("DB_PASS", "orin");
define("DB_NAME", "netobs");

// Times are stored in UTC. Pages show them shifted by this much (US Central standard time).
define("LOCAL_OFFSET", "-06:00");
