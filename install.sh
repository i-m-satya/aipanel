#!/bin/sh
#
# aipanel installer.
#
#   curl -fsSL https://raw.githubusercontent.com/i-m-satya/aipanel/main/install.sh | sh
#   curl -fsSL .../install.sh | sh -s -- --port 2087
#
# Installing from a fork (private or not) needs the fork's URL as well, because
# this script clones AIPANEL_REPO rather than wherever it was downloaded from:
#
#   curl -fsSL -H "Authorization: Bearer $GH_TOKEN" \
#     https://raw.githubusercontent.com/<owner>/<repo>/main/install.sh \
#     | sudo AIPANEL_TOKEN="$GH_TOKEN" \
#            AIPANEL_REPO="https://github.com/<owner>/<repo>.git" sh
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

DEFAULT_REPO_URL="https://github.com/i-m-satya/aipanel.git"
REPO_URL="${AIPANEL_REPO:-$DEFAULT_REPO_URL}"
BRANCH="${AIPANEL_BRANCH:-main}"
INSTALL_DIR="${AIPANEL_DIR:-/opt/aipanel}"
PORT="${AIPANEL_PORT:-2087}"
RUN_USER="aipanel"
AGENT_PORT="${AIPANEL_AGENT_PORT:-9443}"
ASSUME_YES="${AIPANEL_YES:-0}"
# 'shared' hosts other people's sites: open signup, approval-gated first sites,
# and container isolation. 'single' is one operator hosting their own.
MODE="${AIPANEL_MODE:-single}"
# How the panel gets a database. 'auto' reuses a suitable server already on the
# host, otherwise installs one; 'container' always runs a dedicated one.
DB_STRATEGY="${AIPANEL_DB:-auto}"
DB_ROOT_PASS="${AIPANEL_DB_ROOT_PASS:-}"
DB_PORT="${AIPANEL_DB_PORT:-3306}"
DB_HOST="${AIPANEL_DB_HOST:-127.0.0.1}"
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
  --repo <url>    repository to install from (default: ${REPO_URL})
  --shared        shared hosting: open signup, site approval, container
                  isolation per tenant (installs a container runtime)
  --db <how>      auto (default): reuse a suitable MySQL/MariaDB already here,
                  else install one. container: run a dedicated MySQL 8 for the
                  panel, leaving any existing server alone.
  --branch <ref>  branch or tag to install (default: ${BRANCH})
  --token <tok>   GitHub token, for installing from a private fork
  --yes           do not prompt; accept defaults
  --help          show this message

Environment equivalents: AIPANEL_PORT, AIPANEL_DIR, AIPANEL_BRANCH, AIPANEL_REPO,
AIPANEL_YES, AIPANEL_TOKEN, AIPANEL_DB, AIPANEL_DB_ROOT_PASS, AIPANEL_DB_HOST,
AIPANEL_DB_PORT. Prefer the environment variable for the token: an
argument is visible to anyone who can read the process list.

Note that downloading this script from a fork does not by itself install that
fork — pass --repo (or AIPANEL_REPO) with the fork's clone URL.
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --port)   PORT="${2:?--port needs a value}"; shift 2 ;;
        --port=*) PORT="${1#*=}"; shift ;;
        --dir)    INSTALL_DIR="${2:?--dir needs a value}"; shift 2 ;;
        --dir=*)  INSTALL_DIR="${1#*=}"; shift ;;
        --shared) MODE=shared; shift ;;
        --db)     DB_STRATEGY="${2:?--db needs a value}"; shift 2 ;;
        --db=*)   DB_STRATEGY="${1#*=}"; shift ;;
        --repo)   REPO_URL="${2:?--repo needs a value}"; shift 2 ;;
        --repo=*) REPO_URL="${1#*=}"; shift ;;
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

# ---------------------------------------------------------- database probe

