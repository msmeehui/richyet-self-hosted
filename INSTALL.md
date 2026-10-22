# Install RichYet for personal use

This guide is for a new, separate installation. Do not point it at an existing RichYet database or another website’s database. Basic hosting/terminal knowledge is needed; there is no installer wizard. The software is free, but you supply your own hosting and provider accounts. The fresh-install route was tested with PHP 8.5 and MariaDB; the preflight requires PHP 8.2 or newer.

## 1. Prepare the hosting

You need Linux hosting with **PHP 8.2+**, PDO MySQL, cURL, zlib and POSIX, a dedicated MySQL/MariaDB database, HTTPS, and scheduled jobs. PHP and the command-line jobs must run as the same account. Allow at least 256 MB PHP memory. Node.js is only for the optional local preview/checks.

Upload the package to an application folder, for example `/home/you/richyet`. Set your domain’s document root to **that folder’s `public/` subfolder**. Keep `server/`, `self-host/` and private files outside the document root. Apache/LiteSpeed must honour the included `.htaccess`; other web servers need equivalent security headers and `.mjs` JavaScript MIME types. This guide does not cover proxy/container configurations.

The package may extract with restricted file permissions. On the host, ensure the web server can read the public assets: typically `0755` for the application folder and directories inside `public/`, and `0644` for files inside `public/`. Only that public tree needs general read access. Keep configuration, keys and backups owner-only; PHP must be able to read the private server source as its owner. Do not make the whole installation writable by everyone.

Use a domain or subdomain root, such as `https://portfolio.example.org`, not a `/richyet/` subpath. Create an empty database and a user with access to that database only.

## 2. Create private configuration

Replace the two example paths below with your hosting paths. Run these commands as the same account that runs PHP:

```sh
RY_APP=/home/you/richyet
RY_PRIVATE=/home/you/richyet-private
(
  set -eu
  umask 077
  mkdir "$RY_PRIVATE"
  chmod 700 "$RY_PRIVATE"
  (set -C; cat "$RY_APP/self-host/config.example.json" > "$RY_PRIVATE/app-config.json")
  (set -C; cat "$RY_APP/self-host/provider-credentials.example.json" > "$RY_PRIVATE/provider-credentials.json")
  chmod 600 "$RY_PRIVATE/app-config.json" "$RY_PRIVATE/provider-credentials.json"
) && cd "$RY_APP"
```

Use a new private directory. Stop if a command reports an error; do not overwrite an existing configuration or regenerate an existing app key when retrying.

In `app-config.json`, set your HTTPS origin (no trailing slash), database details, email and the actual private credential paths. Keep `allow_insecure_localhost` false. `account_display_names` may remain empty. Keep private configuration outside the application, every website’s public root and synced/shared folders.

Generate a new app key directly into that private file, without displaying it:

```sh
php -r '$p=$argv[1]; $c=json_decode(file_get_contents($p),true,16,JSON_THROW_ON_ERROR); if(!str_starts_with($c["app_key"]??"","REPLACE")) { fwrite(STDERR,"An app key already exists; it was not changed.\n"); exit(1); } $c["app_key"]=bin2hex(random_bytes(32)); if(file_put_contents($p,json_encode($c,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n")===false) exit(1);' "$RY_PRIVATE/app-config.json"
```

Fill `provider-credentials.json` with **your own** credentials as described in [DATA_PROVIDERS.md](DATA_PROVIDERS.md). Do not share keys or put them in public files. For a US-only catalogue, omit `market_eodhd_credentials_path` from the app config; the other three keys remain required by the current runtime.

Set the web PHP environment variable `RICHYET_CONFIG` to the absolute app-config path. On Apache/LiteSpeed, your hosting configuration can use `SetEnv RICHYET_CONFIG /home/you/richyet-private/app-config.json` (path only, no secrets). A terminal `export` alone does not configure PHP for browser requests. Ask the hosting operator to set it if the environment is unavailable.

## 3. Initialize and create your account

```sh
php self-host/bootstrap.php check --config "$RY_PRIVATE/app-config.json"
php self-host/bootstrap.php initialize --config "$RY_PRIVATE/app-config.json"
php server/cli.php invite --config "$RY_PRIVATE/app-config.json" --email you@example.org --output "$RY_PRIVATE/setup-link.txt"
```

Use the same email as `market_owner_email`. Initialization creates all thirteen application tables, including currency/number-format settings, and refuses an existing installation. Open the private `setup-link.txt` yourself and use its link to set your password. It expires in one hour and works once. Delete that temporary link file afterward. No email is sent automatically.

