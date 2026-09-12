# aipanel

An AI-operated hosting control panel for LAMP-style servers. cPanel's shape,
with one opinionated model at the centre:

> **One website = one tenant.** Each site gets its own jailed Linux user, its
> own GitHub repository and its own SSH access. Nobody hand-edits code on the
> server — the AI is the author, and whatever lands on `main` is what runs live.

Full design: [ARCHITECTURE.md](ARCHITECTURE.md).

---

## How it works

```
you ──▶ "add a contact form to shop.example.com"
          │
          ▼
     code agent clones the site's repo into a throwaway sandbox,
     edits it, runs the repo's own tests, opens a pull request
          │
          ▼
     main updated ──▶ GitHub webhook ──▶ deploy job
          │
          ▼
     node agent builds an immutable release from that exact SHA,
     health-checks it, atomically repoints current/, reloads one pool
```

A failed deploy never moves the symlink, so the previous release keeps serving.
Rollback is repointing it back.

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

---

## Quick start

```bash
cp .env.example .env
php bin/console.php key:generate        # paste APP_KEY into .env
docker compose up -d
docker compose exec app php bin/console.php migrate
open http://localhost:8080
```

Sign-in is GitHub-only, so set `GITHUB_OAUTH_CLIENT_ID` / `..._SECRET` in
`.env` before logging in. aipanel stores no passwords: access is granted and
revoked entirely in GitHub.

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
| Code worker | `php bin/code-worker.php` | replicas, on build nodes only |
| Scheduler | `php bin/scheduler.php` | replicas — one wins the leader lease |

```bash
composer test          # phpunit
php bin/console.php queue:status
```

---

## Safety model, in one paragraph

The AI never runs commands. The ops assistant may only emit task invocations
from a fixed catalogue (`src/Tasks/Catalogue.php`), each schema-validated and
role-checked before it can be queued, and a plan reaches the queue only after a
human approves it — with authorization re-checked against *that user's*
account. The code agent may only read and write inside a throwaway clone of one
repository, with dependencies, `.git` and CI config off limits, and it ships
nothing: it opens a pull request, and GitHub's webhook is what deploys. On the
node, the agent executes a fixed handler map with argv-array exec only — there
is no code path that builds a shell string from a parameter.

---

## Layout

```
public/index.php   front controller
src/Tasks/         the task catalogue — the contract everything shares
src/Jobs/          leased queue + runner
src/AI/            ops planner, code agent, sandbox
src/Git/           GitHub App auth, webhook verification
src/Deploy/        release planning, drift detection
src/Auth/          GitHub OAuth (the only login)
agent/             node agent, handler map, config templates, restricted shell
db/migrations/     schema
```

MIT licensed.
