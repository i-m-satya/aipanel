# aipanel

A hosting control panel that gets out of the way. Install it, sign in with
GitHub, add a website — and from then on your repository runs the server.

> **One website = one repository, two environments.** Push to `sandbox` and it
> appears at `sandbox.yoursite.com`. Press *Make it live* and it merges to
> `main` and goes to `yoursite.com`. HTTPS is issued automatically for both.

aipanel does not write your code and runs no AI on your server. You work in
your repository — with Claude Code, or any editor — and the panel's job starts
at the push.

Full design: [ARCHITECTURE.md](ARCHITECTURE.md).

---

## How it works

```
  you + Claude Code ──▶ git push origin sandbox
                                │
                                ▼
                        https://sandbox.yoursite.com      ← rebuilt automatically
                                │
                        "Make it live"  (one button)
                                │
                                ▼
                        merge sandbox → main
                                │
                                ▼
                        https://yoursite.com              ← rebuilt automatically
```

Every deploy builds an immutable release from an exact commit, health-checks
it, then atomically repoints `current/`. A failed build never moves the
symlink, so the previous release keeps serving; rollback moves it back.

Let's Encrypt certificates are issued for every domain — production and
sandbox — with no button to press, and renewed on their own.

### What a tenant gets

| | |
|---|---|
| Linux user | `site_8f3ab1`, its own group, nothing shared |
| Home | `/srv/sites/site_8f3ab1` — `repo.git`, `releases/`, `current ->`, `shared/` |
| SSH | chrooted to that home, restricted shell, key-based only |
| PHP | its own FPM pool running as that user, `open_basedir`'d, no `exec` family |
| Database | one schema, one user, grants on that schema only |
| Limits | systemd slice: CPU quota, memory cap, task cap |

A tenant with full control of their own PHP process still sees exactly one
website.

### The whole panel

1. **Install** — one command.
2. **Log in with GitHub** — the first account becomes the admin.
3. **Add a website** — domain + repository.

That is the entire interface. Everything after it is automatic: both
environments are provisioned, both get certificates, and every push deploys.

---

## Install

One command on any Linux server (Debian/Ubuntu, RHEL/Rocky/Alma/Fedora, or Alpine):

```bash
curl -fsSL https://raw.githubusercontent.com/i-m-satya/aipanel/main/install.sh | sudo sh
```

Installing from a **fork** needs the fork's clone URL too — downloading the
script from a fork does not by itself install that fork, because the installer
clones `AIPANEL_REPO` rather than wherever it was downloaded from. A private
fork also needs a token with read access to it:

```bash
curl -fsSL -H "Authorization: Bearer $GH_TOKEN" \
  https://raw.githubusercontent.com/<owner>/<repo>/main/install.sh \
  | sudo AIPANEL_TOKEN="$GH_TOKEN" \
         AIPANEL_REPO="https://github.com/<owner>/<repo>.git" sh
```

Pick the port it listens on — it prompts, or pass it non-interactively:

```bash
curl -fsSL https://raw.githubusercontent.com/i-m-satya/aipanel/main/install.sh \
  | sudo sh -s -- --port 2087 --yes
```

The installer puts PHP, MariaDB and the panel in place, generates the app key
and database credentials, applies migrations, installs systemd units for the
web, worker, code-worker and scheduler processes, opens the port in `ufw` or
`firewalld` if either is active, and then prints what to do next.

### Then: connect GitHub and claim the panel

Sign-in is GitHub-only, so the panel needs an OAuth app before anyone can log
in. Create one at <https://github.com/settings/developers> with the callback
URL the installer printed:

```
Homepage URL:               http://<server>:<port>
Authorization callback URL: http://<server>:<port>/auth/github/callback
```

Put the credentials in `/opt/aipanel/.env` and restart:

```bash
GITHUB_OAUTH_CLIENT_ID=...
GITHUB_OAUTH_CLIENT_SECRET=...

systemctl restart aipanel
```

Then open the panel and sign in with GitHub. **The first GitHub account to
sign in becomes the administrator.** After that, signing in is not enough —
a GitHub account must have been invited by the admin, so an installation
reachable on the internet is not open to anyone with a GitHub account:

```bash
php /opt/aipanel/bin/console.php user:invite some-github-login user
```

Put a TLS terminator in front before exposing it publicly; the installer
serves plain HTTP on the port you chose.

### Add a website

Give a domain and a GitHub repository. aipanel provisions **two** isolated
tenants — `sandbox.<domain>` tracking the `sandbox` branch and `<domain>`
tracking `main` — each with its own jailed Linux user, chrooted SSH, PHP-FPM
pool, vhost and release layout, and registers the node's read-only deploy key
on the repository.

From then on nothing needs doing in the panel:

| You push to | What updates | HTTPS |
|---|---|---|
| `sandbox` | `https://sandbox.<domain>` only | issued automatically |
| `main` | `https://<domain>` | issued automatically |

*Make it live* merges `sandbox` into `main`, and production deploys through the
same webhook as any other push — there is no second, privileged delivery path.

Certificates need the domain's DNS pointing at the node. Until it does, the
issuance job retries with backoff and the site serves over HTTP.

---

## Development

```bash
cp .env.example .env
php bin/console.php key:generate        # paste APP_KEY into .env
docker compose up -d
docker compose exec app php bin/console.php migrate
open http://localhost:8080
```

### Registering a node

```bash
php bin/console.php node:add web-01 https://web-01.internal:9443 web
# prints the node secret once -> /etc/aipanel/agent.json on that host

php bin/console.php node:deploy-key 1
# prints the node's read-only deploy key
```

On the node, install the agent (`agent/`), its systemd unit, the sshd drop-in
(`agent/templates/sshd-sites.conf.tpl`) and the restricted shell
(`agent/bin/aipanel-shell` → `/usr/local/bin/aipanel-shell`).

### Connecting GitHub

```bash
php bin/console.php github:install <installation_id> <account_id> <org-login>
# prints the webhook secret to set on the GitHub App
```

---

## Running it

| Process | Command | Scale by |
|---|---|---|
| Control plane | `php -S 0.0.0.0:8080 -t public` (nginx + FPM in production) | replicas behind a LB |
| Ops worker | `php bin/worker.php` | replicas — jobs are leased, never double-run |
| Scheduler | `php bin/scheduler.php` | replicas — one wins the leader lease |

```bash
composer test          # phpunit
php bin/console.php queue:status
```

---

## Safety model, in one paragraph

Nothing reaches a server except a named task from a fixed catalogue
(`src/Tasks/Catalogue.php`), schema-validated and role-checked before it can be
queued. On the node, the agent executes a fixed handler map with argv-array
exec only — there is no code path that builds a shell string from a parameter,
and an unknown task name is a 400 rather than an eval. Requests to the agent
are HMAC-signed with a per-node secret over `timestamp.nonce.body`, with a
replay cache. Tenants are isolated from each other by user, chroot,
`open_basedir`, database grants and a systemd slice, not by any one of them.
The panel holds no model API key, because it runs no model.

---

## Layout

```
public/index.php   front controller
src/Tasks/         the task catalogue — the contract everything shares
src/Jobs/          leased queue + runner
src/Git/           GitHub App auth, webhook verification
src/Deploy/        release planning, promotion, drift detection
src/Auth/          GitHub OAuth (the only login)
agent/             node agent, handler map, config templates, restricted shell
db/migrations/     schema
```

MIT licensed.
