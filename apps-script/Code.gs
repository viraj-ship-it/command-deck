/**
 * Brikkin Command Deck — Google Apps Script backend (users + live sheet data)
 *
 * SETUP
 * 1. Set FIRST_ADMIN_EMAIL and FIRST_ADMIN_PASSWORD below.
 * 2. Deploy → New deployment → Web app
 *      Execute as: Me
 *      Who has access: Anyone
 * 3. Copy the Web app URL (ends with /exec) into API_URL at the top of index.html.
 * 4. Sign in on the dashboard with the first admin email + password.
 *    That creates the "_Users" tab and your admin account. After that you can
 *    replace FIRST_ADMIN_PASSWORD with '' and add everyone else from the dashboard.
 *
 * DATA
 * Every tab that has a "Deliverables" column header is shown as one project.
 * Tabs whose name starts with "_" are never sent to the dashboard (_Users lives there).
 */

const FIRST_ADMIN_EMAIL = 'admin@insomniacs.in';
const FIRST_ADMIN_PASSWORD = 'CHANGE_THIS_PASSWORD';
const FIRST_ADMIN_NAME = 'Admin';

const USERS_TAB = '_Users';
const LOG_TAB = '_LoginLog';       // hidden tab: every sign-in attempt
const LOG_KEEP = 2000;             // keep the latest N log rows
const SESSION_HOURS = 6;
const HASH_ROUNDS = 300;

// Sheet column header → field name the dashboard uses
// Payment milestone columns, placed in each project tab next to the deliverable columns
// Payment milestone block, placed in each project tab to the right of the deliverable columns.
// One row per milestone. Payment % of a project should add up to 100%.
// Project timeline block (planned deadline per stage), also in each project tab. Shown to all users.
const TL_COLUMNS = { stage: 'Timeline Stage', date: 'Planned Date', done: 'Completed Date' };

const PAY_COLUMNS = {
  milestone: 'Payment Milestone',   // e.g. Script approval
  stage: 'Linked Stage',            // Script / Recce / Talent / Shoot / First Cut / Client OK / Live Link / Manual
  percent: 'Payment %',             // e.g. 30%
  received: 'Payment Received',     // Yes / No
  receivedDate: 'Received Date'
};

const COLUMNS = {
  sr: 'Sr. No.', name: 'Deliverables', concept: 'Concept', bucket: 'Content Bucket',
  script: 'Script Status', scriptDoc: 'Script', recce: 'Recce', talent: 'Model/Influencer Status',
  shootDate: 'Shoot date', shoot: 'Shoot Status', editor: 'Editor Assigned',
  firstCut: 'First Cut Status', live: 'Live Link', feedback: 'Feedback'
};

/* ------------------------------------------------------------------ entry points */

function doPost(e) {
  let body = {};
  try { body = JSON.parse((e && e.postData && e.postData.contents) || '{}'); } catch (err) {}
  let out;
  try { out = route(body); } catch (err) { out = { ok: false, error: 'Server error: ' + err.message }; }
  return ContentService.createTextOutput(JSON.stringify(out)).setMimeType(ContentService.MimeType.JSON);
}

function doGet() {
  return ContentService.createTextOutput(JSON.stringify({ ok: false, error: 'Brikkin Command Deck API is running. Sign in from the dashboard.' }))
    .setMimeType(ContentService.MimeType.JSON);
}

function route(b) {
  switch (b.action) {
    case 'login': return login(b);
    case 'data': { const u = auth(b); return u.ok ? withData({ ok: true, user: pub(u.user) }, u.user, b.refresh === true) : u; }
    case 'logout': CacheService.getScriptCache().remove('s_' + b.token); return { ok: true };
    case 'listUsers': return admin(b, function () { return { ok: true, users: withLastLogin(readUsers().map(pub)) }; });
    case 'listPrompts': return admin(b, function () { return { ok: true, prompts: listPrompts() }; });
    case 'addPrompt': return admin(b, function () { return addPrompt(b); });
    case 'removePrompt': return admin(b, function () { return removePrompt(b); });
    case 'loginLog': return admin(b, function () { return { ok: true, log: readLog(Number(b.limit) || 300) }; });
    case 'addUser': return admin(b, function (me) { return addUser(b); });
    case 'setRole': return admin(b, function (me) { return setRole(b, me); });
    case 'resetPassword': return admin(b, function (me) { return resetPassword(b); });
    case 'removeUser': return admin(b, function (me) { return removeUser(b, me); });
    case 'changeMyPassword': return changeMyPassword(b);
    default: return { ok: false, error: 'Unknown request.' };
  }
}

