/**
 * Checks PHP cannot make: every Zabbix constant the module uses exists in Zabbix 7.0 (an undefined
 * one only fails at run time, as a broken page), every action in the manifest has its class, and
 * every view an action names is there.
 *   node --test tests/*.test.mjs
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const php = (dir) => fs.readdirSync(path.join(ROOT, dir)).filter((f) => f.endsWith('.php')).map((f) => path.join(ROOT, dir, f));

// Checked against include/defines.inc.php of Zabbix 7.0.31. Add one only after checking it there.
const CONFIRMED = new Set(['USER_TYPE_SUPER_ADMIN', 'USER_TYPE_ZABBIX_ADMIN', 'TAG_OPERATOR_EQUAL', 'ZBX_MONITORED_BY_PROXY', 'ZBX_MONITORED_BY_PROXY_GROUP',
  'ZBX_SIDEBAR_VIEW_MODE_COMPACT', 'ZBX_STYLE_BTN_ALT', 'TRIGGER_SEVERITY_HIGH', 'HOST_INVENTORY_DISABLED', 'HOST_INVENTORY_AUTOMATIC', 'CSRF_TOKEN_NAME']);

test('only Zabbix constants that exist in 7.0', () => {
  const used = new Set();
  for (const f of [...php('.'), ...php('actions'), ...php('lib'), ...php('views')]) {
    for (const m of fs.readFileSync(f, 'utf8').matchAll(/\b(ZBX_[A-Z_]+|USER_TYPE_[A-Z_]+|TAG_OPERATOR_[A-Z_]+|TRIGGER_[A-Z_]+|HOST_INVENTORY_[A-Z_]+|CSRF_[A-Z_]+|ITEM_[A-Z_]+|HOST_STATUS_[A-Z_]+)\b/g)) {
      used.add(`${path.relative(ROOT, f)}: ${m[1]}`);
    }
  }
  const NOT_CONSTANTS = new Set(['HOST_INVENTORY_DATA_DIR']);   // the module's own environment variable
  assert.deepEqual([...used].filter((u) => !CONFIRMED.has(u.split(': ')[1]) && !NOT_CONSTANTS.has(u.split(': ')[1])), []);
});

test('every action has its class, every view its file', () => {
  const m = JSON.parse(fs.readFileSync(path.join(ROOT, 'manifest.json'), 'utf8'));
  assert.equal(m.id, 'host_inventory');
  assert.equal(m.namespace, 'HostInventory');
  for (const [name, a] of Object.entries(m.actions)) {
    const cls = path.join(ROOT, 'actions', a.class + '.php');
    assert.ok(fs.existsSync(cls), `${name}: actions/${a.class}.php`);
    assert.match(fs.readFileSync(cls, 'utf8'), new RegExp(`namespace Modules\\\\HostInventory\\\\Actions;[\\s\\S]*class ${a.class} extends`), `${name}: class ${a.class}`);
    if (a.view) assert.ok(fs.existsSync(path.join(ROOT, 'views', a.view + '.php')), `${name}: views/${a.view}.php`);
  }
});

test('nothing left from where the module came from', () => {
  const all = [...php('.'), ...php('actions'), ...php('lib'), ...php('views'), path.join(ROOT, 'views/js/hostinventory.list.js'), path.join(ROOT, 'manifest.json')];
  const hits = all.filter((f) => /EvpInventory|evp\.inventory|EVP_DATA_DIR|evp_inventory/.test(fs.readFileSync(f, 'utf8'))).map((f) => path.relative(ROOT, f));
  assert.deepEqual(hits, []);
});
