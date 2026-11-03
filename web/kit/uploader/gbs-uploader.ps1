# Guild Bank Sync uploader
# Watches the GuildBankSync addon's saved file and uploads new guild bank snapshots.
# Runs on the PowerShell that comes with Windows; nothing to install.
# Normally started hidden by start-uploader.vbs and lives in the system tray.
# start-uploader-console.bat runs it in a visible window instead (handy for troubleshooting).
# Keep this file private: it contains the upload key.
param([switch]$Console)

# ---- The only lines you need to change -------------------------------------
$GuildName = 'Your Guild'
$SiteUrl   = 'https://bank.your-guild-domain.com'
$ApiKey    = 'PASTE-UPLOAD-KEY'
# ---------------------------------------------------------------------------
$CheckEverySeconds = 5

# ---------------------------------------------------------------------------
$ErrorActionPreference = 'Stop'
$Here = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $MyInvocation.MyCommand.Path }
$StateFile  = Join-Path $Here 'uploaded.txt'
$FolderFile = Join-Path $Here 'wow-folder.txt'
$LogFile    = Join-Path $Here 'uploader-log.txt'
$AppName    = "$GuildName Bank Uploader"
$IsWin      = ($env:OS -eq 'Windows_NT')
$UseTray    = $IsWin -and -not $Console

try {
    [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
} catch {}

if ($IsWin) {
    Add-Type -AssemblyName System.Windows.Forms
    Add-Type -AssemblyName System.Drawing
}

$script:tray = $null
$script:lastBalloon = [datetime]::MinValue

function Log([string]$text, [string]$color = 'Gray') {
    $line = (Get-Date).ToString('[yyyy-MM-dd HH:mm:ss] ') + $text
    if (-not $UseTray) { Write-Host ((Get-Date).ToString('[HH:mm:ss] ') + $text) -ForegroundColor $color }
    try {
        Add-Content -LiteralPath $LogFile -Value $line -Encoding UTF8
        $info = Get-Item -LiteralPath $LogFile
        if ($info.Length -gt 512KB) {
            $tail = Get-Content -LiteralPath $LogFile -Tail 500
            Set-Content -LiteralPath $LogFile -Value $tail -Encoding UTF8
        }
    } catch {}
}

function Notify([string]$title, [string]$text, [string]$kind = 'Info', [switch]$Force) {
    if (-not $script:tray) { return }
    # Don't spam: at most one popup per 30 seconds unless forced.
    if (-not $Force -and ((Get-Date) - $script:lastBalloon).TotalSeconds -lt 30) { return }
    $script:lastBalloon = Get-Date
    $script:tray.BalloonTipTitle = $title
    $script:tray.BalloonTipText = $text
    $script:tray.BalloonTipIcon = [System.Windows.Forms.ToolTipIcon]::$kind
    $script:tray.ShowBalloonTip(4000)
}

function Set-Status([string]$text) {
    if ($script:tray) {
        $t = "$GuildName Bank: $text"
        if ($t.Length -gt 63) { $t = $t.Substring(0, 63) }
        $script:tray.Text = $t
    }
}

function Show-Error([string]$text) {
    if ($IsWin -and $UseTray) {
        [void][System.Windows.Forms.MessageBox]::Show($text, $AppName, 'OK', 'Error')
    }
    Log $text 'Red'
}

# ---------------------------------------------------------------------------
# Finding the WoW folder and the addon's saved file
# ---------------------------------------------------------------------------
function Test-WowRoot([string]$path) {
    if (-not $path -or -not (Test-Path -LiteralPath $path -PathType Container)) { return $false }
    if (Test-Path -LiteralPath (Join-Path $path 'WTF')) { return $true }
    return [bool](Get-ChildItem -LiteralPath $path -Directory -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -match '^_.+_$' })
}

