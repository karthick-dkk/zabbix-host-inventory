/**
 * The Host Inventory page's JavaScript, pressed: tabs, search, filters, a host's details and form,
 * ticked hosts edited together, what is exported, and the read-only view.
 *   node --test test/page.test.mjs      (needs jsdom: npm i jsdom)
 * HINV_DATA=<file.json> runs the same checks' rendering against data from a live page.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const HERE = path.dirname(new URL(import.meta.url).pathname);
const ROOT = path.resolve(HERE, '..');
let JSDOM;
try { ({ JSDOM } = createRequire(path.resolve(ROOT, 'package.json'))('jsdom')); } catch { JSDOM = null; }
const skip = JSDOM ? false : 'jsdom not found — npm i jsdom';

const now = 1790000000;
const f = (key, label, type, extra = {}) => ({ key, label, type, choices: [], required: false, list: true, filter: false, ...extra });
const FIELDS = [f('client', 'Client', 'client', { required: true, filter: true }), f('branch', 'Branch', 'text', { required: true, filter: true }),
  f('type', 'Type', 'choice', { choices: ['CI', 'DI', 'On-Prem'], required: true, filter: true }), f('rack', 'Rack', 'text', { list: false })];
const host = (o) => ({ enabled: true, groups: ['Linux servers'], monitored: 'Zabbix server', values: {}, defaults: {}, hostname: null, zabbix_inventory: { mode: 'automatic', filled: {} }, os: null, size: { cpu: null, mem: null }, disks: [], services: [], web: null,
  seen: now - 60, stale: false, eol: null, attention: [], ...o });
const DATA = {
  fields: FIELDS, clients: ['acme', 'northwind'], open: '', now, certAgent: true, proxies: { 7: 'dc-proxy' }, proxyGroups: {},
  save: { url: 'zabbix.php?action=hostinventory.save', token: 't-save' }, website: { url: 'zabbix.php?action=hostinventory.website', token: 't-web' },
  import: { url: 'zabbix.php?action=hostinventory.import', token: 't-imp' }, automatic: { url: 'zabbix.php?action=hostinventory.automatic', token: 't-auto' }, hostUrl: 'zabbix.php?action=latest.view&filter_set=1', canEdit: true,
  hosts: [
    host({ hostid: '1', host: 'acme-ES-Data-1', name: 'acme-ES-Data-1', kind: 'linux', ip: '10.1.0.11', values: { branch: 'Chennai', type: 'On-Prem' }, defaults: { client: 'acme' },
      hostname: 'es-data-01.acme.local', zabbix_inventory: { mode: 'automatic', filled: { Alias: 'es-data-01.acme.local', OS: 'Linux version 5.14.0', 'HW architecture': 'x86_64' } },
      os: { name: 'Rocky Linux 9.4', kernel: '5.14.0-427.el9_4', arch: 'x86_64', uptime: 3542400, agent: '7.0.4', hint: '' }, size: { cpu: 16, mem: 68719476736 },
      disks: [['/', 1e11, 41], ['/data', 2e12, 88.4], ['/data2', 2e12, null]], services: [['elasticsearch', 'running'], ['kaspersky', 'stopped']] }),
    host({ hostid: '2', host: 'nw-AD-01', name: 'nw-AD-01', kind: 'windows', groups: ['Windows servers', 'northwind'], ip: '172.20.4.2', values: { client: 'northwind', type: 'Cloud' },
      os: { name: 'Windows Server 2012 R2 Standard', kernel: 'build 9600', arch: 'x64', uptime: 86400, agent: '6.0.1', hint: '' }, size: { cpu: 8, mem: 34359738368 },
      disks: [['C:', 1.5e11, 48]], services: [['W32Time', 'running']], eol: '2023-10-10', attention: ['Branch missing', 'Type "Cloud" not in its list', 'OS out of support since Oct 2023'] }),
    host({ hostid: '3', host: 'portal.acme.com', name: 'portal.acme.com', kind: 'web', ip: 'https://portal.acme.com/', values: { client: 'acme', branch: 'Chennai', type: 'On-Prem' },
      web: { code: 503, ms: 120, failed: 1, error: 'HTTP 503', cert_days: 9 } }),
    host({ hostid: '4', host: 'db-legacy', name: 'db-legacy', kind: 'linux', ip: '10.1.2.70', seen: null, stale: true, zabbix_inventory: { mode: 'disabled', filled: {} },
      os: { name: null, kernel: '3.10.0-1160', arch: null, uptime: null, agent: null, hint: 'distribution not reported: add the item system.sw.os[name]' },
      attention: ['Client missing', 'Branch missing', 'Type missing', 'not reporting'] }),
  ]
};

function page(data) {
  const dom = new JSDOM('<!doctype html><html><body><nav><button id="hinv-export-csv" data-export="csv"></button><button id="hinv-export-xlsx" data-export="xlsx"></button>'
    + '<button id="hinv-import"></button><button id="hinv-add-website"></button></nav><div id="hinv"></div></body></html>', { runScripts: 'outside-only' });
  const w = dom.window;
  w.HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
  w.HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); };
  w.eval(fs.readFileSync(path.join(ROOT, 'views/js/hostinventory.list.js'), 'utf8'));
  const exported = [];
  w.HostInvExport = { download: (...a) => exported.push(a) };
  w.HostInventory.init(w.document.getElementById('hinv'), JSON.parse(JSON.stringify(data)));
  const $ = (s) => w.document.querySelector(s);
  const $$ = (s) => [...w.document.querySelectorAll(s)];
  const click = (el) => el.dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
  const change = (el, v) => { if (v !== undefined) { el.type === 'checkbox' ? el.checked = v : el.value = v; } el.dispatchEvent(new w.Event('change', { bubbles: true })); };
  const rows = () => $$('tr.h').map((r) => r.dataset.id);
  return { w, $, $$, click, change, rows, exported };
}

test('the table: every host, the measured columns, the flags', { skip }, () => {
  const p = page(DATA);
  assert.deepEqual(p.rows(), ['1', '2', '3', '4']);
  const heads = p.$$('#i-table th').map((t) => t.textContent);
  assert.deepEqual(heads.slice(1), ['Server name', 'IP address', 'Host groups', 'Client', 'Branch', 'Type', 'Hostname', 'OS', 'OS type', 'Architecture', 'CPU', 'Memory', 'Disks', 'Services', 'Monitored by', 'Filled', 'Last seen'], 'Rack is not in the list');
  const cells = [...p.$('tr.h[data-id="1"]').children].map((c) => c.textContent);
  assert.equal(cells[1], 'acme-ES-Data-1', 'the server name alone: no warnings in that cell');
  assert.equal(cells[2], '10.1.0.11');
  assert.equal(cells[3], 'Linux servers');
  assert.deepEqual(cells.slice(7, 13), ['es-data-01.acme.local', 'Rocky Linux 9.45.14.0-427.el9_4', 'Linux', 'x86_64', '16 cores', '64 GB']);
  assert.equal([...p.$('tr.h[data-id="4"]').children][1].textContent, 'db-legacy', 'even for a host that needs attention');
  const r1 = p.$('tr.h[data-id="1"]').textContent;
  assert.match(r1, /Rocky Linux 9\.4/); assert.match(r1, /1 running · 1 stopped/); assert.match(r1, /\+1 more/);
  assert.ok(p.$('tr.h[data-id="1"] .dflt'), 'the client from Cluster Management is shown, marked as a default');
  assert.match(p.$('tr.h[data-id="2"]').textContent, /end of support/);
  assert.match(p.$('tr.h[data-id="2"]').textContent, /not in list/);
  assert.match(p.$('tr.h[data-id="3"]').textContent, /HTTP 503 · 120 ms/);
  assert.match(p.$('tr.h[data-id="3"]').textContent, /certificate 9 days/);
  assert.match(p.$('tr.h[data-id="4"]').textContent, /not reported/);
  assert.match(p.$('tr.h[data-id="4"]').textContent, /not reporting · never/);
});

test('tabs, search, filters', { skip }, () => {
  const p = page(DATA);
  assert.deepEqual(p.$$('#i-tabs .n').map((n) => n.textContent), ['4', '2', '1', '1', '2']);
  p.click(p.$('[data-tab="web"]')); assert.deepEqual(p.rows(), ['3']);
  p.click(p.$('[data-tab="att"]')); assert.deepEqual(p.rows(), ['2', '4']);
  p.click(p.$('[data-tab="all"]'));
  const q = p.$('#i-q'); q.value = 'kaspersky'; q.dispatchEvent(new p.w.Event('input')); assert.deepEqual(p.rows(), ['1']);
  q.value = ''; q.dispatchEvent(new p.w.Event('input'));
  p.change(p.$('#i-f-client'), 'acme'); assert.deepEqual(p.rows(), ['1', '3'], 'the Cluster Management client counts in the filter');
  p.change(p.$('#i-f-client'), '(empty)'); assert.deepEqual(p.rows(), ['4']);
  p.change(p.$('#i-f-client'), '');
  p.change(p.$('#i-f-os'), '(past end of support)'); assert.deepEqual(p.rows(), ['2']);
  p.change(p.$('#i-f-os'), '');
  p.change(p.$('#i-f-group'), 'northwind'); assert.deepEqual(p.rows(), ['2'], 'filter by host group');
  p.change(p.$('#i-f-group'), '');
  q.value = 'windows servers'; q.dispatchEvent(new p.w.Event('input')); assert.deepEqual(p.rows(), ['2'], 'search matches host groups');
});

test('a host\'s details: its form posts to the save action, with the OS details and the disks', { skip }, () => {
  const p = page(DATA);
  p.click(p.$('tr.h[data-id="1"] td:nth-child(2)'));
  const det = p.$('tr.d');
  assert.ok(det, 'a click opens the details');
  const form = det.querySelector('form');
  assert.equal(form.getAttribute('action'), 'zabbix.php?action=hostinventory.save');
  assert.equal(form.querySelector('[name="_csrf_token"]').value, 't-save');
  assert.equal(form.querySelector('[name="hostids[]"]').value, '1');
  assert.deepEqual([...form.querySelectorAll('input, select')].map((e) => e.name).filter((n) => n.startsWith('values[')), ['values[client]', 'values[branch]', 'values[type]', 'values[rack]'], 'every field, Rack too');
  assert.match(form.querySelector('[name="values[client]"]').textContent, /acme \(Cluster Management\)/);
  const text = det.textContent;
  p.click(p.$('tr.h[data-id="1"] td:nth-child(2)'));
  p.click(p.$('tr.h[data-id="2"] td:nth-child(2)'));
  assert.match(p.$('tr.d').textContent, /OS out of support since Oct 2023/, 'what needs attention is in the details');
  p.click(p.$('tr.h[data-id="2"] td:nth-child(2)'));
  p.click(p.$('tr.h[data-id="1"] td:nth-child(2)'));
  for (const s of ['Linux servers', 'es-data-01.acme.local', '5.14.0-427.el9_4', 'x86_64', '41 days', '7.0.4', '/data2', 'kaspersky · stopped', 'Zabbix host inventory', 'Automatic', 'HW architecture']) assert.ok(text.includes(s), s);
  p.click(p.$('tr.h[data-id="1"] td:nth-child(2)'));
  assert.equal(p.$('tr.d'), null, 'a second click closes it');
});

test('hosts keeping no Zabbix inventory: one button asks Zabbix to fill it from their items', { skip }, () => {
  const p = page(DATA);
  const z = p.$('#i-zinv');
  assert.equal(z.hidden, false);
  assert.equal(z.getAttribute('action'), 'zabbix.php?action=hostinventory.automatic');
  assert.deepEqual([...z.querySelectorAll('[name="hostids[]"]')].map((i) => i.value), ['4'], 'only the host whose inventory is disabled; not the website');
  assert.equal(z.querySelector('[name="_csrf_token"]').value, 't-auto');
  assert.equal(page({ ...DATA, canEdit: false }).$('#i-zinv').hidden, true, 'not offered read-only');
});

test('ticked hosts: one field set for all of them', { skip }, () => {
  const p = page(DATA);
  p.change(p.$('input.pick[value="1"]'), true);
  p.change(p.$('input.pick[value="4"]'), true);
  const b = p.$('#i-bulk');
  assert.equal(b.hidden, false);
  assert.deepEqual([...b.querySelectorAll('[name="hostids[]"]')].map((i) => i.value), ['1', '4']);
  p.change(p.$('#i-bf'), 'type');
  assert.deepEqual([...p.$('#i-bval').options].map((o) => o.value), ['', 'CI', 'DI', 'On-Prem']);
  assert.equal(p.$('#i-bval').name, 'values[type]');
  p.click(p.$('#i-bclear'));
  assert.equal(p.$('#i-bulk').hidden, true);
});

test('export: the rows shown, with the measured columns as numbers', { skip }, () => {
  const p = page(DATA);
  p.click(p.$('[data-tab="linux"]'));
  p.click(p.w.document.getElementById('hinv-export-xlsx'));
  const [prefix, kind, , set] = p.exported[0];
  assert.equal(prefix, 'host-inventory'); assert.equal(kind, 'xlsx');
  assert.equal(set.rows.length, 2);
  const col = (h) => set.headers.indexOf(h);
  assert.equal(set.rows[0][col('CPU cores')], 16);
  assert.equal(set.rows[0][col('Memory (GB)')], 64);
  assert.equal(set.rows[0][col('Client')], null, 'a default is not exported as a typed value');
  assert.equal(set.rows[1][col('CPU cores')], null, 'unknown is empty, not 0');
});

test('read-only: no ticks, no forms, no dialogs', { skip }, () => {
  const p = page({ ...DATA, canEdit: false });
  assert.equal(p.$$('input.pick').length, 0);
  p.click(p.$('tr.h[data-id="1"] td:nth-child(2)'));
  assert.equal(p.$('tr.d form'), null);
  assert.equal(p.$('#i-web'), null);
});

test('a live page\'s data renders', { skip: skip || (!process.env.HINV_DATA && 'set HINV_DATA') }, () => {
  const data = JSON.parse(fs.readFileSync(process.env.HINV_DATA, 'utf8'));
  const p = page(data);
  assert.equal(p.rows().length, data.hosts.length);
  for (const id of p.rows()) { p.click(p.$(`tr.h[data-id="${id}"] td:nth-child(2)`)); assert.ok(p.$('tr.d')); p.click(p.$(`tr.h[data-id="${id}"] td:nth-child(2)`)); }
});
