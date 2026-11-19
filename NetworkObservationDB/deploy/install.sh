#!/usr/bin/env bash
# Sets the project up on an Ubuntu machine: this laptop or the Jetson.
#
#   sudo bash deploy/install.sh            full LAMP setup + simulator service
#   sudo bash deploy/install.sh --dev      database only (use deploy/run_dev.sh for the pages)
#   sudo bash deploy/install.sh --reset    also wipe and reload the tables
#
# Safe to run again. It skips packages that are already installed and leaves
# existing data alone unless --reset is given.
#
# If the database root account needs a password:
#   sudo DB_ROOT_PASS=yourpassword bash deploy/install.sh
set -euo pipefail

DB_NAME=netobs
DB_USER=jetson
DB_PASS=orin
WEB_DIR=/var/www/html/netobs
SIM_DIR=/opt/netobs
UNIT=netobs-sim.service

DEV=0
RESET=0
for arg in "$@"; do
    case "$arg" in
        --dev) DEV=1 ;;
        --reset) RESET=1 ;;
        *) echo "Unknown option: $arg"; exit 1 ;;
    esac
done

if [[ $EUID -ne 0 ]]; then
    echo "Run this with sudo."
    exit 1
fi

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export DEBIAN_FRONTEND=noninteractive

step() { echo; echo "== $1"; }
installed() { dpkg-query -W -f='${Status}' "$1" 2>/dev/null | grep -q "install ok installed"; }

# ---------------------------------------------------------------- packages
step "Packages"
PACKAGES=(php-cli php-mysql python3 python3-pymysql)
# Keep whichever database server is already there. Install MariaDB only if there is none.
if ! installed mariadb-server && ! installed mysql-server && ! installed default-mysql-server; then
    PACKAGES+=(mariadb-server)
fi
if [[ $DEV -eq 0 ]]; then
    PACKAGES+=(apache2 libapache2-mod-php)
fi
MISSING=()
for pkg in "${PACKAGES[@]}"; do
    installed "$pkg" || MISSING+=("$pkg")
done
if [[ ${#MISSING[@]} -gt 0 ]]; then
    echo "Installing: ${MISSING[*]}"
    apt-get update -qq
    apt-get install -y -qq "${MISSING[@]}"
else
    echo "Everything needed is already installed."
fi

# ---------------------------------------------------------------- database
step "Database server"
if systemctl cat mariadb.service >/dev/null 2>&1; then
    systemctl enable --now mariadb
else
    systemctl enable --now mysql
fi

# root normally logs in through the local socket with no password when run under sudo
ROOT_SQL=(mysql)
if [[ -n "${DB_ROOT_PASS:-}" ]]; then
    ROOT_SQL=(mysql -uroot "-p${DB_ROOT_PASS}")
fi
if ! "${ROOT_SQL[@]}" -e "SELECT 1" >/dev/null 2>&1; then
    echo "Could not log in to the database as root."
    echo "If root has a password, run:  sudo DB_ROOT_PASS=yourpassword bash deploy/install.sh $*"
    exit 1
fi

# Stop the simulator before touching tables so it is not writing during the load.
if [[ $DEV -eq 0 ]] && systemctl is-active --quiet "$UNIT" 2>/dev/null; then
    systemctl stop "$UNIT"
fi

step "Database $DB_NAME and user $DB_USER"
"${ROOT_SQL[@]}" <<SQL
CREATE DATABASE IF NOT EXISTS $DB_NAME;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
if ! mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT 1" >/dev/null 2>&1; then
    echo "The $DB_USER account exists but its password is not '$DB_PASS'."
    echo "Either change it back or put the real password in web/config.php and $UNIT."
    exit 1
fi

HAS_TABLES=$("${ROOT_SQL[@]}" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_name='connection'")
if [[ $RESET -eq 1 || "$HAS_TABLES" == "0" ]]; then
    echo "Loading sql/schema.sql and sql/seed.sql"
    "${ROOT_SQL[@]}" < "$PROJECT_DIR/sql/schema.sql"
    "${ROOT_SQL[@]}" < "$PROJECT_DIR/sql/seed.sql"
else
    echo "Tables already exist, leaving them alone (use --reset to reload)."
fi

step "Simulated connection history"
python3 "$PROJECT_DIR/simulator/netsim.py" seed

if [[ $DEV -eq 1 ]]; then
    step "Done (dev setup)"
    echo "Start the pages and the simulator with:  bash deploy/run_dev.sh"
    echo "Then open http://localhost:8080/"
    exit 0
fi

# ---------------------------------------------------------------- web pages
step "Web pages -> $WEB_DIR"
mkdir -p "$WEB_DIR"
cp -r "$PROJECT_DIR/web/." "$WEB_DIR/"
systemctl enable --now apache2

# ---------------------------------------------------------------- phpMyAdmin
step "phpMyAdmin"
if [[ -d /usr/share/phpmyadmin ]]; then
    echo "Already installed."
else
    # Answer the installer's questions ahead of time: hook it into Apache, and skip
    # its optional settings database (not needed to browse tables).
    echo "phpmyadmin phpmyadmin/reconfigure-webserver multiselect apache2" | debconf-set-selections
    echo "phpmyadmin phpmyadmin/dbconfig-install boolean false" | debconf-set-selections
    apt-get update -qq
    apt-get install -y -qq phpmyadmin
fi
if [[ -f /etc/phpmyadmin/apache.conf && ! -e /etc/apache2/conf-enabled/phpmyadmin.conf ]]; then
    ln -sf /etc/phpmyadmin/apache.conf /etc/apache2/conf-available/phpmyadmin.conf
    a2enconf -q phpmyadmin
fi
systemctl reload apache2

# ---------------------------------------------------------------- simulator service
step "Simulator service ($UNIT)"
install -D -m 644 "$PROJECT_DIR/simulator/netsim.py" "$SIM_DIR/netsim.py"
install -m 644 "$PROJECT_DIR/deploy/$UNIT" "/etc/systemd/system/$UNIT"
systemctl daemon-reload
systemctl enable "$UNIT"
systemctl restart "$UNIT"

IP=$(hostname -I 2>/dev/null | awk '{print $1}')
step "Done"
echo "Pages:       http://${IP:-localhost}/netobs/"
echo "phpMyAdmin:  http://${IP:-localhost}/phpmyadmin/   (log in as $DB_USER / $DB_PASS)"
echo "Simulator:   systemctl status $UNIT"
echo "Live log:    journalctl -u $UNIT -f"