function Find-WowRoot {
    if (Test-Path -LiteralPath $FolderFile) {
        $saved = (Get-Content -LiteralPath $FolderFile -Raw).Trim()
        if (Test-WowRoot $saved) { return $saved }
    }
    $candidates = New-Object System.Collections.Generic.List[string]
    foreach ($key in 'HKLM:\SOFTWARE\WOW6432Node\Blizzard Entertainment\World of Warcraft',
                     'HKLM:\SOFTWARE\Blizzard Entertainment\World of Warcraft') {
        try {
            $p = (Get-ItemProperty -Path $key -ErrorAction Stop).InstallPath
            if ($p) {
                $p = $p.TrimEnd('\')
                if ((Split-Path $p -Leaf) -match '^_.+_$') { $p = Split-Path $p -Parent }
                $candidates.Add($p)
            }
        } catch {}
    }
    if (${env:ProgramFiles(x86)}) { $candidates.Add((Join-Path ${env:ProgramFiles(x86)} 'World of Warcraft')) }
    if ($env:ProgramFiles) { $candidates.Add((Join-Path $env:ProgramFiles 'World of Warcraft')) }
    foreach ($drive in (Get-PSDrive -PSProvider FileSystem -ErrorAction SilentlyContinue)) {
        foreach ($sub in 'World of Warcraft', 'Games\World of Warcraft', 'Program Files (x86)\World of Warcraft',
                         'Program Files\World of Warcraft', 'Blizzard\World of Warcraft') {
            $candidates.Add((Join-Path $drive.Root $sub))
        }
    }
    foreach ($c in $candidates) {
        if (Test-WowRoot $c) { Set-Content -LiteralPath $FolderFile -Value $c; return $c }
    }

    if ($IsWin) {
        Log 'Could not find World of Warcraft automatically; asking for the folder.' 'Yellow'
        [void][System.Windows.Forms.MessageBox]::Show(
            'Could not find World of Warcraft automatically. Please pick your "World of Warcraft" folder in the next window.',
            $AppName, 'OK', 'Information')
        try {
            $dlg = New-Object System.Windows.Forms.FolderBrowserDialog
            $dlg.Description = 'Select your World of Warcraft folder (the one that contains _forever_ or WTF)'
            if ($dlg.ShowDialog() -eq [System.Windows.Forms.DialogResult]::OK -and (Test-WowRoot $dlg.SelectedPath)) {
                Set-Content -LiteralPath $FolderFile -Value $dlg.SelectedPath
                return $dlg.SelectedPath
            }
        } catch {}
    }
    return $null
}

function Find-SavedFiles([string]$root) {
    $patterns = @(
        (Join-Path $root 'WTF\Account\*\SavedVariables\GuildBankSync.lua'),
        (Join-Path $root '*\WTF\Account\*\SavedVariables\GuildBankSync.lua')
    )
    $found = @()
    foreach ($p in $patterns) {
        $found += @(Get-ChildItem -Path $p -File -ErrorAction SilentlyContinue)
    }
    return $found | Sort-Object FullName -Unique
}

# ---------------------------------------------------------------------------
# Reading snapshots and uploading them
# ---------------------------------------------------------------------------
$luaEscapes = @{ 'n' = "`n"; 't' = "`t"; 'r' = "`r"; 'a' = [string][char]7; 'b' = [string][char]8;
                 'f' = [string][char]12; 'v' = [string][char]11; '\' = '\'; '"' = '"'; "'" = "'"; "`n" = "`n" }

function ConvertFrom-LuaString([string]$raw) {
    return [regex]::Replace($raw, '\\(\d{1,3}|[\s\S])', {
        param($m)
        $esc = $m.Groups[1].Value
        if ($esc -match '^\d+$') { return [string][char]([int]$esc) }
        if ($luaEscapes.ContainsKey($esc)) { return $luaEscapes[$esc] }
        return $esc
    })
}

function Get-Snapshots([string]$path) {
    $text = [IO.File]::ReadAllText($path, [Text.Encoding]::UTF8)
    $rx = [regex]'\["json"\]\s*=\s*"((?:[^"\\]|\\[\s\S])*)"'
    foreach ($m in $rx.Matches($text)) { ConvertFrom-LuaString $m.Groups[1].Value }
}

function Get-Hash([string]$s) {
    $sha = [Security.Cryptography.SHA256]::Create()
    $bytes = $sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($s))
    return -join ($bytes | ForEach-Object { $_.ToString('x2') })
}

function Send-Snapshot([string]$json) {
    $params = @{
        Uri         = "$($SiteUrl.TrimEnd('/'))/index.php?route=api/upload"
        Method      = 'Post'
        Headers     = @{ Authorization = "Bearer $ApiKey" }
        ContentType = 'application/json; charset=utf-8'
        Body        = [Text.Encoding]::UTF8.GetBytes($json)
        UserAgent   = 'GuildBankSync-Uploader/0.4'
        TimeoutSec  = 30
    }
    try {
        $resp = Invoke-RestMethod @params -UseBasicParsing
        return @{ Ok = $true; Status = 200; Body = $resp }
    } catch {
        $status = 0
        if ($_.Exception.Response) { $status = [int]$_.Exception.Response.StatusCode }
        $body = if ($_.ErrorDetails -and $_.ErrorDetails.Message) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        return @{ Ok = $false; Status = $status; Body = $body }
    }
}

$script:sent = New-Object System.Collections.Generic.List[string]
if (Test-Path -LiteralPath $StateFile) {
    foreach ($line in (Get-Content -LiteralPath $StateFile)) { if ($line) { $script:sent.Add($line.Trim()) } }
}
$script:mtimes = @{}
$script:watching = @{}
$script:announced = $false
$script:pauseUntil = [datetime]::MinValue
$script:busy = $false
$script:root = $null

$script:inboxDue = [datetime]::MinValue
$script:inboxCount = -1
$script:inboxWarned = $false

# ---------------------------------------------------------------------------
# Hand-out list: approved requests, written into the addon folder as Inbox.lua.
# The game reads it on the next login or /reload.
# ---------------------------------------------------------------------------
function Find-AddonDirs([string]$root) {
    $patterns = @(
        (Join-Path $root 'Interface\AddOns\GuildBankSync'),
        (Join-Path $root '*\Interface\AddOns\GuildBankSync')
    )
    $found = @()
    foreach ($p in $patterns) { $found += @(Get-Item -Path $p -ErrorAction SilentlyContinue | Where-Object { $_.PSIsContainer }) }
    return $found | Sort-Object FullName -Unique
}

function ConvertTo-LuaString($value) {
    if ($null -eq $value) { return 'nil' }
    $s = [string]$value
    $s = $s.Replace('\', '\\').Replace('"', '\"').Replace("`r", '').Replace("`n", '\n')
    $s = [regex]::Replace($s, '[\x00-\x1f]', '')
    return '"' + $s + '"'
}

function ConvertTo-LuaNumber($value) {
    if ($null -eq $value -or [string]$value -eq '') { return 'nil' }
    return ([long]$value).ToString([Globalization.CultureInfo]::InvariantCulture)
}

function Update-Inbox {
    $dirs = @(Find-AddonDirs $script:root)
    if (-not $dirs.Count) { return }
    try {
        $resp = Invoke-RestMethod -Uri "$($SiteUrl.TrimEnd('/'))/index.php?route=api/handouts" -Method Get `
            -Headers @{ Authorization = "Bearer $ApiKey" } -UserAgent 'GuildBankSync-Uploader/0.4' -TimeoutSec 20 -UseBasicParsing
    } catch {
        if (-not $script:inboxWarned) {
            Log "Could not fetch the hand-out list: $($_.Exception.Message)" 'Yellow'
            $script:inboxWarned = $true
        }
        return
    }
    $script:inboxWarned = $false
    $lines = New-Object System.Collections.Generic.List[string]
    $lines.Add('-- Written by the Guild Bank Sync uploader. Do not edit; it is replaced automatically.')
    $lines.Add('GuildBankSyncInbox = {')
    $lines.Add("  updated = $(ConvertTo-LuaNumber $resp.time),")
    $lines.Add('  requests = {')
    $count = 0
    foreach ($r in @($resp.requests)) {
        if ($null -eq $r) { continue }
        $count++
        $lines.Add(('    {{ id = {0}, character = {1}, itemId = {2}, item = {3}, quality = {4}, quantity = {5}, approvedBy = {6} }},' -f `
            (ConvertTo-LuaNumber $r.id), (ConvertTo-LuaString $r.character), (ConvertTo-LuaNumber $r.item_id),
            (ConvertTo-LuaString $r.item_name), (ConvertTo-LuaNumber $r.quality), (ConvertTo-LuaNumber $r.quantity),
            (ConvertTo-LuaString $r.approved_by)))
    }
    $lines.Add('  },')
    $lines.Add('}')
    $text = ($lines -join "`r`n") + "`r`n"
    $utf8 = New-Object System.Text.UTF8Encoding $false
    foreach ($dir in $dirs) {
        $path = Join-Path $dir.FullName 'Inbox.lua'
        $old = if (Test-Path -LiteralPath $path) { [IO.File]::ReadAllText($path, $utf8) } else { '' }
        # Ignore the timestamp line when comparing, so the file only changes when the list does.
        $strip = { param($t) [regex]::Replace($t, '(?m)^\s*updated = .*$', '') }
        if ((& $strip $old) -ne (& $strip $text)) {
            [IO.File]::WriteAllText($path, $text, $utf8)
        }
    }
    if ($count -ne $script:inboxCount) {
        if ($count -gt $script:inboxCount -and $script:inboxCount -ge 0) {
            Notify 'Requests to hand out' "$count approved request(s) waiting. They show in game after your next /reload." 'Info'
        }
        Log "Hand-out list: $count approved request(s)."
        $script:inboxCount = $count
    }
}