# Decided before installing anything: the package that broke the most installs
# was mariadb-server, because its pre-install script tries to stop a MySQL that
# is already running and aborts the whole dpkg run when it cannot.
DB_SERVER_PRESENT=0
if command -v mysqld >/dev/null 2>&1 || command -v mariadbd >/dev/null 2>&1 \
   || [ -d /var/lib/mysql/mysql ]; then
    DB_SERVER_PRESENT=1
fi

step "Installing dependencies with ${PM}"

# PHP 8.3 is the floor. Ubuntu 22.04 ships 8.1 and Debian 12 ships 8.2, so on
# those the distro packages are not enough and a PHP repository is added rather
# than failing at the version check after a long install.
PHP_SERIES=""
needs_php_repo() {
    have="$(php -r 'echo PHP_MAJOR_VERSION . PHP_MINOR_VERSION;' 2>/dev/null || echo 0)"
    [ "$have" -lt 83 ] 2>/dev/null
}

case "$PM" in
    apt)
        export DEBIAN_FRONTEND=noninteractive
        apt-get update -qq
        apt-get install -y -qq --no-install-recommends \
            git curl unzip ca-certificates nginx openssh-client certbot

        if needs_php_repo; then
            step "Adding a PHP 8.3 repository (this distribution ships an older PHP)"
            apt-get install -y -qq --no-install-recommends \
                software-properties-common gnupg lsb-release

            if grep -qi ubuntu /etc/os-release; then
                add-apt-repository -y ppa:ondrej/php >/dev/null
            else
                curl -fsSL https://packages.sury.org/php/apt.gpg \
                    -o /usr/share/keyrings/sury-php.gpg
                printf 'deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ %s main\n' \
                    "$(lsb_release -sc)" > /etc/apt/sources.list.d/sury-php.list
            fi
            apt-get update -qq
            PHP_SERIES=8.3
        fi

        if [ -n "$PHP_SERIES" ]; then
            apt-get install -y -qq --no-install-recommends \
                "php${PHP_SERIES}-cli" "php${PHP_SERIES}-mysql" "php${PHP_SERIES}-curl" \
                "php${PHP_SERIES}-mbstring" "php${PHP_SERIES}-xml" "php${PHP_SERIES}-zip" \
                "php${PHP_SERIES}-fpm"
        else
            apt-get install -y -qq --no-install-recommends \
                php-cli php-mysql php-curl php-mbstring php-xml php-zip php-fpm
        fi

        apt-get install -y -qq --no-install-recommends mariadb-client
        DB_SERVICE=mariadb
        DB_SERVER_PKG=mariadb-server
        ;;
    dnf)
        dnf install -y -q git curl unzip nginx openssh-clients certbot \
            php-cli php-mysqlnd php-curl php-mbstring php-xml php-fpm mariadb
        DB_SERVICE=mariadb
        DB_SERVER_PKG=mariadb-server
        ;;
    apk)
        apk add --no-cache git curl unzip nginx openssh-client certbot \
            php83-cli php83-pdo_mysql php83-curl php83-mbstring php83-openssl \
            php83-session php83-tokenizer php83-fileinfo php83-phar php83-dom \
            php83-fpm mariadb-client
        DB_SERVICE=mariadb
        DB_SERVER_PKG=mariadb
        ;;
esac

if [ "$MODE" = shared ]; then
    step "Installing a container runtime (shared mode)"
    case "$PM" in
        apt) apt-get install -y -qq --no-install-recommends docker.io ;;
        dnf) dnf install -y -q podman ;;
        apk) apk add --no-cache docker ;;
    esac

    if command -v systemctl >/dev/null 2>&1; then
        systemctl enable --now docker >/dev/null 2>&1 || true
    fi
fi

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

if [ -n "$TOKEN" ] && [ "$REPO_URL" = "$DEFAULT_REPO_URL" ]; then
    red "note: a token was supplied but --repo was not, so this installs the public
      upstream repository (${DEFAULT_REPO_URL}), not a fork. If you meant to
      install a fork, re-run with --repo <its clone URL>."
fi

green "installing from ${REPO_URL} (${BRANCH})"

