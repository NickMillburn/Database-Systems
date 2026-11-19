#!/usr/bin/env bash
# run_dev.sh
#
# WHAT THIS FILE DOES
# Run the project without Apache or systemd: PHP's built-in web server plus the
# simulator in the foreground. Ctrl-C stops both.
#
#   bash deploy/run_dev.sh         normal traffic rate
#   bash deploy/run_dev.sh 20      20x traffic, easier to watch
#
# Needs the database set up first:  sudo bash deploy/install.sh --dev
# work from the project folder no matter where the script was called from
cd "$(dirname "${BASH_SOURCE[0]}")/.."

# start the simulator in the background (&) and remember its process id
python3 -u simulator/netsim.py run --speed "${1:-1}" &
SIM_PID=$!
# when this script exits (Ctrl-C), stop the simulator too
trap 'kill $SIM_PID 2>/dev/null' EXIT

echo "Pages at http://localhost:8080/"
# serve the web/ folder on port 8080 until Ctrl-C
php -S localhost:8080 -t web