# One pass: look at the saved file(s) and upload anything new.
function Invoke-Sync {
    if ($script:busy -or (Get-Date) -lt $script:pauseUntil) { return }
    $script:busy = $true
    try {
        $files = @(Find-SavedFiles $script:root)
        if (-not $files.Count -and -not $script:announced) {
            Log 'Waiting for the addon. In game: open the guild vault, wait a few seconds, close it and click "Reload now".' 'Yellow'
            Set-Status 'waiting for the addon'
            $script:announced = $true
        }
        foreach ($file in $files) {
            $stamp = $file.LastWriteTimeUtc.Ticks
            if ($script:mtimes[$file.FullName] -eq $stamp) { continue }
            if (-not $script:watching.ContainsKey($file.FullName)) {
                Log "Watching $($file.FullName)"
                $script:watching[$file.FullName] = $true
                Set-Status 'running'
            }

            $allOk = $true
            $changed = $false
            foreach ($json in @(Get-Snapshots $file.FullName)) {
                $hash = Get-Hash $json
                if ($script:sent.Contains($hash)) { continue }
                $r = Send-Snapshot $json
                if ($r.Ok) {
                    $s = $r.Body.summary
                    $msg = "{0} tab(s) updated, {1} new log entries, {2} request(s) completed." -f `
                        $s.tabs_updated, $s.new_transactions, $s.requests_fulfilled
                    Log "Uploaded: $msg" 'Green'
                    Set-Status ('last sync ' + (Get-Date).ToString('HH:mm'))
                    Notify 'Guild bank synced' $msg 'Info' -Force
                    $script:sent.Add($hash); $changed = $true
                    $script:inboxDue = [datetime]::MinValue
                } elseif ($r.Status -eq 401) {
                    Log 'The site rejected the upload key. It was turned off, or your rank can no longer sync. Download a new sync kit from your profile on the website.' 'Red'
                    Notify 'Upload key rejected' 'The website no longer accepts this kit. Download a new sync kit from your profile on the website.' 'Error'
                    Set-Status 'upload key rejected'
                    $script:pauseUntil = (Get-Date).AddMinutes(1)
                    $allOk = $false; break
                } elseif ($r.Status -eq 403 -or $r.Status -eq 429) {
                    Log "Blocked by the site or Cloudflare ($($r.Status)). Will retry. If this keeps happening, check Cloudflare > Security > Events." 'Red'
                    Notify 'Upload blocked' "The website blocked the upload ($($r.Status)). Retrying in a minute." 'Warning'
                    $script:pauseUntil = (Get-Date).AddMinutes(1)
                    $allOk = $false; break
                } elseif ($r.Status -ge 400 -and $r.Status -lt 500) {
                    Log "Snapshot refused ($($r.Status)): $($r.Body)" 'Red'
                    Notify 'Snapshot refused' "The website refused a snapshot ($($r.Status)). See the log for details." 'Warning'
                    $script:sent.Add($hash); $changed = $true   # bad data: don't retry it forever
                } else {
                    Log "Can't reach the site right now ($($r.Status)). Will retry." 'Yellow'
                    Set-Status 'website unreachable, retrying'
                    $script:pauseUntil = (Get-Date).AddSeconds(30)
                    $allOk = $false; break
                }
            }
            if ($changed) {
                $keep = $script:sent | Select-Object -Last 200
                Set-Content -LiteralPath $StateFile -Value $keep
            }
            if ($allOk) { $script:mtimes[$file.FullName] = $stamp }
        }
        if ((Get-Date) -ge $script:inboxDue) {
            $script:inboxDue = (Get-Date).AddMinutes(5)
            Update-Inbox
        }
    } catch {
        Log "Unexpected error: $($_.Exception.Message)" 'Red'
    } finally {
        $script:busy = $false
    }
}

# ---------------------------------------------------------------------------
# Start with Windows (a shortcut in the user's Startup folder)
# ---------------------------------------------------------------------------
function Get-StartupShortcut { Join-Path ([Environment]::GetFolderPath('Startup')) "$AppName.lnk" }

function Set-Autostart([bool]$enabled) {
    $lnk = Get-StartupShortcut
    if ($enabled) {
        $vbs = Join-Path $Here 'start-uploader.vbs'
        $shell = New-Object -ComObject WScript.Shell
        $sc = $shell.CreateShortcut($lnk)
        $sc.TargetPath = Join-Path $env:WINDIR 'System32\wscript.exe'
        $sc.Arguments = '"' + $vbs + '"'
        $sc.WorkingDirectory = $Here
        $sc.Description = $AppName
        $sc.Save()
        Log 'Start with Windows: on'
    } elseif (Test-Path -LiteralPath $lnk) {
        Remove-Item -LiteralPath $lnk -Force
        Log 'Start with Windows: off'
    }
}

# ---------------------------------------------------------------------------
# Tray icon
# ---------------------------------------------------------------------------
function New-ChestIcon {
    $bmp = New-Object System.Drawing.Bitmap 32, 32
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.SmoothingMode = 'AntiAlias'
    $g.Clear([System.Drawing.Color]::Transparent)
    $dark  = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(255, 122, 95, 23))
    $gold  = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(255, 201, 162, 39))
    $light = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(255, 232, 198, 90))
    $lock  = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(255, 42, 32, 22))
    $g.FillRectangle($dark, 2, 7, 28, 22)
    $g.FillRectangle($gold, 4, 9, 24, 18)
    $g.FillRectangle($light, 4, 9, 24, 6)
    $g.FillRectangle($lock, 13, 15, 6, 7)
    $g.Dispose()
    return [System.Drawing.Icon]::FromHandle($bmp.GetHicon())
}

function Start-Tray {
    $menu = New-Object System.Windows.Forms.ContextMenuStrip

    $title = $menu.Items.Add($AppName)
    $title.Enabled = $false
    [void]$menu.Items.Add((New-Object System.Windows.Forms.ToolStripSeparator))

    $open = $menu.Items.Add('Open the guild bank website')
    $open.Font = New-Object System.Drawing.Font($open.Font, [System.Drawing.FontStyle]::Bold)
    $open.Add_Click({ Start-Process $SiteUrl })

    $syncNow = $menu.Items.Add('Check for new snapshots now')
    $syncNow.Add_Click({
        $script:mtimes = @{}
        $script:pauseUntil = [datetime]::MinValue
        $script:inboxDue = [datetime]::MinValue
        Invoke-Sync
    })

    $showLog = $menu.Items.Add('Show log')
    $showLog.Add_Click({
        if (-not (Test-Path -LiteralPath $LogFile)) { Set-Content -LiteralPath $LogFile -Value '' }
        Start-Process notepad.exe -ArgumentList ('"' + $LogFile + '"')
    })

    $auto = New-Object System.Windows.Forms.ToolStripMenuItem 'Start with Windows'
    $auto.CheckOnClick = $true
    $auto.Checked = Test-Path -LiteralPath (Get-StartupShortcut)
    $auto.Add_Click({
        try { Set-Autostart $this.Checked } catch {
            Show-Error "Could not change the Windows startup setting: $($_.Exception.Message)"
            $this.Checked = -not $this.Checked
        }
    })
    [void]$menu.Items.Add($auto)

    [void]$menu.Items.Add((New-Object System.Windows.Forms.ToolStripSeparator))
    $quit = $menu.Items.Add('Quit uploader')
    $quit.Add_Click({
        Log 'Uploader stopped.'
        $script:tray.Visible = $false
        [System.Windows.Forms.Application]::Exit()
    })

    $script:tray = New-Object System.Windows.Forms.NotifyIcon
    $script:tray.Icon = New-ChestIcon
    $script:tray.ContextMenuStrip = $menu
    $script:tray.Add_DoubleClick({ Start-Process $SiteUrl })
    $script:tray.Visible = $true
    Set-Status 'running'
    Notify $AppName 'Running in the background. Right-click the chest icon for options.' 'Info' -Force

    $timer = New-Object System.Windows.Forms.Timer
    $timer.Interval = $CheckEverySeconds * 1000
    $timer.Add_Tick({ Invoke-Sync })
    $timer.Start()
    Invoke-Sync

    [System.Windows.Forms.Application]::Run()
    $timer.Stop()
    $script:tray.Dispose()
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
if (-not $UseTray) {
    $Host.UI.RawUI.WindowTitle = $AppName
    Write-Host ''
    Write-Host "  $AppName" -ForegroundColor Yellow
    Write-Host '  Leave this window open while you play. Close it when you are done.' -ForegroundColor DarkGray
    Write-Host ''
}

if (-not $SiteUrl -or $SiteUrl -match 'your-guild-domain') {
    Show-Error 'The website address is missing. Open gbs-uploader.ps1 in Notepad and set the $SiteUrl line.'
    return
}
if (-not $ApiKey -or $ApiKey.StartsWith('PASTE')) {
    Show-Error 'The upload key is missing. Open gbs-uploader.ps1 in Notepad and paste it on the $ApiKey line.'
    return
}

# Only one copy at a time.
$createdNew = $false
$mutex = New-Object System.Threading.Mutex($true, 'Local\GuildBankSyncUploader', [ref]$createdNew)
if (-not $createdNew) {
    if ($UseTray) {
        [void][System.Windows.Forms.MessageBox]::Show(
            'The uploader is already running. Look for the chest icon near the clock (you may need to click the ^ arrow).',
            $AppName, 'OK', 'Information')
    } else {
        Write-Host 'The uploader is already running (check the tray near the clock).' -ForegroundColor Yellow
    }
    return
}

try {
    $script:root = Find-WowRoot
    if (-not $script:root) {
        Show-Error 'No World of Warcraft folder selected. Start the uploader again to pick it.'
        return
    }
    Log "Uploader started. WoW folder: $($script:root)"

    if ($UseTray) {
        Start-Tray
    } else {
        while ($true) {
            Invoke-Sync
            Start-Sleep -Seconds $CheckEverySeconds
        }
    }
} finally {
    $mutex.ReleaseMutex()
    $mutex.Dispose()
}
