#!/usr/bin/env bash
# Removes what install.sh added for this project. Leaves Apache, PHP, the
# database server and phpMyAdmin installed.
#
#   sudo bash deploy/uninstall.sh             remove the service and the web pages
#   sudo bash deploy/uninstall.sh --drop-db   also delete the netobs database
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "Run this with sudo."
    exit 1
fi

systemctl disable --now netobs-sim.service 2>/dev/null || true
rm -f /etc/systemd/system/netobs-sim.service
systemctl daemon-reload
rm -rf /opt/netobs /var/www/html/netobs
echo "Removed the simulator service and the web pages."

if [[ "${1:-}" == "--drop-db" ]]; then
    mysql -e "DROP DATABASE IF EXISTS netobs;"
    echo "Dropped the netobs database (the jetson login was left in place)."
fi
