-- Guild Bank Sync
-- Scans the guild bank (items, item logs, money log) whenever you open the guild vault
-- and stores a JSON snapshot in SavedVariables. WoW writes SavedVariables to disk on
-- /reload or logout; the desktop uploader picks the snapshot up from there.

local ADDON_NAME = ...
local VERSION = "0.2.0"
local MAX_EXPORTS = 10

local MAX_SLOTS = MAX_GUILDBANK_SLOTS_PER_TAB or 98
local MAX_TABS = MAX_GUILDBANK_TABS or 6
local MONEY_LOG_TAB = MAX_TABS + 1
local QUERY_DELAY = 0.6   -- seconds between server queries (the server throttles these)
local SETTLE_DELAY = 2.0  -- wait after the last query before the first scan
local RESCAN_DELAY = 1.5  -- debounce for rescans while the bank stays open

local frame = CreateFrame("Frame")
local bankOpen = false
local ready = false
local sessionId = nil
local rescanQueued = false
local lastSummary = nil
local savedThisVisit = false

local function msg(text)
  DEFAULT_CHAT_FRAME:AddMessage("|cff33ccffGuildBankSync:|r " .. text)
end

----------------------------------------------------------------------
-- Minimal JSON encoder
----------------------------------------------------------------------
local escapes = { ['"'] = '\\"', ['\\'] = '\\\\', ['\b'] = '\\b', ['\f'] = '\\f',
                  ['\n'] = '\\n', ['\r'] = '\\r', ['\t'] = '\\t' }

local function encodeString(s)
  s = s:gsub('[%c"\\]', function(c)
    return escapes[c] or string.format("\\u%04x", c:byte())
  end)
  return '"' .. s .. '"'
end

local function isArray(t)
  if next(t) == nil then return true end
  local n = 0
  for k in pairs(t) do
    if type(k) ~= "number" then return false end
    n = n + 1
  end
  return n == #t
end