/* ------------------------------------------------------------------ auth */

function login(b) {
  const email = norm(b.email), pw = String(b.password || '');
  let users = readUsers();
  if (!users.length && email === norm(FIRST_ADMIN_EMAIL) && FIRST_ADMIN_PASSWORD && pw === FIRST_ADMIN_PASSWORD) {
    writeUser({ email: email, name: FIRST_ADMIN_NAME, role: 'admin', password: pw });
    users = readUsers();
  }
  const u = users.filter(function (x) { return x.email === email; })[0];
  if (!u || !u.active || hash(pw, u.salt) !== u.hash) {
    logLogin(email, u ? u.name : '', !u ? 'Failed · unknown email' : !u.active ? 'Failed · inactive' : 'Failed · wrong password');
    Utilities.sleep(1000);
    return { ok: false, error: 'Email ID or password is incorrect.' };
  }
  logLogin(email, u.name, 'Success');
  const token = Utilities.getUuid() + Utilities.getUuid().replace(/-/g, '');
  CacheService.getScriptCache().put('s_' + token, email, SESSION_HOURS * 3600);
  return withData({ ok: true, token: token, user: pub(u) }, u);
}

function auth(b) {
  const email = b.token ? CacheService.getScriptCache().get('s_' + b.token) : null;
  if (!email) return { ok: false, expired: true, error: 'Your session has ended. Please sign in again.' };
  const u = readUsers().filter(function (x) { return x.email === email; })[0];
  if (!u || !u.active) return { ok: false, expired: true, error: 'Your account is no longer active.' };
  return { ok: true, user: u };
}

function admin(b, fn) {
  const a = auth(b);
  if (!a.ok) return a;
  if (a.user.role !== 'admin') return { ok: false, error: 'Only admins can manage users.' };
  const lock = LockService.getScriptLock();
  lock.waitLock(10000);
  try { return fn(a.user); } finally { lock.releaseLock(); }
}

/* ------------------------------------------------------------------ user management */

function addUser(b) {
  const email = norm(b.email), name = String(b.name || '').trim(), role = b.role === 'admin' ? 'admin' : 'user';
  if (!name) return { ok: false, error: 'Enter the user\'s name.' };
  if (!validEmail(email)) return { ok: false, error: 'Enter a valid email ID.' };
  if (String(b.password || '').length < 6) return { ok: false, error: 'Password must be at least 6 characters.' };
  if (readUsers().some(function (u) { return u.email === email; })) return { ok: false, error: 'A user with this email ID already exists.' };
  writeUser({ email: email, name: name, role: role, password: String(b.password) });
  return { ok: true, users: withLastLogin(readUsers().map(pub)) };
}

function setRole(b, me) {
  const email = norm(b.email), role = b.role === 'admin' ? 'admin' : 'user';
  if (email === me.email) return { ok: false, error: 'You cannot change your own role.' };
  const users = readUsers(), u = users.filter(function (x) { return x.email === email; })[0];
  if (!u) return { ok: false, error: 'User not found.' };
  if (u.role === 'admin' && role !== 'admin' && users.filter(function (x) { return x.role === 'admin' && x.active; }).length <= 1)
    return { ok: false, error: 'At least one admin is needed.' };
  usersSheet().getRange(u.row, 3).setValue(role);
  return { ok: true, users: withLastLogin(readUsers().map(pub)) };
}

function resetPassword(b) {
  const email = norm(b.email), pw = String(b.password || '');
  if (pw.length < 6) return { ok: false, error: 'New password must be at least 6 characters.' };
  const u = readUsers().filter(function (x) { return x.email === email; })[0];
  if (!u) return { ok: false, error: 'User not found.' };
  const salt = Utilities.getUuid();
  usersSheet().getRange(u.row, 4, 1, 2).setValues([[salt, hash(pw, salt)]]);
  return { ok: true, users: withLastLogin(readUsers().map(pub)) };
}

function removeUser(b, me) {
  const email = norm(b.email);
  if (email === me.email) return { ok: false, error: 'You cannot remove yourself.' };
  const u = readUsers().filter(function (x) { return x.email === email; })[0];
  if (!u) return { ok: false, error: 'User not found.' };
  usersSheet().deleteRow(u.row);
  return { ok: true, users: withLastLogin(readUsers().map(pub)) };
}

