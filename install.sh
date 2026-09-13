#!/bin/sh
#
# aipanel installer.
#
#   curl -fsSL https://raw.githubusercontent.com/i-m-satya/aipanel/main/install.sh | sh
#   curl -fsSL .../install.sh | sh -s -- --port 2087
#
# While the repository is private, both fetching this script and cloning the
# panel need a GitHub token with read access to it:
#
#   curl -fsSL -H "Authorization: Bearer $GH_TOKEN" \
#     https://raw.githubusercontent.com/i-m-satya/aipanel/main/install.sh \
#     | sudo AIPANEL_TOKEN="$GH_TOKEN" sh
#
# Installs the panel and its dependencies on a Linux server, provisions the
# database, writes systemd units for the web, worker and scheduler processes,
# and prints the URL to finish setup in the browser. The first GitHub account
# to sign in becomes the administrator.
#
# The ACME webroot is created here so that Let's Encrypt challenges for every
# domain the panel manages are served from one place.
#
# Supported: Debian/Ubuntu (apt), RHEL/Rocky/Alma/Fedora (dnf), Alpine (apk).

set -eu

REPO_URL="${AIPANEL_REPO:-https://github.com/i-m-satya/aipanel.git}"
BRANCH="${AIPANEL_BRANCH:-main}"
INSTALL_DIR="${AIPANEL_DIR:-/opt/aipanel}"
PORT="${AIPANEL_PORT:-2087}"
RUN_USER="aipanel"
ASSUME_YES="${AIPANEL_YES:-0}"
TOKEN="${AIPANEL_TOKEN:-}"

# ---------------------------------------------------------------- output

red()    { printf '\033[31m%s\033[0m\n' "$*" >&2; }
green()  { printf '\033[32m%s\033[0m\n' "$*"; }
bold()   { printf '\033[1m%s\033[0m\n' "$*"; }
step()   { printf '\n\033[1m==>\033[0m %s\n' "$*"; }
die()    { red "error: $*"; exit 1; }

usage() {
    cat <<USAGE
aipanel installer

  --port <n>      port the panel listens on (default: ${PORT})
  --dir <path>    install directory (default: ${INSTALL_DIR})
  --branch <ref>  branch or tag to install (default: ${BRANCH})
  --token <tok>   GitHub token, required while the repository is private
  --yes           do not prompt; accept defaults
  --help          show this message

Environment equivalents: AIPANEL_PORT, AIPANEL_DIR, AIPANEL_BRANCH, AIPANEL_YES,
AIPANEL_TOKEN. Prefer the environment variable for the token: an argument is
visible to anyone who can read the process list.
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --port)   PORT="${2:?--port needs a value}"; shift 2 ;;
        --port=*) PORT="${1#*=}"; shift ;;
        --dir)    INSTALL_DIR="${2:?--dir needs a value}"; shift 2 ;;
        --dir=*)  INSTALL_DIR="${1#*=}"; shift ;;
        --branch) BRANCH="${2:?--branch needs a value}"; shift 2 ;;
        --branch=*) BRANCH="${1#*=}"; shift ;;
        --token)  TOKEN="${2:?--token needs a value}"; shift 2 ;;
        --token=*) TOKEN="${1#*=}"; shift ;;
        --yes|-y) ASSUME_YES=1; shift ;;
        --help|-h) usage; exit 0 ;;
        *) die "unknown option: $1 (try --help)" ;;
    esac
done

# ---------------------------------------------------------------- checks

[ "$(id -u)" -eq 0 ] || die "run as root (sudo sh install.sh)"

case "$PORT" in
    ''|*[!0-9]*) die "port must be a number, got '$PORT'" ;;
esac
if [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
    die "port must be between 1 and 65535"
fi

if [ "$ASSUME_YES" != "1" ] && [ -t 0 ]; then
    printf 'Port for the panel [%s]: ' "$PORT"
    read -r answer || answer=''
    if [ -n "$answer" ]; then
        case "$answer" in
            ''|*[!0-9]*) die "port must be a number, got '$answer'" ;;
        esac
        PORT="$answer"
    fi
