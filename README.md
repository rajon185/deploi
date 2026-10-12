# Deploi

A self-hosted server management and deployment panel in the spirit of Ploi / Forge, built with Laravel 12.

Deploi creates servers at your cloud provider (or takes over any Ubuntu box you can SSH into), provisions them with Nginx, PHP, a database, Redis and friends, and then manages sites, deployments, SSL, databases, cron jobs, daemons, firewall rules and more, all over SSH.

## Features

| Area | What you can do |
| --- | --- |
| **Servers** | Create at **DigitalOcean**, **Hetzner Cloud**, **Vultr** or **Linode/Akamai** via their APIs, or add a **custom server** (any Ubuntu 22.04/24.04 VPS: AWS, OVH, an existing droplet…) over SSH. Types: web server, database server, worker, load balancer. Live provisioning progress, server stats (load, RAM, disk, uptime), reboot, destroy at provider. |
| **Provisioning** | Nginx, PHP 7.4–8.4 (ondrej PPA), Composer, MySQL 8 / MariaDB / PostgreSQL, Redis, Node.js 22, Supervisor, UFW firewall, fail2ban, Certbot, swap, unattended security upgrades, a `deploi` deploy user with its own SSH key. |
| **Sites** | Laravel, generic PHP, WordPress, static/SPA and Node.js (reverse proxy). Aliases, per-site PHP version, web directory, redirects. |
| **Deployments** | GitHub, GitLab (incl. self-hosted), Bitbucket or any Git URL. Editable deploy script, **zero-downtime deployments** with releases + one-click rollback, live deployment logs, deploy webhooks with branch filtering, automatic push webhooks, `.env` editor. |
| **SSL** | Free Let's Encrypt certificates (auto-renewing) or upload your own. |
| **Databases** | Create databases and users, manage grants, reveal passwords, **scheduled backups to S3-compatible storage** (AWS S3, DO Spaces, Backblaze B2, Wasabi, MinIO) with retention. |
| **Server tools** | Cron jobs, Supervisor daemons, Laravel queue workers, UFW firewall rules, SSH keys, service restarts, install/uninstall PHP versions, switch CLI PHP. Every action is logged in the server's **task log** with full output. |
| **Account** | Cloud + source-control credentials (encrypted), reusable SSH keys, Slack / Discord / webhook notifications, REST API with tokens. |

## Existing servers and websites

Already have servers running websites (a droplet you set up by hand, Forge/Ploi/cPanel leftovers, Apache boxes…)? Use **Import existing server**:

1. **Servers → Import existing server.** Enter the IP, or pick the machine from your connected DigitalOcean / Hetzner / Vultr / Linode account (Deploi lists everything in the account).
2. Run the one-liner Deploi shows as root. It only appends Deploi's SSH key to `/root/.ssh/authorized_keys`.
3. Click **connect and scan**. The scan is read-only and lists:
   - every website in `/etc/nginx/sites-enabled`, `/etc/nginx/conf.d` and `/etc/apache2/sites-enabled`: domains and aliases, project directory, Laravel / WordPress / PHP / Node.js / static detection, PHP version, SSL certificate and its expiry, git remote and branch, and the owning user
   - installed software: Nginx/Apache, PHP CLI and FPM versions, MySQL/MariaDB/PostgreSQL, Redis, Node, Supervisor, Certbot, UFW
   - databases, cron jobs (`/etc/cron.d` and user crontabs), Supervisor programs, firewall rules and system users
4. Tick the sites and databases you want to manage and click **Import**.

Imported sites are **managed in place**. Deploi never rewrites their Nginx/Apache config and never deletes their files:

- **Deploy** runs the site's deploy script in its existing directory, as the user that owns the files (`git pull` if the directory is a git checkout; otherwise you write the steps). Webhooks, auto-deploy, the `.env` editor, logs, queue workers and the Laravel scheduler all work.
- **Let's Encrypt** uses certbot's Nginx/Apache plugin, which adds the certificate to the existing config in place.
- Settings that would regenerate the vhost (aliases, redirects, custom certificates) are disabled. "Delete" becomes **Remove from Deploi** and leaves the site running.

The **Discover** tab is available on every server, including ones Deploi provisioned, so you can always see what's on a box and pick up sites that were set up by hand.

## Quick start (Docker)

Requirements: Docker Desktop (or Docker Engine + Compose v2).

```bash
docker compose up -d
```

The first start installs Composer dependencies, generates `APP_KEY`, runs migrations and builds front-end assets, which takes a minute or two. Then open **http://localhost:8090** and create your owner account. Registration closes after the first account is created.

| Service | URL / purpose |
| --- | --- |
| `web` + `app` | Deploi at http://localhost:8090 (nginx + PHP 8.4-FPM) |
| `queue` (×3) | Runs provisioning, deployments and every SSH task |
| `scheduler` | Server health checks every 5 min, task-log pruning |
| `assets` | `vite build --watch`, which rebuilds CSS/JS when you edit views |
| `mysql`, `redis` | App database, cache, sessions, queue |
| `mailpit` | Catches outgoing mail at http://localhost:8025 |

Change ports with `APP_PORT`, `FORWARD_DB_PORT`, `MAILPIT_PORT` in your shell or a root `.env`.

Useful commands:

```bash
docker compose exec app php artisan test           # run the test suite
docker compose exec app php artisan tinker
docker compose logs -f queue                        # watch tasks being executed
docker compose restart queue                        # after changing PHP code used by jobs
```

