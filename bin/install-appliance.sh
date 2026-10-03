#!/bin/sh
#
# Install aipanel as an appliance: everything in its own containers.
#
#   curl -fsSL .../install-appliance.sh | sudo sh
#   curl -fsSL .../install-appliance.sh | sudo sh -s -- --http-port 8080 --https-port 8443
#
# What this puts on the host:
#
#   a container runtime          (the only package installed)
#   /var/lib/aipanel             (every file aipanel owns)
#   aipanel.service              (one unit, which runs the stack)
#
# What it does NOT touch: /etc/nginx, /etc/php, /www, the host's MySQL, the
# host's PHP, or any host user. A server already running aaPanel, cPanel or
# Plesk keeps working — the only resource shared with it is the ports below, so
# if something else owns 80/443, give aipanel different ones and point your
# existing web server at it.

set -eu

ROOT=/var/lib/aipanel
APP_DIR="${AIPANEL_APP_DIR:-/var/lib/aipanel/app}"
REPO_URL="${AIPANEL_REPO:-https://github.com/i-m-satya/aipanel.git}"
BRANCH="${AIPANEL_BRANCH:-main}"
HTTP_PORT="${AIPANEL_HTTP_PORT:-80}"
HTTPS_PORT="${AIPANEL_HTTPS_PORT:-443}"
PANEL_PORT="${AIPANEL_PORT:-2087}"

red()   { printf '\033[31m%s\033[0m\n' "$*" >&2; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
bold()  { printf '\033[1m%s\033[0m\n' "$*"; }
step()  { printf '\n\033[1m==>\033[0m %s\n' "$*"; }
die()   { red "error: $*"; exit 1; }

while [ $# -gt 0 ]; do
    case "$1" in
        --http-port)  HTTP_PORT="${2:?}"; shift 2 ;;
        --https-port) HTTPS_PORT="${2:?}"; shift 2 ;;
        --port)       PANEL_PORT="${2:?}"; shift 2 ;;
        --repo)       REPO_URL="${2:?}"; shift 2 ;;
        --branch)     BRANCH="${2:?}"; shift 2 ;;
        --help|-h)    sed -n '3,22p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) die "unknown option: $1 (try --help)" ;;
    esac
done

[ "$(id -u)" -eq 0 ] || die "run as root"

# Refuse a port something else already holds, rather than leaving the edge in a
# restart loop that looks like a bug in aipanel.
for port in "$HTTP_PORT" "$HTTPS_PORT" "$PANEL_PORT"; do
    if command -v ss >/dev/null 2>&1 && ss -lnt 2>/dev/null | grep -q ":${port} "; then
        die "port ${port} is already in use — most likely by the web server already on this host.
  Give aipanel its own ports and proxy to them from that web server, e.g.
    --http-port 8080 --https-port 8443"
    fi
done

# ------------------------------------------------------------ runtime

step "Installing a container runtime"
if command -v docker >/dev/null 2>&1; then
    green "docker is already installed"
elif command -v podman >/dev/null 2>&1; then
    green "podman is already installed"
elif command -v apt-get >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq --no-install-recommends docker.io docker-compose-v2 git
elif command -v dnf >/dev/null 2>&1; then
    dnf install -y -q podman podman-compose git
elif command -v apk >/dev/null 2>&1; then
    apk add --no-cache docker docker-cli-compose git
else
    die "no supported package manager found (apt, dnf or apk)"
fi

RUNTIME=docker
command -v docker >/dev/null 2>&1 || RUNTIME=podman

if command -v systemctl >/dev/null 2>&1; then
    systemctl enable --now "$RUNTIME" >/dev/null 2>&1 || true
fi

COMPOSE="$RUNTIME compose"
$COMPOSE version >/dev/null 2>&1 || COMPOSE="${RUNTIME}-compose"
$COMPOSE version >/dev/null 2>&1 || die "no compose command found; install docker-compose-v2 or podman-compose"

# -------------------------------------------------------------- layout

step "Creating ${ROOT}"
for dir in "$ROOT" "$ROOT/sites" "$ROOT/edge/conf.d" "$ROOT/acme" "$ROOT/certs" \
           "$ROOT/certs/default" "$ROOT/secrets" "$ROOT/app"; do
    mkdir -p "$dir"
done
chmod 0750 "$ROOT/secrets"

step "Fetching aipanel into ${APP_DIR}"
if [ -d "${APP_DIR}/.git" ]; then
    git -C "$APP_DIR" fetch --quiet origin "$BRANCH"
    git -C "$APP_DIR" reset --hard --quiet "origin/${BRANCH}"
else
    git clone --quiet --branch "$BRANCH" --depth 1 "$REPO_URL" "$APP_DIR"
fi

cp "${APP_DIR}/docker/edge/nginx.conf" "${ROOT}/edge/nginx.conf"
cp "${APP_DIR}/docker/edge/aipanel-proxy.conf" "${ROOT}/edge/conf.d/../aipanel-proxy.conf" 2>/dev/null \
    || cp "${APP_DIR}/docker/edge/aipanel-proxy.conf" "${ROOT}/edge/aipanel-proxy.conf"

# The edge's default server needs a certificate before any tenant has one, so a
# TLS handshake for an unknown hostname fails cleanly rather than borrowing
# someone's certificate.
if [ ! -f "${ROOT}/certs/default/fullchain.pem" ]; then
    step "Generating the edge's default certificate"
    openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
        -subj "/CN=aipanel-default" \
        -keyout "${ROOT}/certs/default/privkey.pem" \
        -out "${ROOT}/certs/default/fullchain.pem" 2>/dev/null
    chmod 0600 "${ROOT}/certs/default/privkey.pem"
fi

# ------------------------------------------------------------- secrets

