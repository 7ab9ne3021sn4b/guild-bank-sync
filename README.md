# Guild Bank Sync

A guild bank website for World of Warcraft: Forever. Any guild can host its own copy; it was first built
for the HOOLIGANS guild.

Members log in with Discord and see the bank tabs their rank can see in game. They can search everything
in the bank, read the deposit/withdrawal history, and request items. Officers approve requests on the site
and get notified in Discord, and a request closes itself when the member takes the item out of the bank.

```
 In game                      Officer's PC                   Web hosting
┌──────────────────┐  /reload ┌───────────────────┐  HTTPS   ┌──────────────────────────┐
│ GuildBankSync    │ ───────▶ │ Uploader (tray)   │ ───────▶ │ PHP site + MySQL         │
│ addon scans bank │  saved   │ watches the saved │  upload  │ Discord login, bank,     │
│ when vault opens │  file    │ file, sends new   │   key    │ history, requests,       │
└──────────────────┘          │ snapshots         │          │ Discord webhook          │
                              └───────────────────┘          └──────────────────────────┘
```

**Why the uploader?** The game can't talk to the internet, so the addon saves a snapshot to disk and a
small uploader on the officer's PC sends it to the website. The site updates whenever someone with both
opens the guild vault and reloads; the addon offers a one-click "Reload now" after closing the vault.

**Two-way:** the uploader also fetches approved requests from the site and writes them into the addon,
so officers see a "To hand out" list next to the guild vault in game.

**Privacy and safety**
- The addon only *reads* the guild bank. It never moves items or gold.
- The uploader only reads the addon's own saved file and sends it to the guild's website. It doesn't touch
  anything else on your PC.
- Discord login asks only for your username and your roles on the guild's Discord server
  (`identify` and `guilds.members.read`). The site cannot read your messages or post as you.

## Folders

| Folder | What it is |
|---|---|
| `web/` | The website (PHP 8.1+, MySQL/MariaDB, curl and zip extensions). |
| `web/kit/addon/GuildBankSync/` | The in-game addon (Lua). |
| `web/kit/uploader/` | Windows uploader (PowerShell, nothing to install). Runs in the system tray. |

The addon and uploader live inside `web/kit/` because the website builds each officer's personal
sync kit from them.

## Setting up the website

1. **Hosting:** any PHP 8.1+ hosting with MySQL and the curl and zip extensions. Create a database and import
   `web/schema.sql` (phpMyAdmin → Import).
2. **Upload** the contents of `web/` to the site's folder, including the `.htaccess` files.
3. **Discord application:** <https://discord.com/developers/applications> → New Application → OAuth2.
   Copy the Client ID and Secret and add the redirect `https://YOUR-SITE/index.php?route=callback`.
   No bot is needed.
4. **Configure:** copy `web/config.sample.php` to `web/config.php` and fill in the guild name, site
   address, database, Discord IDs, upload keys and ranks (Discord role ID → visible tabs and permissions).
   `config.php` is in `.gitignore` and must never be committed. Updating an existing install? Upload
   the new files over the old ones; new database tables are created automatically.
5. Open the site and log in with Discord.

Errors are written to `web/logs/php-errors.log` (blocked from browsers); visitors only see a short code.

## Setting up a syncer (officer PC)

1. Log in on the website, open **Profile**, and click **Download my sync kit**. Ranks with `can_approve`
   (or `can_sync`) get this option. The kit has the addon and the uploader with the guild name, website
   address and a personal key already filled in.
2. Copy the `GuildBankSync` folder into `World of Warcraft\_forever_\Interface\AddOns\`.
3. Double-click `start-uploader.vbs`. A gold chest icon appears near the clock. Right-click it to
   open the website, check for snapshots, show the log, or turn on **Start with Windows**.
   `start-uploader-console.bat` runs it in a visible window for troubleshooting.
4. In game: open the guild vault, wait a few seconds, close it, and click **Reload now**.

The addon only sees the tabs **your character** can see, so sync with a character that can see all tabs.
In-game commands: `/gbs` (status), `/gbs list` (hand-out list), `/gbs scan`, `/gbs popup`,
`/gbs handouts`, `/gbs clear`.

**Keys.** Each kit has its own key, stored on the site only as a hash. Downloading a new kit replaces
the old key; officers can turn off any key on their Profile page, and a key stops working when its owner
loses their syncing rank. Fixed keys in `config.php` (`upload_keys`) also work, e.g. for a shared PC.
To build a kit by hand, set `$GuildName`, `$SiteUrl` and `$ApiKey` at the top of `gbs-uploader.ps1`.

## How it works

**Ranks.** On login, and every 15 minutes after, the site reads the member's Discord roles and uses the
first matching rank in `ranks`. Remove the role and access ends at the next check.

**History.** The game keeps the last 25 entries per tab, with hour-precision times and no IDs. The site
merges overlapping logs from each upload and skips entries it already has. If a tab gets more than 25
changes between syncs, the oldest of those are lost.

**Requests.** Members request an item from tabs they can see, up to the amount in the bank. Officers
approve or deny with an optional note. When the requester's character withdraws the item and the next sync
arrives, the request closes as *Done*. Officers can also mark it done by hand.

**Old snapshots never win.** An older snapshot arriving after a newer one won't overwrite tab contents or
the gold total; its log entries are still merged in.

## License

MIT. See [LICENSE](LICENSE). World of Warcraft is a trademark of Blizzard Entertainment; this is an
unofficial fan project and is not affiliated with or endorsed by Blizzard.
