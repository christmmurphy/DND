# Campaign Charts

A self-hosted, shared map tool for your D&D group. Run multiple maps across different campaigns, pin quests, mark danger zones, take snapshots, and sync across the table in real time.

## Files in this folder

- `index.html` — the app (loads React + Tailwind from CDNs, no build step)
- `api.php` — backend; reads/writes the data folder
- `README.md` — this file

A `data/` folder is created automatically on first request. Each map gets its own subfolder:
```
data/
  maps.json              ← list of maps
  {map-slug}/
    meta.json            ← pins and zones for this map
    map.bin + map.type   ← uploaded map image
    snapshots.json       ← snapshot index
    snapshots/{id}/      ← saved snapshots (meta + image)
```

## Run locally on Mac

You need PHP (ships with macOS, or install via Homebrew) and a browser.

```bash
# 1. Clone or download the repo, then cd into the tides folder
cd path/to/DND/tides

# 2. Start PHP's built-in server
php -S localhost:8080

# 3. Open http://localhost:8080 in your browser
```

That's it — no build step, no Node, no dependencies to install. The `data/` folder is created next to `api.php` on first load.

> **Note:** PHP's built-in server is single-threaded. It's fine for local solo use but not suitable for serving multiple players simultaneously. Use the Synology install for shared sessions.

## Install on Synology Web Station

1. **Open File Station** and go to your `/web` shared folder (the Web Station document root).
2. **Create a folder** like `menagerie` (or any name you want — that becomes the URL path).
3. **Upload `index.html` and `api.php`** into that folder.
4. **Visit the URL**, e.g.:
   `https://your-nas.synology.me/menagerie/`
5. The first time you load it, `api.php` creates `data/` next to itself and writes the `.htaccess` guards. If you don't see the upload screen, see "Troubleshooting" below.

That's it. Click **Upload Chart**, pick your map image, and start placing pins.

## Sharing with your group

Just send your group the URL. Everyone who opens it sees the same map, pins, and zones. The page polls every 5 seconds for changes from other clients (and refreshes when you switch back to the tab), so DM edits show up in players' browsers without a manual refresh.

## Optional: lock down writes

By default, anyone who visits the URL can add or delete pins and zones. For a public hostname like `synology.me`, you may want to require a shared secret.

In `api.php`:
```php
const SECRET = 'your-secret-here';
```

In `index.html`:
```js
const SECRET = "your-secret-here";
```

Make them identical. After that, only people whose copy of the page has the matching secret can write. (Reading is still open — anyone with the URL can view.)

For stricter access, put the whole folder behind Synology's reverse proxy auth or HTTP basic auth via Web Station's portal settings.

## Troubleshooting

**"Could not reach the server" toast on first load**
- The page can't reach `api.php`. Check that both files are in the same folder and that PHP is enabled. From the screenshot you shared, PHP 8.0 was Normal — that should work.

**Upload returns 413 / "Map exceeds 12 MB"**
- The app pre-compresses images to JPEG at 2400px wide before upload, so this should be rare. If your map is enormous, lower `maxDim` in `index.html` (search for `compressImage`) or raise `MAX_MAP_BYTES` in `api.php` and the matching limits in Synology's PHP profile (`upload_max_filesize`, `post_max_size`).

**Pins/zones don't sync between two devices**
- Both devices need to be on the same `api.php`. Confirm they're hitting the same URL (no mixed `http://` vs `https://`, no IP vs hostname).

**Data folder is browsable**
- The auto-written `.htaccess` blocks Apache. If your Web Station serves through Nginx instead, add a location block in your virtual host:
  ```
  location /menagerie/data/ { deny all; }
  ```
  Or rename the folder so the path isn't guessable.

## Resetting

To start fresh, delete the `data/` folder. The next page load will recreate it empty.