step "Generating secrets"
random() { head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n'; }

[ -f "${ROOT}/secrets/db_root" ] || random > "${ROOT}/secrets/db_root"
[ -f "${ROOT}/secrets/db_pass" ] || random > "${ROOT}/secrets/db_pass"
[ -f "${ROOT}/deploy_key" ] || ssh-keygen -t ed25519 -N "" -q \
    -C "aipanel-node-$(hostname)" -f "${ROOT}/deploy_key"
chmod 0600 "${ROOT}/secrets/"* "${ROOT}/deploy_key"

APP_KEY="$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
NODE_SECRET="$(random)"
DB_PASS="$(cat "${ROOT}/secrets/db_pass")"
HOST_ADDR="$(curl -fsS --max-time 5 https://api.ipify.org 2>/dev/null \
    || hostname -I 2>/dev/null | awk '{print $1}' || echo localhost)"
APP_URL="http://${HOST_ADDR}:${PANEL_PORT}"

if [ ! -f "${ROOT}/.env" ]; then
    cat > "${ROOT}/.env" <<ENVFILE
APP_ENV=production
APP_DEBUG=false
APP_URL=${APP_URL}
APP_KEY=${APP_KEY}

DB_DSN=mysql:host=db;port=3306;dbname=aipanel;charset=utf8mb4
DB_USER=aipanel
DB_PASS=${DB_PASS}

REDIS_DSN=

AIPANEL_HTTP_PORT=${HTTP_PORT}
AIPANEL_HTTPS_PORT=${HTTPS_PORT}
AIPANEL_PANEL_PORT=${PANEL_PORT}

# Sign-in (GitHub only) — fill these in, then restart the stack.
GITHUB_OAUTH_CLIENT_ID=
GITHUB_OAUTH_CLIENT_SECRET=

# The GitHub App the panel acts as.
GITHUB_APP_ID=
GITHUB_APP_PRIVATE_KEY_PATH=/var/lib/aipanel/github-app.pem
GITHUB_APP_WEBHOOK_SECRET=
ENVFILE
    chmod 0600 "${ROOT}/.env"
fi

# The agent's own config: appliance mode, and where everything lives.
cat > "${ROOT}/agent.json" <<AGENTFILE
{
    "secret": "${NODE_SECRET}",
    "role": "web",
    "isolation": "appliance",
    "appliance_root": "${ROOT}",
    "edge_container": "aipanel-edge-1",
    "container_runtime": "${RUNTIME}",
    "deploy_key": "${ROOT}/deploy_key"
}
AGENTFILE
chmod 0600 "${ROOT}/agent.json"

# -------------------------------------------------------------- images

step "Building images (first run takes a few minutes)"
$RUNTIME build -q -f "${APP_DIR}/docker/php/Dockerfile" -t aipanel/panel:latest "$APP_DIR" >/dev/null
for php in 8.3 8.4; do
    $RUNTIME build -q -f "${APP_DIR}/docker/tenant/Dockerfile" \
        --build-arg "PHP=${php}" -t "aipanel/php:${php}" "${APP_DIR}/docker/tenant" >/dev/null
done
green "built aipanel/panel:latest, aipanel/php:8.3, aipanel/php:8.4"

# --------------------------------------------------------------- stack

step "Starting the stack"
cp "${APP_DIR}/docker/stack/docker-compose.yml" "${ROOT}/docker-compose.yml"

if command -v systemctl >/dev/null 2>&1; then
    cat > /etc/systemd/system/aipanel.service <<UNIT
[Unit]
Description=aipanel (appliance)
Requires=${RUNTIME}.service
After=${RUNTIME}.service

[Service]
Type=oneshot
RemainAfterExit=yes
WorkingDirectory=${ROOT}
EnvironmentFile=${ROOT}/.env
ExecStart=/bin/sh -c '${COMPOSE} -f ${ROOT}/docker-compose.yml up -d'
ExecStop=/bin/sh -c '${COMPOSE} -f ${ROOT}/docker-compose.yml down'

[Install]
WantedBy=multi-user.target
UNIT
    systemctl daemon-reload
    systemctl enable --now aipanel.service >/dev/null 2>&1 || true
else
    (cd "$ROOT" && $COMPOSE up -d)
fi

step "Applying migrations"
i=0
while [ "$i" -lt 60 ]; do
    if $RUNTIME exec aipanel-panel-1 php bin/console.php migrate >/dev/null 2>&1; then
        break
    fi
    i=$((i + 1))
    sleep 2
done
$RUNTIME exec aipanel-panel-1 php bin/console.php migrate || \
    red "migrations did not run yet; try: ${RUNTIME} exec aipanel-panel-1 php bin/console.php migrate"

$RUNTIME exec aipanel-panel-1 php bin/console.php mode:shared >/dev/null 2>&1 || true

bold "
  aipanel is installed as an appliance.
  ---------------------------------------------------------------
  On this host it owns only:  ${ROOT}, one systemd unit, and the
  container runtime. Your existing panel and sites are untouched.

  Panel:  ${APP_URL}       (port ${PANEL_PORT})
  Sites:  ports ${HTTP_PORT} (http) and ${HTTPS_PORT} (https)

  Next, in GitHub:
    1) An OAuth app so you can sign in —
       callback ${APP_URL}/auth/github/callback
    2) A GitHub App so the panel can read repos and receive pushes —
       webhook ${APP_URL}/webhooks/github

  Put both sets of credentials in ${ROOT}/.env, then:
    systemctl restart aipanel

  Then open ${APP_URL} and sign in. THE FIRST ACCOUNT BECOMES THE ADMIN.

  Check on things with:
    ${COMPOSE} -f ${ROOT}/docker-compose.yml ps
    ${COMPOSE} -f ${ROOT}/docker-compose.yml logs -f panel
"
