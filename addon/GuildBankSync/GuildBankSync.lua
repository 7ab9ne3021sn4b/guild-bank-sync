-- Guild Bank Sync
-- Scans the guild bank (items, item logs, money log) whenever you open the guild vault
-- and stores a JSON snapshot in SavedVariables. WoW writes SavedVariables to disk on
-- /reload or logout; the desktop uploader picks the snapshot up from there.

local ADDON_NAME = ...
local VERSION = "0.1.0"
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
  if reason == "first" then
    msg("Snapshot saved (" .. lastSummary .. "). It uploads after /reload or logout.")
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

local function onBankOpened()
  if bankOpen then return end
  bankOpen = true
  ready = false
  sessionId = tostring(time()) .. "-" .. tostring(math.random(1000, 9999))

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
  bankOpen = false
  ready = false
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
  else
    local n = GuildBankSyncDB and GuildBankSyncDB.exports and #GuildBankSyncDB.exports or 0
    msg(string.format("v%s, %d snapshot(s) waiting. Last scan: %s", VERSION, n, lastSummary or "none this session"))
    msg("Commands: /gbs scan, /gbs clear. Type /reload to write snapshots to disk for the uploader.")
  end
end