# A token is only needed for a private repository. It is passed to git through
# an askpass helper rather than embedded in the remote URL, so it never lands
# in .git/config, the reflog, or a later `git remote -v`.
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
  If that repository is private, the installer needs a GitHub token with read
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

# Where tenants live. Each site gets a subdirectory owned by root, with
# tenant-writable directories beneath it — that is what makes the SSH chroot
# safe, since a chroot root must not be writable by the user inside it.
mkdir -p /srv/sites
chmod 755 /srv/sites

step "Installing the tenant shell and SSH jail"

# The restricted shell every tenant logs in with: an allowlist, no shell
# metacharacters. This is the boundary between a tenant's SSH session and the
# rest of the node, so it is installed root-owned and not writable by anyone else.
install -o root -g root -m 0755 "${INSTALL_DIR}/agent/bin/aipanel-shell" /usr/local/bin/aipanel-shell

# Chroot every member of the 'sites' group into its own home.
if [ -d /etc/ssh/sshd_config.d ]; then
    install -o root -g root -m 0644 \
        "${INSTALL_DIR}/agent/templates/sshd-sites.conf.tpl" \
        /etc/ssh/sshd_config.d/aipanel-sites.conf

    # Never reload sshd with a config it rejects: that can lock everyone out.
    if sshd -t 2>/dev/null; then
        systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || \
            rc-service sshd reload >/dev/null 2>&1 || true
        green "sshd jail installed for group 'sites'"
    else
        rm -f /etc/ssh/sshd_config.d/aipanel-sites.conf
        red "sshd rejected the aipanel jail config; removed it and left sshd untouched.
      Tenant SSH will not be jailed until this is resolved — check: sshd -t"
    fi
else
    red "note: /etc/ssh/sshd_config.d does not exist on this system, so the tenant
      SSH jail was not installed. Add the contents of
      ${INSTALL_DIR}/agent/templates/sshd-sites.conf.tpl to /etc/ssh/sshd_config
      by hand, then reload sshd."
fi

cd "$INSTALL_DIR"
composer install --no-interaction --no-dev --prefer-dist --no-progress --quiet

# ----------------------------------------------------------- database

step "Preparing the database"

# aipanel's job queue claims work with `FOR UPDATE SKIP LOCKED`, which needs
# MySQL 8.0+ or MariaDB 10.6+. An older server cannot run the panel at all, so
# the version is checked rather than discovered later as a SQL error.
DB_NAME=aipanel
DB_USER=aipanel
DB_PASS="$("$PHP_BIN" -r 'echo bin2hex(random_bytes(16));')"
DB_CONTAINER=""

mysql_root() {
    if [ -n "$DB_ROOT_PASS" ]; then
        mysql -h "$DB_HOST" -P "$DB_PORT" -uroot -p"$DB_ROOT_PASS" "$@"
    else
        mysql -uroot "$@"
    fi
}

# Prints the server version, or nothing if it cannot be reached.
server_version() {
    mysql_root -N -B -e 'SELECT VERSION()' 2>/dev/null | head -1
}

supports_skip_locked() {
    v="$1"
    case "$v" in
        *MariaDB*)
            major="$(printf '%s' "$v" | cut -d. -f1)"
            minor="$(printf '%s' "$v" | cut -d. -f2)"
            [ "$major" -gt 10 ] 2>/dev/null && return 0
            [ "$major" -eq 10 ] 2>/dev/null && [ "$minor" -ge 6 ] 2>/dev/null
            ;;
        *)
            major="$(printf '%s' "$v" | cut -d. -f1)"
            [ "$major" -ge 8 ] 2>/dev/null
            ;;
    esac
}

