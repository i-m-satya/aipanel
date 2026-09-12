# aipanel — Architecture

aipanel is an AI-operated hosting control panel for classic LAMP-style servers.
It has the cPanel shape — many websites on a fleet of servers — but one
opinionated model at its centre:

> **One website = one tenant.** Each website gets its own jailed Linux user,
> its own GitHub repository, and its own SSH access. Nobody hand-edits code on
> the server. The AI is the author: it changes the repo, and whatever lands on
> `main` is what runs live.

---

## 1. The tenant model

```
website  shop.example.com
   ├── linux user        site_8f3ab1            (jailed, no sight of other sites)
   ├── home              /srv/sites/site_8f3ab1
   │      ├── repo.git   bare mirror of the GitHub repo
   │      ├── releases/  20260912T101500Z/ … (immutable, timestamped)
   │      ├── current -> releases/20260912T101500Z   (atomic symlink)
   │      └── shared/    .env, uploads/, storage/  (survives deploys)
   ├── github repo       acme-org/shop-example-com
   ├── php-fpm pool      runs as site_8f3ab1, open_basedir'd to its home
   ├── mysql db + user   scoped grants, that database only
   └── ssh access        restricted shell, chrooted to its own home
```

Everything a tenant can touch is inside its own home. Everything about that
website — user, pool, vhost, DB, repo, deploy history — is derived from one
site id, which is what makes create/destroy/move-to-another-node a single
reversible operation instead of a checklist.

### 1.1 Isolation (how "SSH but only your own site" is enforced)

SSH is the part people get wrong, so it is layered — no single control is load
bearing:

