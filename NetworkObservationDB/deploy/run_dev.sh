#!/usr/bin/env bash
# Run the project without Apache or systemd: PHP's built-in web server plus the
# simulator in the foreground. Ctrl-C stops both.
#
#   bash deploy/run_dev.sh         normal traffic rate
#   bash deploy/run_dev.sh 20      20x traffic, easier to watch
#
# Needs the database set up first:  sudo bash deploy/install.sh --dev
cd "$(dirname "${BASH_SOURCE[0]}")/.."

python3 -u simulator/netsim.py run --speed "${1:-1}" &
SIM_PID=$!
trap 'kill $SIM_PID 2>/dev/null' EXIT

echo "Pages at http://localhost:8080/"
php -S localhost:8080 -t web