start_db_container() {
    runtime=docker
    command -v docker >/dev/null 2>&1 || runtime=podman
    command -v "$runtime" >/dev/null 2>&1 || die "no container runtime available for --db container.
  Install docker (or podman), or point the panel at a MySQL 8 / MariaDB 10.6
  server with:  AIPANEL_DB_HOST=... AIPANEL_DB_PORT=... AIPANEL_DB_ROOT_PASS=..."

    DB_CONTAINER=aipanel-db
    DB_HOST=127.0.0.1
    DB_PORT="${AIPANEL_DB_PORT:-3307}"
    DB_ROOT_PASS="$("$PHP_BIN" -r 'echo bin2hex(random_bytes(16));')"

    if "$runtime" inspect "$DB_CONTAINER" >/dev/null 2>&1; then
        die "a container named ${DB_CONTAINER} already exists.
  Remove it (${runtime} rm -f ${DB_CONTAINER}) or set AIPANEL_DB_PORT and re-run."
    fi

    green "starting a dedicated MySQL 8 on ${DB_HOST}:${DB_PORT} (your existing server is untouched)"
    "$runtime" run --detach --restart unless-stopped --name "$DB_CONTAINER" \
        -e MYSQL_ROOT_PASSWORD="$DB_ROOT_PASS" \
        -e MYSQL_DATABASE="$DB_NAME" \
        -p "127.0.0.1:${DB_PORT}:3306" \
        -v aipanel-db-data:/var/lib/mysql \
        mysql:8.0 >/dev/null

    printf '  waiting for it to accept connections'
    i=0
    while [ "$i" -lt 90 ]; do
        if "$runtime" exec "$DB_CONTAINER" \
            mysqladmin ping -h localhost -uroot -p"$DB_ROOT_PASS" >/dev/null 2>&1; then
            printf '\n'
            return 0
        fi
        printf '.'
        i=$((i + 1))
        sleep 1
    done
    printf '\n'
    die "the database container did not become ready; check: ${runtime} logs ${DB_CONTAINER}"
}

if [ "$DB_STRATEGY" = container ]; then
    start_db_container
elif [ "$DB_SERVER_PRESENT" = 1 ]; then
    # Something is already here. Use it if it is new enough; never try to
    # install over it, which is what aborts the package run.
    if command -v systemctl >/dev/null 2>&1; then
        systemctl start "$DB_SERVICE" >/dev/null 2>&1 \
            || systemctl start mysql >/dev/null 2>&1 \
            || systemctl start mysqld >/dev/null 2>&1 || true
    fi

    VERSION="$(server_version)"

    if [ -z "$VERSION" ]; then
        die "a MySQL/MariaDB server is installed here but this script cannot log in as root.
  Give it credentials and re-run:
    AIPANEL_DB_ROOT_PASS='your-root-password' sh install.sh --port ${PORT}${MODE:+ --${MODE}}
  Or leave it alone and give the panel its own database:
    sh install.sh --port ${PORT} --db container"
    elif supports_skip_locked "$VERSION"; then
        green "using the MySQL/MariaDB already on this host (${VERSION})"
    else
        red "the database on this host is ${VERSION}; aipanel needs MySQL 8.0+ or MariaDB 10.6+
      (its job queue claims work with FOR UPDATE SKIP LOCKED)."
        printf '  Giving the panel its own database instead, and leaving yours untouched.\n'
        DB_STRATEGY=container
        start_db_container
    fi
else
    # Nothing here: install a server, which is safe because nothing is running.
    case "$PM" in
        apt) apt-get install -y -qq --no-install-recommends "$DB_SERVER_PKG" ;;
        dnf) dnf install -y -q "$DB_SERVER_PKG" ;;
        apk) apk add --no-cache "$DB_SERVER_PKG" ;;
    esac

    if command -v systemctl >/dev/null 2>&1; then
        systemctl enable --now "$DB_SERVICE" >/dev/null 2>&1 \
            || systemctl enable --now mysqld >/dev/null 2>&1 || true
    else
        rc-update add mariadb default >/dev/null 2>&1 || true
        /etc/init.d/mariadb setup >/dev/null 2>&1 || true
        rc-service mariadb start >/dev/null 2>&1 || true
    fi

    VERSION="$(server_version)"
    if [ -n "$VERSION" ] && ! supports_skip_locked "$VERSION"; then
        die "the installed database is ${VERSION}, but aipanel needs MySQL 8.0+ or MariaDB 10.6+.
  Re-run with --db container to give the panel its own."
    fi
    green "installed and started ${DB_SERVICE} (${VERSION:-version unknown})"