fi

# A port already in use would leave the panel silently unreachable.
if command -v ss >/dev/null 2>&1 && ss -lnt 2>/dev/null | grep -q ":${PORT} "; then
    die "port ${PORT} is already in use — choose another with --port"
fi

# ------------------------------------------------------------ packages

detect_pm() {
    if command -v apt-get >/dev/null 2>&1; then echo apt
    elif command -v dnf >/dev/null 2>&1; then echo dnf
    elif command -v apk >/dev/null 2>&1; then echo apk
    else echo unsupported
    fi
}

PM="$(detect_pm)"
[ "$PM" = unsupported ] && die "no supported package manager found (apt, dnf or apk)"

step "Installing dependencies with ${PM}"
case "$PM" in
    apt)
        export DEBIAN_FRONTEND=noninteractive
        apt-get update -qq
        apt-get install -y -qq --no-install-recommends \
            git curl unzip ca-certificates \
            php-cli php-mysql php-curl php-mbstring php-xml php-zip \
            mariadb-server nginx openssh-client certbot
        DB_SERVICE=mariadb
        ;;
    dnf)
        dnf install -y -q \
            git curl unzip \
            php-cli php-mysqlnd php-curl php-mbstring php-xml \
            mariadb-server nginx openssh-clients certbot
        DB_SERVICE=mariadb
        ;;
    apk)
        apk add --no-cache \
            git curl unzip \
            php83-cli php83-pdo_mysql php83-curl php83-mbstring php83-openssl \
            php83-session php83-tokenizer php83-fileinfo php83-phar php83-dom \
            mariadb mariadb-client nginx openssh-client certbot
        DB_SERVICE=mariadb
        ;;
esac

PHP_BIN="$(command -v php || true)"
[ -n "$PHP_BIN" ] || PHP_BIN="$(command -v php83 || true)"
[ -n "$PHP_BIN" ] || die "php was installed but is not on PATH"

PHP_VERSION="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
case "$PHP_VERSION" in
    8.3|8.4|8.5|9.*) : ;;
    *) die "aipanel needs PHP 8.3 or newer; this server has ${PHP_VERSION}" ;;
esac
green "php ${PHP_VERSION} at ${PHP_BIN}"

# ----------------------------------------------------------- composer

if ! command -v composer >/dev/null 2>&1; then
    step "Installing Composer"
    # Verify the installer against the published hash before running it.
    expected="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    actual="$("$PHP_BIN" -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
    [ "$expected" = "$actual" ] || die "Composer installer checksum mismatch — refusing to run it"
    "$PHP_BIN" /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
fi

# --------------------------------------------------------------- code

step "Installing aipanel into ${INSTALL_DIR}"

# A token is only needed while the repository is private. It is passed to git
# through an askpass helper rather than embedded in the remote URL, so it never
# lands in .git/config, the reflog, or a later `git remote -v`.
if [ -n "$TOKEN" ]; then
    ASKPASS="$(mktemp)"
    cat > "$ASKPASS" <<ASK
#!/bin/sh
case "\$1" in
    *Username*) echo "x-access-token" ;;
    *) echo "${TOKEN}" ;;
esac
ASK
    chmod 700 "$ASKPASS"
    export GIT_ASKPASS="$ASKPASS"
    # Remove it however this script exits, successfully or not.
    trap 'rm -f "$ASKPASS"' EXIT HUP INT TERM
fi

# Never let git stop on an interactive credential prompt during an unattended
# install; a missing credential should fail with the message below instead.
export GIT_TERMINAL_PROMPT=0

if [ -d "${INSTALL_DIR}/.git" ]; then
    git -C "$INSTALL_DIR" fetch --quiet origin "$BRANCH"
    git -C "$INSTALL_DIR" checkout --quiet "$BRANCH"
    git -C "$INSTALL_DIR" reset --hard --quiet "origin/${BRANCH}"