local encode
encode = function(v)
  local tv = type(v)
  if tv == "nil" then return "null"
  elseif tv == "boolean" then return v and "true" or "false"
  elseif tv == "number" then
    if v ~= v or v == math.huge or v == -math.huge then return "null" end
    if v == math.floor(v) then return string.format("%d", v) end
    return tostring(v)
  elseif tv == "string" then return encodeString(v)
  elseif tv == "table" then
    local parts = {}
    if isArray(v) then
      for i = 1, #v do parts[#parts + 1] = encode(v[i]) end
      return "[" .. table.concat(parts, ",") .. "]"
    end
    for k, val in pairs(v) do
      if val ~= nil then
        parts[#parts + 1] = encodeString(tostring(k)) .. ":" .. encode(val)
      end
    end
    return "{" .. table.concat(parts, ",") .. "}"
  end
  return "null"
end

----------------------------------------------------------------------
-- Helpers
----------------------------------------------------------------------
local function parseLink(link)
  if type(link) ~= "string" then return nil, nil end
  local id = tonumber(link:match("item:(%d+)"))
  local name = link:match("%[(.-)%]")
  return id, name
end

local function hoursAgo(years, months, days, hours)
  return (((years or 0) * 365 + (months or 0) * 30 + (days or 0)) * 24) + (hours or 0)
end

local function itemQuality(id, fromSlot)
  if fromSlot and fromSlot >= 0 then return fromSlot end
  if id and GetItemInfo then
    local _, _, quality = GetItemInfo(id)
    return quality
  end
  return nil
end

----------------------------------------------------------------------
-- Scanning
----------------------------------------------------------------------
local function scan(reason)
  if not bankOpen or not ready then return end
  local guild, rankName = GetGuildInfo("player")
  if not guild then return end

  local snap = {
    v = 1,
    addon = VERSION,
    guild = guild,
    realm = GetRealmName and GetRealmName() or nil,
    scanner = UnitName("player"),
    scannerRank = rankName,
    time = time(),
    money = GetGuildBankMoney and GetGuildBankMoney() or nil,
    tabs = {},
    moneyLog = {},
  }

  local itemCount = 0
  for tab = 1, (GetNumGuildBankTabs() or 0) do
    local name, icon, isViewable = GetGuildBankTabInfo(tab)
    if isViewable then
      local t = { index = tab, name = name or ("Tab " .. tab), icon = icon, items = {}, log = {} }
      for slot = 1, MAX_SLOTS do
        local link = GetGuildBankItemLink(tab, slot)
        if link then
          local _, count, _, _, quality = GetGuildBankItemInfo(tab, slot)
          local id, itemName = parseLink(link)
          if id then
            t.items[#t.items + 1] = {
              slot = slot, id = id, name = itemName,
              q = itemQuality(id, quality), count = count or 1,
            }
            itemCount = itemCount + 1
          end
        end
      end
      for i = 1, (GetNumGuildBankTransactions(tab) or 0) do
        local kind, player, link, count, tab1, tab2, y, mo, d, h = GetGuildBankTransaction(tab, i)
        local id, itemName = parseLink(link)
        t.log[#t.log + 1] = {
          type = kind, player = player or "Unknown", id = id, name = itemName,
          q = itemQuality(id), count = count, from = tab1, to = tab2,
          ago = hoursAgo(y, mo, d, h),
        }
      end
      snap.tabs[#snap.tabs + 1] = t
    end
  end

  if GetNumGuildBankMoneyTransactions then
    for i = 1, (GetNumGuildBankMoneyTransactions() or 0) do
      local kind, player, amount, y, mo, d, h = GetGuildBankMoneyTransaction(i)
      snap.moneyLog[#snap.moneyLog + 1] = {
        type = kind, player = player or "Unknown", amount = amount or 0,
        ago = hoursAgo(y, mo, d, h),
      }
    end
  end

  GuildBankSyncDB.exports = GuildBankSyncDB.exports or {}
  local exports = GuildBankSyncDB.exports
  local entry = { session = sessionId, time = snap.time, json = encode(snap) }

  -- One snapshot per bank visit: later scans in the same visit replace the earlier one.
  local last = exports[#exports]
  if last and last.session == sessionId then
    exports[#exports] = entry
  else
    exports[#exports + 1] = entry
  end
  while #exports > MAX_EXPORTS do table.remove(exports, 1) end

  lastSummary = string.format("%d tabs, %d stacks, %s", #snap.tabs, itemCount, date("%H:%M:%S"))
  GuildBankSyncDB.lastScan = snap.time
  savedThisVisit = true
  if reason == "first" then
    msg("Snapshot saved (" .. lastSummary .. "). Close the vault and reload to send it to the website.")
  end
end

local function queueRescan()
  if rescanQueued or not ready then return end
  rescanQueued = true
  C_Timer.After(RESCAN_DELAY, function()
    rescanQueued = false
    scan("update")
  end)
end

----------------------------------------------------------------------
-- Hand-out list (approved requests, written to Inbox.lua by the uploader)
----------------------------------------------------------------------
local QUALITY_COLORS = { [0] = "9d9d9d", [1] = "ffffff", [2] = "1eff00", [3] = "0070dd",
                         [4] = "a335ee", [5] = "ff8000", [6] = "e6cc80", [7] = "00ccff" }
local MAX_LINES = 12
local handoutFrame = nil

local function inboxRequests()
  local inbox = GuildBankSyncInbox
  if type(inbox) ~= "table" or type(inbox.requests) ~= "table" then return {}, 0 end
  return inbox.requests, tonumber(inbox.updated) or 0
end

local function itemText(r)
  local name = r.item or ("item #" .. tostring(r.itemId or "?"))
  local color = QUALITY_COLORS[tonumber(r.quality) or 1] or "ffffff"
  return string.format("|cff%s[%s]|r", color, name)
end

local function ageText(updated)
  if not updated or updated <= 0 then return "unknown" end
  local mins = math.floor((time() - updated) / 60)
  if mins < 1 then return "just now" end
  if mins < 60 then return mins .. " min ago" end
  local hours = math.floor(mins / 60)
  if hours < 48 then return hours .. " h ago" end
  return math.floor(hours / 24) .. " days ago"
end

local function requestLine(r)
  return string.format("%s  %dx %s", r.character or "?", tonumber(r.quantity) or 1, itemText(r))
end

local function buildHandoutFrame()
  local f = CreateFrame("Frame", "GuildBankSyncHandouts", UIParent)
  f:SetWidth(300)
  f:SetFrameStrata("HIGH")
  f:SetClampedToScreen(true)
  local bg = f:CreateTexture(nil, "BACKGROUND")
  bg:SetAllPoints()
  if bg.SetColorTexture then bg:SetColorTexture(0, 0, 0, 0.8) else bg:SetTexture(0, 0, 0, 0.8) end
  f.title = f:CreateFontString(nil, "OVERLAY", "GameFontNormal")
  f.title:SetPoint("TOPLEFT", 10, -10)
  f.body = f:CreateFontString(nil, "OVERLAY", "GameFontHighlightSmall")
  f.body:SetPoint("TOPLEFT", f.title, "BOTTOMLEFT", 0, -6)
  f.body:SetWidth(280)
  f.body:SetJustifyH("LEFT")
  f.foot = f:CreateFontString(nil, "OVERLAY", "GameFontDisableSmall")
  f.foot:SetPoint("TOPLEFT", f.body, "BOTTOMLEFT", 0, -6)
  f.foot:SetWidth(280)
  f.foot:SetJustifyH("LEFT")
  local close = CreateFrame("Button", nil, f, "UIPanelCloseButton")
  close:SetPoint("TOPRIGHT", 2, 2)
  f:Hide()
  return f
end

local function showHandouts()
  local requests, updated = inboxRequests()
  if #requests == 0 or GuildBankSyncDB.handouts == false then
    if handoutFrame then handoutFrame:Hide() end
    return
  end
  handoutFrame = handoutFrame or buildHandoutFrame()
  local lines = {}
  for i, r in ipairs(requests) do
    if i > MAX_LINES then
      lines[#lines + 1] = string.format("...and %d more on the website", #requests - MAX_LINES)
      break
    end
    lines[#lines + 1] = requestLine(r)
  end
  handoutFrame.title:SetText(string.format("To hand out (%d)", #requests))
  handoutFrame.body:SetText(table.concat(lines, "\n"))
  handoutFrame.foot:SetText("Approved on the website. List from " .. ageText(updated) .. ".")
  local height = 10 + handoutFrame.title:GetStringHeight() + 6 + handoutFrame.body:GetStringHeight()
                 + 6 + handoutFrame.foot:GetStringHeight() + 10
  handoutFrame:SetHeight(height)
  handoutFrame:ClearAllPoints()
  local anchor = _G.GuildBankFrame
  if anchor and anchor.IsShown and anchor:IsShown() then
    handoutFrame:SetPoint("TOPLEFT", anchor, "TOPRIGHT", 4, 0)
  else
    handoutFrame:SetPoint("LEFT", UIParent, "LEFT", 40, 80)
  end
  handoutFrame:Show()
end

----------------------------------------------------------------------
-- "Reload now?" popup after a vault visit
----------------------------------------------------------------------
StaticPopupDialogs = StaticPopupDialogs or {}
StaticPopupDialogs["GUILDBANKSYNC_RELOAD"] = {
  text = "Guild Bank Sync: the guild bank was scanned.\nReload now to send it to the website?",
  button1 = "Reload now",
  button2 = "Later",
  OnAccept = function() ReloadUI() end,
  timeout = 0,
  whileDead = true,
  hideOnEscape = true,
  preferredIndex = 3,
}

local function onBankOpened()
  if bankOpen then return end
  bankOpen = true
  ready = false
  savedThisVisit = false
  sessionId = tostring(time()) .. "-" .. tostring(math.random(1000, 9999))

  C_Timer.After(0.5, function()
    if bankOpen then showHandouts() end
  end)

  -- Ask the server for every viewable tab and its log, spaced out to avoid throttling.
  local queries = {}
  for tab = 1, (GetNumGuildBankTabs() or 0) do
    local _, _, isViewable = GetGuildBankTabInfo(tab)
    if isViewable then
      queries[#queries + 1] = function() QueryGuildBankTab(tab) end
      queries[#queries + 1] = function() QueryGuildBankLog(tab) end
    end
  end
  queries[#queries + 1] = function() QueryGuildBankLog(MONEY_LOG_TAB) end

  for i, fn in ipairs(queries) do
    C_Timer.After((i - 1) * QUERY_DELAY, function()
      if bankOpen then fn() end
    end)
  end
  C_Timer.After(#queries * QUERY_DELAY + SETTLE_DELAY, function()
    if not bankOpen then return end
    ready = true
    scan("first")
  end)
end

local function onBankClosed()
  if not bankOpen then return end
  scan("close")
  local saved = savedThisVisit
  bankOpen = false
  ready = false
  savedThisVisit = false
  if handoutFrame then handoutFrame:Hide() end
  if saved and GuildBankSyncDB.popup ~= false and not (InCombatLockdown and InCombatLockdown()) then
    C_Timer.After(0.3, function() StaticPopup_Show("GUILDBANKSYNC_RELOAD") end)
  end
end

----------------------------------------------------------------------
-- Events (registered defensively: event names differ between client versions)
----------------------------------------------------------------------
local function tryRegister(event)
  pcall(frame.RegisterEvent, frame, event)
end

tryRegister("ADDON_LOADED")
tryRegister("GUILDBANKFRAME_OPENED")
tryRegister("GUILDBANKFRAME_CLOSED")
tryRegister("PLAYER_INTERACTION_MANAGER_FRAME_SHOW")
tryRegister("PLAYER_INTERACTION_MANAGER_FRAME_HIDE")
tryRegister("GUILDBANKBAGSLOTS_CHANGED")
tryRegister("GUILDBANKLOG_UPDATE")
tryRegister("GUILDBANK_UPDATE_MONEY")
tryRegister("GUILDBANK_UPDATE_TABS")

local GUILD_BANKER = Enum and Enum.PlayerInteractionType and Enum.PlayerInteractionType.GuildBanker

frame:SetScript("OnEvent", function(_, event, arg1)
  if event == "ADDON_LOADED" then
    if arg1 == ADDON_NAME then
      GuildBankSyncDB = GuildBankSyncDB or {}
      GuildBankSyncDB.exports = GuildBankSyncDB.exports or {}
    end
  elseif event == "GUILDBANKFRAME_OPENED" then
    onBankOpened()
  elseif event == "GUILDBANKFRAME_CLOSED" then
    onBankClosed()
  elseif event == "PLAYER_INTERACTION_MANAGER_FRAME_SHOW" then
    if GUILD_BANKER and arg1 == GUILD_BANKER then onBankOpened() end
  elseif event == "PLAYER_INTERACTION_MANAGER_FRAME_HIDE" then
    if GUILD_BANKER and arg1 == GUILD_BANKER then onBankClosed() end
  elseif bankOpen then
    if event == "GUILDBANK_UPDATE_TABS" and ready then
      -- A tab was bought or permissions changed; re-query everything.
      bankOpen = false
      onBankOpened()
    else
      queueRescan()
    end
  end
end)

----------------------------------------------------------------------
-- Slash command
----------------------------------------------------------------------
SLASH_GUILDBANKSYNC1 = "/gbs"
SlashCmdList.GUILDBANKSYNC = function(input)
  input = (input or ""):lower():match("^%s*(.-)%s*$")
  if input == "scan" then
    if bankOpen and ready then scan("first") else msg("Open the guild vault first.") end
  elseif input == "clear" then
    GuildBankSyncDB.exports = {}
    msg("Stored snapshots cleared.")
  elseif input == "list" then
    local requests, updated = inboxRequests()
    if #requests == 0 then
      msg("No approved requests to hand out (list from " .. ageText(updated) .. ").")
    else
      msg(string.format("%d approved request(s) to hand out (list from %s):", #requests, ageText(updated)))
      for _, r in ipairs(requests) do msg("  " .. requestLine(r)) end
    end
  elseif input == "popup" then
    GuildBankSyncDB.popup = (GuildBankSyncDB.popup == false)
    msg("Reload popup after closing the vault: " .. (GuildBankSyncDB.popup and "on" or "off"))
  elseif input == "handouts" then
    GuildBankSyncDB.handouts = (GuildBankSyncDB.handouts == false)
    msg("Hand-out list next to the vault: " .. (GuildBankSyncDB.handouts and "on" or "off"))
  else
    local n = GuildBankSyncDB and GuildBankSyncDB.exports and #GuildBankSyncDB.exports or 0
    msg(string.format("v%s, %d snapshot(s) waiting. Last scan: %s", VERSION, n, lastSummary or "none this session"))
    msg("Commands: /gbs list, /gbs scan, /gbs popup (on/off), /gbs handouts (on/off), /gbs clear.")
  end
end