### Testing provisioning locally with the sandbox server

The compose file includes an optional **Ubuntu 24.04 container with systemd + sshd** that behaves like a fresh VPS, so you can try the whole flow without paying for a droplet:

```bash
docker compose --profile sandbox up -d --build sandbox
```

1. In Deploi, go to **New server → Custom server**, IP `sandbox`, port `22`.
2. The server page shows a `docker compose exec sandbox …` command (under *Testing with the local Docker sandbox?*). Run it from the project folder to authorise Deploi's key.
3. Click **start provisioning** and watch the progress bar and live log.
4. Add a site such as `sandbox.test` and open http://localhost:8081 with a `Host` header (`curl -H "Host: sandbox.test" http://localhost:8081`), or add `127.0.0.1 sandbox.test` to your hosts file and browse to `http://sandbox.test:8081`.

Let's Encrypt won't work in the sandbox (it isn't reachable from the internet); everything else will.

## Connecting DigitalOcean

1. DigitalOcean → **API → Tokens → Generate New Token** with *read* and *write* scope.
2. Deploi → **Providers → Connect a provider → DigitalOcean**, paste the token. It's validated immediately and stored encrypted.
3. **New server** → choose the DigitalOcean account, region and size. Deploi uploads a freshly generated SSH key to your DO account, creates an Ubuntu 24.04 droplet with it, waits for it to boot, then provisions it.

**Already have droplets?** Add them as **Custom servers**: run the one-liner Deploi shows on the droplet as root. Deploi expects a fresh Ubuntu install, because provisioning installs and reconfigures Nginx/PHP/MySQL.

Hetzner, Vultr and Linode work the same way (see the help text on the Providers page for which token to create).

## Connecting GitHub / GitLab / Bitbucket

- **GitHub:** classic personal access token with `repo` and `admin:public_key` scopes.
- **GitLab:** personal access token with the `api` scope (plus base URL for self-hosted).
- **Bitbucket:** username + app password with *Account: write*, *Repositories: read*, *Webhooks: read & write*.

When you install a repository Deploi adds the server's deploy key to your account (so the server can clone) and, if auto-deploy is on, creates a push webhook pointing at the site's deploy URL. For the webhook to work, Deploi must be reachable from the internet: set `DEPLOI_WEBHOOK_URL` to its public URL (or a tunnel like `cloudflared` / `ngrok` while testing locally).

Using any other Git host? Pick **Custom Git URL** and add the server's deploy key (shown on the server and site pages) as a read-only deploy key.

## Deploy scripts

Each site has an editable bash script, run as the `deploi` user with `set -eo pipefail`. Available variables:

| Variable | Value |
| --- | --- |
| `$DEPLOI_RELEASE_PATH` | Directory being deployed (the new release for zero-downtime sites) |
| `$DEPLOI_SITE_PATH` | `/home/deploi/<domain>` |
| `$DEPLOI_BRANCH`, `$DEPLOI_DOMAIN`, `$DEPLOI_DEPLOYMENT_ID` | as named |
| `$DEPLOI_PHP`, `$DEPLOI_PHP_FPM`, `$DEPLOI_COMPOSER` | e.g. `php8.3`, `php8.3-fpm`, `php8.3 /usr/local/bin/composer` |

Zero-downtime layout: `releases/<timestamp>`, `shared/.env`, `shared/storage`, and `current → releases/<latest>`. A failed deploy never touches `current`.

## API

Create a token under **Account → API tokens**:

```bash
curl -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" http://localhost:8090/api/servers
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" http://localhost:8090/api/sites/1/deploy
```

Endpoints: `GET /api/servers`, `GET /api/servers/{id}`, `GET /api/sites`, `GET /api/sites/{id}`, `POST /api/sites/{id}/deploy`, `GET /api/sites/{id}/deployments`, `GET /api/sites/{id}/deployments/{id}`.

For CI without a token, just `POST` to the site's deploy webhook URL.

## How it works

```
Controller ──► Server::runTask(name, script) ──► server_tasks row ──► RunServerTask job (queue)
                                                                     │  SSH (phpseclib) as root
                                                                     │  upload script → run → stream output to DB every 1s
                                                                     └► on_finish callback (mark active/failed, parse commit, etc.)
```

- `app/Scripts/`: every bash script Deploi runs (provisioning, Nginx vhosts, deploys, SSL, databases, Supervisor…). Files are written on the server base64-encoded, so there are no quoting surprises.
- `app/Services/Providers/`: cloud provider drivers (one interface, add your own).
- `app/Services/SourceControl/`: GitHub / GitLab / Bitbucket drivers.
- `app/Jobs/`: `CreateCloudServer → WaitForCloudServer → StartProvisioning → RunServerTask`.
- `app/Tasks/Callbacks/`: what happens when a task finishes.

Each server gets its own Ed25519 key pair; the private key, sudo password, database password and all provider tokens are encrypted with `APP_KEY`. **Back up your `.env`**, because losing `APP_KEY` means losing access to every server.

## Production notes

- Run behind HTTPS and set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`/`DEPLOI_WEBHOOK_URL` to the public URL.
- Keep `REDIS_QUEUE_RETRY_AFTER` (default 3800) larger than the longest job, otherwise Redis hands a running provision to a second worker.
- Run several queue workers: provisioning takes 10–15 minutes and shouldn't block deployments.