elif ! git clone --quiet --branch "$BRANCH" --depth 1 "$REPO_URL" "$INSTALL_DIR"; then
    if [ -z "$TOKEN" ]; then
        die "could not clone ${REPO_URL}.
  The repository is private, so the installer needs a GitHub token with read
  access to it. Re-run with:  sudo AIPANEL_TOKEN=<token> sh install.sh"
    fi
    die "could not clone ${REPO_URL} — check that the token has read access to it"
fi

id -u "$RUN_USER" >/dev/null 2>&1 || \
    useradd --system --home-dir "$INSTALL_DIR" --shell /usr/sbin/nologin "$RUN_USER" 2>/dev/null || \
    adduser -S -H -h "$INSTALL_DIR" -s /sbin/nologin "$RUN_USER"

# The 'sites' group is what the sshd jail rule matches on; tenant users are
# added to it as websites are created.
getent group sites >/dev/null 2>&1 || groupadd sites 2>/dev/null || addgroup sites 2>/dev/null || true

# Shared ACME challenge webroot: every managed domain answers Let's Encrypt
# from here, so certificates are issued and renewed without per-site setup.
mkdir -p /var/www/acme
chmod 755 /var/www/acme

cd "$INSTALL_DIR"
composer install --no-interaction --no-dev --prefer-dist --no-progress --quiet

# ----------------------------------------------------------- database

step "Preparing the database"
if command -v systemctl >/dev/null 2>&1; then
    systemctl enable --now "$DB_SERVICE" >/dev/null 2>&1 || systemctl enable --now mysqld >/dev/null 2>&1 || true
else
    rc-update add mariadb default >/dev/null 2>&1 || true
    /etc/init.d/mariadb setup >/dev/null 2>&1 || true
    rc-service mariadb start >/dev/null 2>&1 || true
fi

DB_NAME=aipanel
DB_USER=aipanel
DB_PASS="$("$PHP_BIN" -r 'echo bin2hex(random_bytes(16));')"

mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

# ------------------------------------------------------------- config

step "Writing configuration"
APP_KEY="$("$PHP_BIN" -r 'echo base64_encode(random_bytes(32));')"
HOST_ADDR="$(curl -fsS --max-time 5 https://api.ipify.org 2>/dev/null || hostname -I 2>/dev/null | awk '{print $1}' || echo localhost)"
APP_URL="http://${HOST_ADDR}:${PORT}"

if [ ! -f "${INSTALL_DIR}/.env" ]; then
    cp "${INSTALL_DIR}/.env.example" "${INSTALL_DIR}/.env"
fi

set_env() {
    key="$1"; value="$2"
    if grep -q "^${key}=" "${INSTALL_DIR}/.env"; then
        # The value can contain slashes and ampersands, so use a delimiter that
        # cannot appear in it and escape the replacement.
        escaped="$(printf '%s' "$value" | sed -e 's/[&|\\]/\\&/g')"
        sed -i "s|^${key}=.*|${key}=${escaped}|" "${INSTALL_DIR}/.env"
    else
        printf '%s=%s\n' "$key" "$value" >> "${INSTALL_DIR}/.env"
    fi
}

set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "$APP_URL"
set_env APP_KEY "$APP_KEY"
set_env DB_DSN "mysql:host=127.0.0.1;port=3306;dbname=${DB_NAME};charset=utf8mb4"
set_env DB_USER "$DB_USER"
set_env DB_PASS "$DB_PASS"
set_env REDIS_DSN ''
set_env AIPANEL_PORT "$PORT"

chown -R "${RUN_USER}:${RUN_USER}" "$INSTALL_DIR"
chmod 600 "${INSTALL_DIR}/.env"

step "Applying migrations"
su -s /bin/sh "$RUN_USER" -c "cd '${INSTALL_DIR}' && '${PHP_BIN}' bin/console.php migrate"

# ------------------------------------------------------------ services

step "Installing services"
if command -v systemctl >/dev/null 2>&1; then
    cat > /etc/systemd/system/aipanel.service <<UNIT