| Layer | Control |
|---|---|
| Identity | One Linux user + one primary group per site. No shared group, ever. |
| Filesystem | `ChrootDirectory /srv/sites/%u` in an sshd `Match Group sites` block; home owned `root:root`, tenant-writable subdirs beneath it. |
| Shell | Restricted shell with a fixed command allowlist (`git`, `composer`, `php artisan`, `ls`, `tail`, …); `internal-sftp` for file transfer. |
| Web runtime | Per-site PHP-FPM pool running as the site user, `open_basedir` to its home, `disable_functions` for `proc_open`/`exec`/`shell_exec`. |
| Database | One DB + one MySQL user with grants on that schema only. No `FILE`, no `PROCESS`. |
| Resources | systemd slice per site: `CPUQuota`, `MemoryMax`, `TasksMax`, plus disk quota — one tenant cannot starve the node. |
| Kernel | `ProtectSystem=strict`, `PrivateTmp`, `NoNewPrivileges` on the pool unit; `/proc` mounted `hidepid=2` so tenants cannot see each other's processes. |
| Network | Egress policy per site (default: DNS, HTTP(S), the node's own DB socket). No lateral access to other sites' sockets. |

A tenant who fully owns their own PHP process still sees exactly one website.

---

## 2. Planes

```
                       ┌──────────────────────────────────────────┐
   browser / API ─────▶│  CONTROL PLANE (stateless, N replicas)   │
   GitHub webhooks ───▶│  nginx → PHP-FPM → public/index.php      │
                       └───┬───────────────┬──────────────────┬───┘
                           │               │                  │
                    ┌──────▼─────┐  ┌──────▼──────┐   ┌───────▼────────┐
                    │  MySQL     │  │   Redis     │   │ Anthropic API  │
                    │ primary +  │  │ sessions,   │   │ ops planner +  │
                    │ replicas   │  │ cache, rate │   │ code agent     │
                    └──────┬─────┘  └─────────────┘   └───────┬────────┘
                           │ jobs (leased)                    │
                    ┌──────▼──────────────────────────┐  ┌─────▼────────┐
                    │  WORKERS (N replicas)           │  │  GitHub API  │
                    │  ops jobs + AI code jobs        │──│  (App auth)  │
                    └──────┬──────────────────────────┘  └──────────────┘
                           │ HTTPS + HMAC-SHA256, per-node key
        ┌──────────────────┼──────────────────┬──────────────────┐
        ▼                  ▼                  ▼                  ▼
  ┌───────────┐      ┌───────────┐      ┌───────────┐      ┌───────────┐
  │  NODE 1   │      │  NODE 2   │      │  DB NODE  │      │ DNS NODE  │
  │ web role  │      │ web role  │      │ mysql role│      │ bind role │
  │ agent.php │      │ agent.php │      │ agent.php │      │ agent.php │
  │ site_a…n  │      │ site_a…n  │      │           │      │           │
  └───────────┘      └───────────┘      └───────────┘      └───────────┘
```

### 2.1 Control plane (`src/`, `public/`)
PHP 8.3, PSR-4, no framework — front controller, router, thin controllers,
repositories over PDO. A control panel is installed on other people's servers
for years at a time; a small dependency surface is a security feature.

Stateless: sessions in Redis, no local uploads, no local cron (leader-elected
scheduler, §6).

### 2.2 Job plane (`src/Jobs/`, `bin/worker.php`)
DB-backed queue with **lease semantics** — a job that provisions a tenant or
deploys a release must never be silently lost:

- claim = `SELECT … FOR UPDATE SKIP LOCKED` + a time-bounded lease, so N
  workers share one table with no broker and no double-execution;
- crashed worker → lease expires → job returns to `queued`, which is safe
  because every task is idempotent under its idempotency key;
- exponential backoff on retry; exhausted jobs are buried with the full agent
  transcript in the audit log;
- per-node concurrency cap, so 500 workers cannot stampede one host.

### 2.3 Data plane (`agent/`)
One PHP CLI agent per managed host, started by systemd, reachable only from
control-plane addresses. It verifies `HMAC-SHA256(timestamp.nonce.body)` with a
per-node secret (timestamp window + nonce replay cache), then dispatches
against a **fixed handler map**. An unknown task name is a 400, never an eval.
Every exec goes through `proc_open` with an argv array — there is no code path
that builds a shell string, so tenant input can never become shell syntax.
Handlers return `{ok, changed, stdout, stderr, facts}`; `changed:false` is how
idempotency reports itself.

---

## 3. The code path: GitHub → main → live

No manual code, and no editing on the server. `current/` is a symlink to an
immutable release directory; the tenant's writable state lives in `shared/`.

```
 ① AI change request ──▶ worker clones the repo into an ephemeral sandbox
                         (never on a web node), Claude edits it, worker runs
                         the repo's own checks, commits on a branch
 ② branch pushed ─────▶ GitHub PR (or direct to main for trusted sites)
 ③ main updated ──────▶ GitHub webhook → control plane verifies signature
 ④ deploy job ────────▶ agent on the site's node:
                          git fetch into repo.git (deploy key, read-only)
                          build a new releases/<ts>/ from that exact SHA
                          install deps, link shared/, run migrations
                          health-check the release on a private port
                          atomically repoint current/ → new release
                          reload the site's FPM pool only
 ⑤ failure ───────────▶ symlink never moves; the old release keeps serving,
                         job is buried, audit log holds the transcript
```

Properties this buys:

- **Deploys are atomic and reversible.** Rollback is repointing a symlink to
  the previous release directory — `deploy.rollback` is one task.
- **The live tree is disposable.** Server state is a pure function of a commit
  SHA plus `shared/`, so a site can be rebuilt on another node from GitHub.
- **Drift is detectable.** The agent records the SHA it built; the panel flags
  a site whose `current/` SHA is not the repo's `main`.
- **A bad deploy blast radius is one site.** One pool reload, one symlink.

### 3.1 GitHub integration
aipanel authenticates as a **GitHub App** (installation tokens, not a personal
token): per-installation scope, short-lived tokens, revocable per customer.
Per site it stores repo full name, installation id, and a read-only deploy key
used by the agent — the agent can fetch, never push. Webhook deliveries are
verified with `X-Hub-Signature-256` before anything is queued, and a delivery
for an unknown repo is dropped, not investigated.

---

## 4. AI layers

Two separate AI surfaces, with different privileges. Neither can run commands.

### 4.1 Ops planner (`src/AI/Assistant.php`)
Claude gets one tool per catalogue entry plus read-only inventory tools.
Read-only tools execute immediately so it can look at real state; mutating
tool calls are **captured, not run** — they become a typed `Plan`, validated
against the catalogue (unknown task, bad param, wrong node role, or a tenant
boundary violation ⇒ hard reject), shown to the user as a diff, and only
enqueued on approval. Authorization is re-checked at enqueue time against the
*user's* account, never the model's claim. A prompt-injected model can at worst
propose a plan its user must approve, over resources that user already owns.

### 4.2 Code agent (`src/AI/CodeAgent.php`)
Given a change request for one site, a worker:

1. mints a scoped installation token for that one repository;
2. clones into an **ephemeral sandbox** — a throwaway container on a build
   node, never on a web node and never as the tenant user;
3. lets Claude read and edit only that working tree;
4. runs the repo's own checks (`composer test`, lint) and refuses to open a PR
   when they fail;
5. commits with the requesting user and the session recorded in the trailer, so
   every line in production traces back to a request and a transcript;
6. opens a PR, or pushes to `main` when the site is configured for it.

The deploy is then driven by GitHub's webhook like any human push — the AI has
no deploy path of its own, and no credential that reaches a web node.

---

## 5. Task catalogue

The catalogue (`src/Tasks/Catalogue.php`) is the contract shared by the UI, the
HTTP API and both AI surfaces. Nothing reaches a node unless it is listed here
with typed parameters and a required node role.

| Task | Role | Destructive |
|---|---|---|
| `site.create` (user + jail + pool + vhost + repo wiring) | web | no |
| `site.delete` / `site.suspend` | web | delete, suspend |
| `site.set_php_version` | web | no |
| `ssh.key_add` / `ssh.key_remove` | web | no |
| `deploy.run` / `deploy.rollback` | web | rollback |
| `ssl.issue` (ACME) | web | no |
| `db.create` / `db.drop` | mysql | drop |
| `dns.zone_create` / `dns.record_upsert` | dns | no |
| `mail.mailbox_create` | mail | no |
| `backup.run` / `backup.restore` | any | restore |
| `node.facts` | any | no |

Adding a capability = one catalogue entry + one agent handler; the UI, the API
and the assistant pick it up automatically.

---

## 6. Scaling path

| Stage | Shape |
|---|---|
| 1 host | control plane + agent on the same box |
| 10s of sites | 2+ web replicas behind a LB, Redis sessions, 2+ workers |
| 100s of nodes | read replicas for dashboards, build nodes for AI/deploy sandboxes, per-node concurrency caps |
| 1000s | `account_id`-sharded MySQL, one queue partition per shard, regional worker pools near their nodes |

Mechanics that make those stages non-events:

- **No sticky sessions** — Redis session handler.
- **Leader-elected scheduler** (Redis lock, or the `leader_locks` table) so N
  replicas run cron work — cert renewals, backup windows, fact refresh, drift
  detection — exactly once.
- **Fact cache** — dashboards read cached `node.facts`, never live nodes, so
  panel latency is independent of fleet size.
- **Backpressure** — queue depth per node is a first-class metric; enqueue
  storms get a 429 instead of an unbounded backlog.
- **Site mobility** — because a site is (commit SHA + `shared/` + DB dump),
  rebalancing a node is a provision-and-cut-over, not a migration project.

---

## 7. Security model

- Argon2id passwords, Redis sessions, CSRF on every state-changing form,
  per-account authorization enforced in repositories.
- Per-node secrets encrypted at rest (AES-256-GCM under `APP_KEY`), rotatable.
- The agent runs privileged but only executes its handler map, with argv-array
  exec only.
- GitHub App installation tokens (short-lived, per-repo); agents hold read-only
  deploy keys.
- Full audit log: who (user, ops plan, or code agent), which task, which
  params, what the agent returned, which commit deployed.
- LLM output is data, never code: schema-validated task invocations, and repo
  edits that must survive the repo's own tests and a PR.

---

## 8. Layout

```
public/index.php        front controller
src/Support/            env, config, container, crypto
src/Http/               router, request/response, middleware, controllers
src/Infra/              PDO factory, migrations runner
src/Domain/             users, accounts, nodes, sites, repos + repositories
src/Jobs/               leased queue, job runner
src/Nodes/              AgentClient (HMAC transport)
src/Tasks/              Catalogue (the contract), validator, Plan
src/Git/                GitHub App client, webhook verification
src/Deploy/             release planning, drift detection
src/AI/                 ops planner + code agent
agent/                  node agent, handler map, config templates
bin/                    console (migrate, user:create, node:add), worker
db/migrations/          SQL migrations
views/                  server-rendered templates
```