function changeMyPassword(b) {
  const a = auth(b);
  if (!a.ok) return a;
  if (hash(String(b.oldPassword || ''), a.user.salt) !== a.user.hash) { Utilities.sleep(800); return { ok: false, error: 'Current password is incorrect.' }; }
  if (String(b.newPassword || '').length < 6) return { ok: false, error: 'New password must be at least 6 characters.' };
  const salt = Utilities.getUuid();
  usersSheet().getRange(a.user.row, 4, 1, 2).setValues([[salt, hash(String(b.newPassword), salt)]]);
  return { ok: true };
}

/* ------------------------------------------------------------------ users sheet */

function usersSheet() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  let sh = ss.getSheetByName(USERS_TAB);
  if (!sh) {
    sh = ss.insertSheet(USERS_TAB);
    sh.getRange(1, 1, 1, 7).setValues([['Email', 'Name', 'Role', 'Salt', 'PasswordHash', 'Active', 'Created']]);
    sh.setFrozenRows(1);
    sh.hideSheet();
  }
  return sh;
}

function readUsers() {
  const sh = usersSheet(), last = sh.getLastRow();
  if (last < 2) return [];
  return sh.getRange(2, 1, last - 1, 7).getValues().map(function (r, i) {
    return { row: i + 2, email: norm(r[0]), name: String(r[1]), role: r[2] === 'admin' ? 'admin' : 'user', salt: String(r[3]),
             hash: String(r[4]), active: r[5] !== false && String(r[5]).toUpperCase() !== 'FALSE',
             created: r[6] instanceof Date ? r[6].toISOString() : String(r[6] || '') };
  }).filter(function (u) { return u.email; });
}

function writeUser(o) {
  const salt = Utilities.getUuid();
  usersSheet().appendRow([o.email, o.name, o.role, salt, hash(o.password, salt), true, new Date()]);
}

function pub(u) { return { email: u.email, name: u.name, role: u.role, created: u.created }; }

/* ------------------------------------------------------------------ helpers */

function hash(pw, salt) {
  let h = salt + ':' + pw;
  for (let i = 0; i < HASH_ROUNDS; i++) {
    h = Utilities.base64Encode(Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, h + salt, Utilities.Charset.UTF_8));
  }
  return h;
}
function norm(e) { return String(e || '').trim().toLowerCase(); }
function validEmail(e) { return /^[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}$/.test(e); }

/* ------------------------------------------------------------------ project data (fast + cached) */
const DATA_CACHE_SECONDS = 600;   // sheet data is cached for 10 min; any edit in the sheet clears it

function withData(out, user, fresh) {
  const data = getAllData(fresh);
  out.projects = data.projects;
  if (user) out.prompts = readPrompts(user.email);
  if (user && user.role === 'admin') out.payments = data.payments;   // payments never go to normal users
  out.sheetName = data.sheetName;
  out.updatedAt = data.updatedAt;
  return out;
}

function getAllData(fresh) {
  if (!fresh) { const hit = cacheGet('cd_data'); if (hit) return JSON.parse(hit); }
  const ss = SpreadsheetApp.getActiveSpreadsheet(), tz = ss.getSpreadsheetTimeZone();
  const data = { projects: [], payments: [], sheetName: ss.getName(), updatedAt: new Date().toISOString() };
  ss.getSheets().forEach(function (sh) {
    if (sh.getName().charAt(0) === '_') return;
    const p = readProject(sh, tz);
    if (!p) return;
    if (p.payments.length) data.payments.push({ project: p.name, milestones: p.payments });
    delete p.payments;
    data.projects.push(p);
  });
  try { cachePut('cd_data', JSON.stringify(data), DATA_CACHE_SECONDS); } catch (err) {}
  return data;
}

// Any edit in the Google Sheet clears the cache, so the next Refresh shows it immediately.
function onEdit() { cacheClear('cd_data'); }