[Unit]
Description=aipanel control plane
After=network.target ${DB_SERVICE}.service

[Service]
Type=simple
User=${RUN_USER}
WorkingDirectory=${INSTALL_DIR}
ExecStart=${PHP_BIN} -S 0.0.0.0:${PORT} -t ${INSTALL_DIR}/public
Restart=always
RestartSec=2
NoNewPrivileges=yes
PrivateTmp=yes

[Install]
WantedBy=multi-user.target
UNIT

    # Provisioning, deploys and certificates. Safe to run several.
    cat > /etc/systemd/system/aipanel-worker.service <<UNIT
[Unit]
Description=aipanel worker
After=network.target ${DB_SERVICE}.service

[Service]
Type=simple
User=${RUN_USER}
WorkingDirectory=${INSTALL_DIR}
ExecStart=${PHP_BIN} ${INSTALL_DIR}/bin/worker.php
Restart=always
RestartSec=2
# Let an in-flight deploy finish rather than cutting it in half on restart.
KillSignal=SIGTERM
TimeoutStopSec=120
NoNewPrivileges=yes

[Install]
WantedBy=multi-user.target
UNIT

    cat > /etc/systemd/system/aipanel-scheduler.service <<UNIT
[Unit]
Description=aipanel scheduler
After=network.target ${DB_SERVICE}.service

[Service]
Type=simple
User=${RUN_USER}
WorkingDirectory=${INSTALL_DIR}
ExecStart=${PHP_BIN} ${INSTALL_DIR}/bin/scheduler.php
Restart=always
RestartSec=5
NoNewPrivileges=yes

[Install]
WantedBy=multi-user.target
UNIT

    systemctl daemon-reload
    systemctl enable --now aipanel.service aipanel-worker.service \
        aipanel-scheduler.service >/dev/null 2>&1
else
    cat > /etc/init.d/aipanel <<RC
#!/sbin/openrc-run
command="${PHP_BIN}"
command_args="-S 0.0.0.0:${PORT} -t ${INSTALL_DIR}/public"
command_user="${RUN_USER}"
command_background=true
pidfile="/run/aipanel.pid"
RC
    chmod +x /etc/init.d/aipanel
    rc-update add aipanel default >/dev/null 2>&1 || true
    rc-service aipanel start >/dev/null 2>&1 || true
fi

# --------------------------------------------------------------- firewall

if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q '^Status: active'; then
    ufw allow "${PORT}/tcp" >/dev/null 2>&1 || true
elif command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1; then
    firewall-cmd --permanent --add-port="${PORT}/tcp" >/dev/null 2>&1 || true
    firewall-cmd --reload >/dev/null 2>&1 || true
fi

# ----------------------------------------------------------------- done

printf '\n'
for _ in 1 2 3 4 5 6 7 8 9 10; do
    if curl -fsS --max-time 2 "http://127.0.0.1:${PORT}/health" >/dev/null 2>&1; then
        break
    fi
    sleep 1
done

if curl -fsS --max-time 2 "http://127.0.0.1:${PORT}/health" >/dev/null 2>&1; then
    green "aipanel is running on port ${PORT}"
else
    red "aipanel did not answer on port ${PORT} yet — check: journalctl -u aipanel -n 50"
fi

bold "
  Next: connect GitHub, then claim the panel
  ------------------------------------------
  Sign-in is GitHub-only, so the panel needs an OAuth app before anyone can
  log in. Create one at https://github.com/settings/developers with:

    Homepage URL:               ${APP_URL}
    Authorization callback URL: ${APP_URL}/auth/github/callback

  Then put its credentials in ${INSTALL_DIR}/.env:

    GITHUB_OAUTH_CLIENT_ID=...
    GITHUB_OAUTH_CLIENT_SECRET=...

  and restart:  systemctl restart aipanel

  Finally open ${APP_URL} and sign in with GitHub.
  THE FIRST ACCOUNT TO SIGN IN BECOMES THE ADMINISTRATOR — do it now, before
  this address is reachable by anyone else.
"