## 4. Enable your personal investment catalogue

Review the [starter catalogue and its limits](CATALOGUE.md). Copy it to private storage; it contains searchable listing definitions, not investments you own:

```sh
(umask 077; set -C; cat self-host/starter-catalogue.json > "$RY_PRIVATE/catalogue.json")
php self-host/bootstrap.php catalogue --config "$RY_PRIVATE/app-config.json" --input "$RY_PRIVATE/catalogue.json"
```

This attaches the catalogue to your empty account and enables search. It adds no holdings, invents no prices and makes no provider requests. The helper refuses a nonempty or already-configured account. These setup checks do not test your keys, provider entitlements or current source availability. Keep the chosen listing definitions stable; editing an identity to represent a different exchange is unsafe.

Sign in and choose **Add an investment** on your empty portfolio. Once you have holdings, use **Edit → Add investment** for further additions. Selecting a search result triggers a bounded data check using your keys before units/costs are entered. If it returns unavailable, wait at least five minutes and select it once more; avoid repeated retries. Automatic collection starts for a listing after you add its holding, so a cold search result will not become ready just because the scheduled job runs. Confirm the exact listing, a sensible price date, both comparisons and your saved holding after reloading before adding more. An unavailable listing may require operator work; cloning the software does not guarantee coverage.

## 5. Schedule automatic updates

Create one job, under the same PHP user, every ten minutes. Replace the binary and paths with your own:

```sh
(umask 077; touch "$RY_PRIVATE/collector.log"; chmod 600 "$RY_PRIVATE/collector.log")
```

```cron
*/10 * * * * /usr/bin/php /home/you/richyet/server/cli.php market-refresh --config /home/you/richyet-private/app-config.json >> /home/you/richyet-private/collector.log 2>&1
```

Do not replace unrelated scheduled jobs. Use a private log file with rotation or periodically clear/archive it. The collector applies its own hourly gates and free protective budgets; the ten-minute job does not download every investment every ten minutes. Refreshing the browser reads saved prices only. Check **Last update** and the owner-only **Data status** page after two eligible collections while the market is open, allowing for roughly hourly checks and provider delays. Compare the actual price dates/times and both comparisons; two ten-minute job runs may legitimately reuse the same saved price. A successful job alone does not prove that a source timestamp advanced.

## Back up and recover

Create a private snapshot before updates, and roughly monthly for a small personal installation:

```sh
php server/cli.php backup --config "$RY_PRIVATE/app-config.json" --output "$RY_PRIVATE/portfolio-$(date +%Y%m%d-%H%M%S).json"
```

Copy it to a private location on another device. Keep up to twelve successful copies if that suits your needs. The package does not install backup jobs or upload anything automatically. Also retain your configuration, keys and the matching source version securely; the database snapshot does not include them. Snapshots contain sensitive portfolio/account information and must never go in Git or the web root.

To restore: stop the old collector; create a **separate empty database**, copy your config to a new private file pointing at it, run `bootstrap.php initialize` with that new config, then:

```sh
php server/cli.php restore --config /absolute/private/restore-config.json --input /absolute/private/portfolio-backup.json
```

Check restored holdings and preferences before switching the website’s config. Existing data is never overwritten by restore. Sign in again; sessions/setup tokens are excluded. Provider collection is deliberately paused until the next UTC day after restore to avoid replenishing quotas. Keep the old database until recovery is confirmed. Never run two collectors with the same provider keys as a workaround for limits.

For a forgotten password, run `server/cli.php recover` with the same `--config`, `--email` and a **new** private `--output` file, then use that one-time link yourself.

## If something does not work

- **Unavailable/sign-in error:** check web PHP’s `RICHYET_CONFIG`, HTTPS, extensions, file ownership/permissions and PHP logs. The terminal and website can use different PHP versions.
- **No search results:** the catalogue step may be missing, or the listing is outside the starter catalogue. See CATALOGUE.md.
- **No price/comparison:** check provider access, remaining limits and exact listing support in Data status. Do not lower safety checks or treat a missing value as zero.
- **Old price:** inspect its actual date in Last update. Source delays and Xetra backlogs can take time to recover even while jobs run.
- **Future market hours missing:** run `npm run calendars:review` and review official calendars before updating the included calendar data; unsupported dates are not guessed.

Before upgrading, make a backup, keep the previous source folder and read release instructions. This initial helper is for fresh installation, not in-place migrations. Use only your own installation and provider accounts; never copy another owner’s database or keys as a shortcut.