function readProject(sh, tz) {
  const lastRow = sh.getLastRow(), lastCol = sh.getLastColumn();
  if (lastRow < 2 || lastCol < 2) return null;
  const head = sh.getRange(1, 1, Math.min(20, lastRow), lastCol).getValues();
  let hi = -1;
  for (let r = 0; r < head.length; r++) {
    if (head[r].some(function (c) { return String(c).trim() === 'Deliverables'; })) { hi = r; break; }
  }
  if (hi < 0) return null;
  const header = head[hi].map(function (c) { return String(c).trim(); });
  const col = function (name) { return header.indexOf(name); };
  const idx = {}; Object.keys(COLUMNS).forEach(function (k) { idx[k] = col(COLUMNS[k]); });
  const pidx = {}; Object.keys(PAY_COLUMNS).forEach(function (k) { pidx[k] = col(PAY_COLUMNS[k]); });
  const ts = col(TL_COLUMNS.stage), td = col(TL_COLUMNS.date), tc = col(TL_COLUMNS.done);

  // read only the columns we use, in one call
  const used = [].concat(Object.keys(idx).map(function (k) { return idx[k]; }), Object.keys(pidx).map(function (k) { return pidx[k]; }), [ts, td, tc])
                 .filter(function (c) { return c >= 0; });
  const maxCol = Math.max.apply(null, used) + 1;
  const n = lastRow - (hi + 1);
  if (n <= 0) return null;
  const values = sh.getRange(hi + 2, 1, n, maxCol).getValues();
  const fmt = function (v) {
    if (v instanceof Date) return Utilities.formatDate(v, tz, 'yyyy-MM-dd');
    if (typeof v === 'number') return String(Math.round(v * 1e6) / 1e6);
    return String(v === null ? '' : v).trim();
  };

  // Live Link: only fetch hyperlinks if some cell holds text instead of a URL
  let rich = null;
  if (idx.live >= 0 && values.some(function (r) { const t = String(r[idx.live]).trim(); return t && !/^https?:\/\//i.test(t); })) {
    rich = sh.getRange(hi + 2, idx.live + 1, n, 1).getRichTextValues();
  }

  const rows = [], payments = [], timeline = [];
  for (let r = 0; r < n; r++) {
    const row = values[r];
    const name = idx.name >= 0 ? String(row[idx.name]).trim() : '';
    if (name) {
      const o = {};
      Object.keys(idx).forEach(function (k) {
        const c = idx[k];
        if (c < 0) { o[k] = ''; return; }
        if (k === 'live') {
          const text = fmt(row[c]), link = rich && rich[r][0] && rich[r][0].getLinkUrl();
          o[k] = /^https?:\/\//i.test(text) ? text : (link || text);
        } else o[k] = fmt(row[c]);
      });
      o.name = name.replace(/\s+/g, ' ');
      rows.push(o);
    }
    if (pidx.milestone >= 0) {
      const m = String(row[pidx.milestone]).trim();
      if (m && !/^total/i.test(m)) {
        const cell = function (k) { const c = pidx[k]; return c < 0 ? '' : row[c]; };
        payments.push({ project: sh.getName().trim(), milestone: m, stage: String(cell('stage')).trim(),
          percent: Number(cell('percent')) || 0,
          received: /^(yes|y|received|paid|done|true)$/i.test(String(cell('received')).trim()),
          receivedDate: fmt(cell('receivedDate')) });
      }
    }
    if (ts >= 0 && td >= 0) {
      const st = String(row[ts]).trim();
      if (st && !/^total/i.test(st)) timeline.push({ stage: st, date: fmt(row[td]), completed: tc >= 0 ? fmt(row[tc]) : '' });
    }
  }
  if (!rows.length && !payments.length) return null;
  return { name: sh.getName().trim(), hasFeedback: idx.feedback >= 0, rows: rows, payments: payments, timeline: timeline };
}

/* cache helpers: CacheService holds max 100 KB per key, so big data is split into parts */
function cachePut(key, str, secs) {
  const c = CacheService.getScriptCache(), size = 40000, parts = Math.ceil(str.length / size), obj = {};
  for (let i = 0; i < parts; i++) obj[key + '_' + i] = str.substr(i * size, size);
  obj[key + '_n'] = String(parts);
  c.putAll(obj, secs);
}
function cacheGet(key) {
  const c = CacheService.getScriptCache(), n = Number(c.get(key + '_n'));
  if (!n) return null;
  const keys = []; for (let i = 0; i < n; i++) keys.push(key + '_' + i);
  const got = c.getAll(keys);
  let s = '';
  for (let i = 0; i < n; i++) { if (got[keys[i]] == null) return null; s += got[keys[i]]; }
  return s;
}
function cacheClear(key) { CacheService.getScriptCache().remove(key + '_n'); }

/* ------------------------------------------------------------------ login history */

function logSheet() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  let sh = ss.getSheetByName(LOG_TAB);
  if (!sh) {
    sh = ss.insertSheet(LOG_TAB);
    sh.getRange(1, 1, 1, 4).setValues([['Time', 'Email', 'Name', 'Result']]);
    sh.setFrozenRows(1);
    sh.hideSheet();
  }
  return sh;
}

function logLogin(email, name, result) {
  try {
    const sh = logSheet();
    sh.appendRow([new Date(), email || '(blank)', name || '', result]);
    const extra = sh.getLastRow() - 1 - LOG_KEEP;
    if (extra > 0) sh.deleteRows(2, extra);
  } catch (err) { /* logging must never block sign-in */ }
}

function readLog(limit) {
  const sh = logSheet(), last = sh.getLastRow();
  if (last < 2) return [];
  const n = Math.min(limit, last - 1);
  return sh.getRange(last - n + 1, 1, n, 4).getValues().reverse().map(function (r) {
    return { time: r[0] instanceof Date ? r[0].toISOString() : String(r[0]), email: String(r[1]), name: String(r[2]), result: String(r[3]) };
  });
}

function withLastLogin(users) {
  const sh = logSheet(), last = sh.getLastRow(), seen = {};
  if (last >= 2) {
    const rows = sh.getRange(2, 1, last - 1, 4).getValues();
    for (let i = rows.length - 1; i >= 0; i--) {
      const e = norm(rows[i][1]);
      if (!seen[e] && rows[i][3] === 'Success' && rows[i][0] instanceof Date) seen[e] = rows[i][0].toISOString();
    }
  }
  return users.map(function (u) { u.lastLogin = seen[u.email] || ''; return u; });
}

/* ------------------------------------------------------------------ VIRA prompts */
// Tab "_VIRA" in the Google Sheet. Columns: Email | Message | Days
//   Email : user's email, or ALL for everyone
//   Days  : blank or Daily = every day, otherwise e.g. Mon or Mon,Wed,Fri
// Only today's messages for the signed-in user are sent to the dashboard.
function readPrompts(email) {
  const sh = SpreadsheetApp.getActiveSpreadsheet().getSheetByName('_VIRA');
  if (!sh || sh.getLastRow() < 2) return [];
  const tz = SpreadsheetApp.getActiveSpreadsheet().getSpreadsheetTimeZone();
  const today = Utilities.formatDate(new Date(), tz, 'EEE').toLowerCase();   // mon, tue, ...
  const me = norm(email);
  return sh.getRange(2, 1, sh.getLastRow() - 1, 3).getValues().filter(function (r) {
    const who = String(r[0]).trim().toLowerCase(), msg = String(r[1]).trim();
    const days = String(r[2]).trim().toLowerCase();
    if (!msg || (who !== 'all' && who !== me)) return false;
    if (!days || days === 'daily' || days === 'every day') return true;
    return days.split(/[ ,\/]+/).some(function (d) { return d.slice(0, 3) === today; });
  }).map(function (r) { return String(r[1]).trim(); });
}

/* ------------------------------------------------------------------ VIRA messages: admin manage */
function viraSheet() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  let sh = ss.getSheetByName('_VIRA');
  if (!sh) { sh = ss.insertSheet('_VIRA'); sh.getRange(1, 1, 1, 3).setValues([['Email', 'Message', 'Days']]); sh.setFrozenRows(1); }
  return sh;
}
function listPrompts() {
  const sh = viraSheet(), last = sh.getLastRow();
  if (last < 2) return [];
  return sh.getRange(2, 1, last - 1, 3).getValues().map(function (r, i) {
    return { row: i + 2, email: String(r[0]).trim(), message: String(r[1]).trim(), days: String(r[2]).trim() };
  }).filter(function (p) { return p.message; });
}
function addPrompt(b) {
  const email = String(b.email || '').trim(), msg = String(b.message || '').trim(), days = String(b.days || '').trim();
  if (!msg) return { ok: false, error: 'Write the message VIRA should show.' };
  if (!email) return { ok: false, error: 'Choose who should see this message.' };
  viraSheet().appendRow([email.toUpperCase() === 'ALL' ? 'ALL' : norm(email), msg, days || 'Daily']);
  return { ok: true, prompts: listPrompts() };
}
function removePrompt(b) {
  const sh = viraSheet(), row = Number(b.row);
  if (!row || row < 2 || row > sh.getLastRow()) return { ok: false, error: 'Message not found. Refresh and try again.' };
  if (String(sh.getRange(row, 2).getValue()).trim() !== String(b.message || '').trim()) return { ok: false, error: 'The sheet changed. Refresh and try again.' };
  sh.deleteRow(row);
  return { ok: true, prompts: listPrompts() };
}
