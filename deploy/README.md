# Staging deployment — AWS EC2 (Ubuntu + Apache)

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
