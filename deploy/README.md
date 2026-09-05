# Deployment — AWS EC2 (Ubuntu + Apache)

Two scripts:

- **`deploy-live.sh`** — redeploys a Git commit to the already-live,
  already-enabled `btwimf.com` docroot (currently `/var/www/btwimf-new`).
- **`staging-setup.sh`** — sets up (or redeploys) a separate, non-live staging
  copy on a different port/docroot.

## Blog Admin content is always preserved

Both scripts carry forward these server-side, git-ignored paths (see
`admin/README.md` → "Git / deploy" and `.gitignore`) from the previous
deployment into the new one, so **a Git deployment can never delete existing
Blog Admin content**:

- `blogs/*/` — published article directories
- `_blog-data/` — Blog Admin's source-of-truth post data
- `assets/img/blog/` — uploaded post hero images
- `blogs/feed.xml` — generated RSS feed
- `admin/config.php` — admin password hash
- `_submissions/` — saved form-handler.php leads

`blogs/index.html` is **not** carried forward — it always comes from the
new commit (it's the committed Git placeholder). Only the Blog Admin's own
**"Rebuild site"** button, run live after the deploy, regenerates it from
`_blog-data/`.

Neither script uses `rsync --delete` or `git clean`, and neither ever deletes
the previous deployment — `deploy-live.sh` renames the old docroot aside
(`<docroot>.pre-deploy-<ref>-<timestamp>`) instead of removing it, so every
deploy is reversible with one `mv`.

## Live deployment

```bash
# on the EC2 box, as a user with sudo — repo is public, no auth needed
sudo bash deploy-live.sh main            # deploy the tip of main
sudo bash deploy-live.sh <full-git-sha>  # deploy an exact commit
sudo bash deploy-live.sh main --dry-run  # preview every action first
```

It downloads that commit from GitHub codeload, builds it in a new sibling
directory, carries forward the Blog Admin/runtime paths above, then does one
atomic swap into the live docroot. Prints the rollback command (and the old
directory's path) when it finishes.

## Staging deployment — non-live, separate docroot

Non-destructive. Creates **only** `/var/www/btwimf-new/` and a **new** Apache
vhost on port **8090**. Never touches `btwimf.com`, WordPress, the database,
backups, or DNS.

## What you need

- SSH access to the EC2 box as a user with `sudo`.
- The deploy bundle: `btwimf-<hash>.tar.gz` (produced with
  `git archive --format=tar.gz -o btwimf-<hash>.tar.gz <commit>` — or ask for it).
- Apache 2.4 already running; PHP for Apache (`libapache2-mod-php php-mbstring`)
  — the script warns if PHP is missing.

## Run it

```bash
# on your machine
scp btwimf-<hash>.tar.gz deploy/staging-setup.sh ubuntu@EC2_HOST:~

# on the EC2 box
sudo bash staging-setup.sh ~/btwimf-<hash>.tar.gz
# (optional first: add  --dry-run  to preview every action)
```

Then, as the script prints:

1. Open inbound **TCP 8090** in the EC2 security group (restrict to your IP).
2. Edit `/var/www/btwimf-new/form-handler.php` → set `RECIPIENT`, `FROM_ADDR`.
   `sudo apt install -y postfix` ("Internet Site") so `mail()` works. Leads are
   also written to `_submissions/*.jsonl` regardless.
3. Visit `http://EC2_IP:8090/admin/setup.php`, set a password, then **delete
   `admin/setup.php`**. Manage posts at `/admin/`.

Staging URL: **`http://<EC2_public_IP>:8090/`**

## Remove staging later

```bash
sudo a2dissite btwimf-staging
sudo a2disconf btwimf-staging-listen
sudo rm /etc/apache2/sites-available/btwimf-staging.conf \
        /etc/apache2/conf-available/btwimf-staging-listen.conf
sudo systemctl reload apache2
sudo rm -rf /var/www/btwimf-new
```

## Optional: HTTPS on a subdomain instead of a port

Only if you want it. Add **one** DNS record `staging.btwimf.com A <EC2_IP>`
(this does not affect `btwimf.com`), then:

```bash
sudo SITE_NAME=btwimf-staging PORT=80 DOCROOT=/var/www/btwimf-new \
  bash staging-setup.sh ~/btwimf-<hash>.tar.gz   # then edit ServerName in the vhost
sudo certbot --apache -d staging.btwimf.com
```