fi

mysql_root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
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
set_env DB_DSN "mysql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_NAME};charset=utf8mb4"
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

    # The node agent. Runs as root because provisioning a tenant means creating
    # a user, writing nginx and FPM config and reloading services. It listens on
    # loopback only: nothing outside this host can reach it, and the control
    # plane still authenticates every request with the node secret.
    cat > /etc/systemd/system/aipanel-agent.service <<UNIT
[Unit]
Description=aipanel node agent
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=${INSTALL_DIR}
ExecStart=${PHP_BIN} -S 127.0.0.1:${AGENT_PORT} ${INSTALL_DIR}/agent/aipanel-agent.php
Restart=always
RestartSec=2
PrivateTmp=yes

[Install]
WantedBy=multi-user.target
UNIT

    systemctl daemon-reload
    systemctl enable --now aipanel.service aipanel-worker.service \
        aipanel-scheduler.service aipanel-agent.service >/dev/null 2>&1
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

    cat > /etc/init.d/aipanel-agent <<RC
#!/sbin/openrc-run
command="${PHP_BIN}"
command_args="-S 127.0.0.1:${AGENT_PORT} ${INSTALL_DIR}/agent/aipanel-agent.php"
command_background=true
pidfile="/run/aipanel-agent.pid"
RC
    chmod +x /etc/init.d/aipanel-agent
    rc-update add aipanel-agent default >/dev/null 2>&1 || true
    rc-service aipanel-agent start >/dev/null 2>&1 || true
fi

step "Registering this host as a managed node"

# Single-server install: this box is both the control plane and the one node it
# manages. Without this a panel has nowhere to put a website.
"${PHP_BIN}" "${INSTALL_DIR}/bin/console.php" node:bootstrap-local "${AGENT_PORT}"

if [ "$MODE" = shared ]; then
    step "Configuring shared hosting"
    "${PHP_BIN}" "${INSTALL_DIR}/bin/console.php" mode:shared
else
    "${PHP_BIN}" "${INSTALL_DIR}/bin/console.php" mode:single
fi

# The agent config and deploy key are written by the command above as root.
chmod 600 /etc/aipanel/agent.json /etc/aipanel/deploy_key 2>/dev/null || true

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
  Installed. Two steps left, both in GitHub.
  ---------------------------------------------------------------
  1) OAuth app — so you can sign in.
     https://github.com/settings/developers

       Homepage URL:               ${APP_URL}
       Authorization callback URL: ${APP_URL}/auth/github/callback

     Put the client id and secret in ${INSTALL_DIR}/.env
     (GITHUB_OAUTH_CLIENT_ID / GITHUB_OAUTH_CLIENT_SECRET), then:
       systemctl restart aipanel

     Now open ${APP_URL} and sign in.
     THE FIRST ACCOUNT TO SIGN IN BECOMES THE ADMINISTRATOR — do it before
     this address is reachable by anyone else.

  2) GitHub App — so the panel can read your repos, register deploy keys,
     receive push webhooks and merge sandbox into main.
     Settings -> Developer settings -> GitHub Apps

       Webhook URL:  ${APP_URL}/webhooks/github
       Permissions:  Contents read+write, Metadata read, Pull requests read+write
       Events:       Push

     Save its private key to /etc/aipanel/github-app.pem, set GITHUB_APP_ID and
     GITHUB_APP_PRIVATE_KEY_PATH in .env, install the App on your account, then:
       cd ${INSTALL_DIR} && php bin/console.php github:install <installation_id> <account_id> <login>

  Check on things with:
    systemctl status aipanel aipanel-worker aipanel-scheduler aipanel-agent
    curl -s http://127.0.0.1:${PORT}/health
    journalctl -u aipanel -n 50
"
