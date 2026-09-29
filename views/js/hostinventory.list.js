/*
 * Host Inventory page: tabs by kind, search, filters from the fields, the table, a detail row per
 * host (its form, when the user may edit), ticked hosts edited together, CSV / Excel export of the
 * rows shown, and the Add website and Import CSV dialogs. Values are posted to the server as
 * ordinary forms; nothing is kept in the browser.
 */
window.HostInventory = {
	init(root, d) {
		const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
		const $ = (s) => root.querySelector(s);
		const KIND = { linux: 'Linux', windows: 'Windows', web: 'Website' };
		const fields = d.fields;
		const hosts = d.hosts;
		let tab = 'all', q = '', osFilter = '', groupFilter = '', open = d.open || null;
		const filt = {};
		const sel = new Set();

		const bytes = (b) => {
			if (b === null || b === undefined) return '—';
			const u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
			let i = 0, v = b;
			while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
			return (v >= 100 || i < 3 ? Math.round(v) : Math.round(v * 10) / 10) + ' ' + u[i];
		};
		const ago = (t) => {
			if (!t) return 'never';
			const s = d.now - t;
			return s < 90 ? 'just now' : s < 5400 ? Math.round(s / 60) + ' min ago' : s < 129600 ? Math.round(s / 3600) + ' h ago' : Math.round(s / 86400) + ' days ago';
		};
		const upFor = (s) => s === null ? '—' : s < 3600 ? Math.round(s / 60) + ' min' : s < 172800 ? Math.round(s / 3600) + ' h' : Math.round(s / 86400) + ' days';
		const value = (h, k) => h.values[k] || '';
		const shown = (h, k) => value(h, k) || h.defaults[k] || '';
		const pastEol = (h) => h.eol && Date.parse(h.eol) / 1000 < d.now;

		const inTab = (h) => tab === 'all' || (tab === 'att' ? h.attention.length > 0 : h.kind === tab);
		const text = (h) => [h.name, h.host, h.ip, h.hostname, ...h.groups, h.os && h.os.name, h.os && h.os.kernel, ...h.services.map((s) => s[0]),
			...fields.map((f) => shown(h, f.key))].join(' ').toLowerCase();
		const rows = () => hosts.filter((h) => inTab(h) && (!q || text(h).includes(q))
			&& (!osFilter || (osFilter === '(past end of support)' ? pastEol(h) : (h.os && h.os.name) === osFilter))
			&& (!groupFilter || h.groups.includes(groupFilter))
			&& Object.entries(filt).every(([k, v]) => !v || (v === '(empty)' ? !shown(h, k) : shown(h, k) === v)));

		// --- layout ------------------------------------------------------------------------
		root.insertAdjacentHTML('beforeend', `
			<div class="i-tabs" role="tablist" id="i-tabs"></div>
			<div class="i-tools" id="i-tools"><input type="search" id="i-q" placeholder="Find a host, IP, OS, service or client" aria-label="Search"></div>
			<form class="i-zinv" id="i-zinv" method="post" action="${esc(d.automatic.url)}" hidden></form>
			<form class="i-bulk" id="i-bulk" method="post" action="${esc(d.save.url)}" hidden></form>
			<div class="i-wrap"><table class="i-t" id="i-table"></table></div>
			${d.canEdit ? `<dialog id="i-web"><form method="post" action="${esc(d.website.url)}">
				<input type="hidden" name="_csrf_token" value="${esc(d.website.token)}">
				<h3>Add website</h3>
				<div class="kv">
					<span>Address</span><span><input type="url" name="url" id="w-url" required placeholder="https://portal.example.com"></span>
					<span>Name</span><span><input type="text" name="name" id="w-name" placeholder="from the address"></span>
					${fields.map((f) => `<span>${esc(f.label)}</span><span>${input(f, '', 'values[' + f.key + ']', 'w-' + f.key)}</span>`).join('')}
					<span>Checked from</span><span><select name="monitored_by" id="w-mon"><option value="">Zabbix server</option>
						${Object.entries(d.proxies).map(([id, n]) => `<option value="proxy:${esc(id)}">Proxy: ${esc(n)}</option>`).join('')}
						${Object.entries(d.proxyGroups).map(([id, n]) => `<option value="group:${esc(id)}">Proxy group: ${esc(n)}</option>`).join('')}</select></span>
				</div>
				<p class="sub">A host with a web check of the address every minute; a HIGH problem when it fails.
					${d.certAgent ? 'The certificate is checked too (https).' : 'The certificate is not checked until a Super admin sets the agent 2 for it on the Fields page.'}</p>
				<div class="dfoot"><button type="button" class="btn-alt" data-close>Cancel</button><button type="submit">Add website</button></div>
			</form></dialog>
			<dialog id="i-imp"><form method="post" action="${esc(d.import.url)}" enctype="multipart/form-data">
				<input type="hidden" name="_csrf_token" value="${esc(d.import.token)}">
				<h3>Import CSV</h3>
				<p class="sub">The file Export CSV writes, edited: a Host column and a column per field (by name). Empty cells leave a value as it is; "-" clears it. Other columns are ignored. You see what changes before anything is saved.</p>
				<p><input type="file" name="csv" id="imp-file" accept=".csv,text/csv" required></p>
				<div class="dfoot"><button type="button" class="btn-alt" data-close>Cancel</button><button type="submit">Preview</button></div>
			</form></dialog>` : ''}`);

		function input(f, v, name, id, placeholder = '') {
			if (f.type === 'choice' || f.type === 'client') {
				const opts = f.type === 'client' ? d.clients : f.choices;
				const extra = v && !opts.includes(v) ? [v] : [];
				return `<select name="${esc(name)}" id="${esc(id)}"><option value="">${placeholder ? esc('— ' + placeholder) : '—'}</option>${[...opts, ...extra].map((c) => `<option${c === v ? ' selected' : ''}>${esc(c)}</option>`).join('')}</select>`;
			}
			const type = f.type === 'date' ? 'date' : f.type === 'number' ? 'number' : 'text';
			return `<input type="${type}" name="${esc(name)}" id="${esc(id)}" value="${esc(v)}" placeholder="${esc(placeholder)}" maxlength="255">`;
		}

		// --- tabs and filters -------------------------------------------------------------------
		function tabs() {
			const n = (k) => hosts.filter((h) => k === 'all' || (k === 'att' ? h.attention.length > 0 : h.kind === k)).length;
			$('#i-tabs').innerHTML = [['all', 'All'], ['linux', 'Linux'], ['windows', 'Windows'], ['web', 'Websites'], ['att', 'Needs attention']]
				.map(([k, l]) => `<button type="button" role="tab" class="${k}" data-tab="${k}" aria-selected="${tab === k}">${l} <span class="n">${n(k)}</span></button>`).join('');
		}
		function filters() {
			const tools = $('#i-tools');
			tools.querySelectorAll('select').forEach((s) => s.remove());
			fields.filter((f) => f.filter).forEach((f) => {
				const vals = f.type === 'choice' ? f.choices : [...new Set(hosts.map((h) => shown(h, f.key)).filter(Boolean).concat(f.type === 'client' ? d.clients : []))].sort();
				tools.insertAdjacentHTML('beforeend', `<select id="i-f-${esc(f.key)}" data-filter="${esc(f.key)}" aria-label="${esc(f.label)}"><option value="">${esc(f.label)}: all</option>${vals.map((v) => `<option>${esc(v)}</option>`).join('')}<option>(empty)</option></select>`);
			});
			const groups = [...new Set(hosts.flatMap((h) => h.groups))].sort((a, b) => a.localeCompare(b));
			tools.insertAdjacentHTML('beforeend', `<select id="i-f-group" data-group aria-label="Host group"><option value="">Host group: all</option>${groups.map((v) => `<option>${esc(v)}</option>`).join('')}</select>`);
			const oses = [...new Set(hosts.map((h) => h.os && h.os.name).filter(Boolean))].sort();
			tools.insertAdjacentHTML('beforeend', `<select id="i-f-os" data-os aria-label="OS"><option value="">OS: all</option>${oses.map((v) => `<option>${esc(v)}</option>`).join('')}<option>(past end of support)</option></select>`);
		}

		// --- the table ----------------------------------------------------------------------
		const meter = (p) => p === null ? '<span class="meter"></span>' : `<span class="meter"><i class="${p >= 90 ? 'b' : p >= 80 ? 'w' : ''}" style="width:${Math.min(100, p)}%"></i></span>`;
		function fieldCell(h, f) {
			const v = value(h, f.key), dv = h.defaults[f.key];
			if (!v && dv) return `<span class="dflt" title="From Cluster Management">${esc(dv)}</span>`;
			if (!v) return f.required ? '<span class="miss">missing</span>' : '<span class="sub">—</span>';
			const inList = f.type !== 'choice' || f.choices.includes(v);
			const body = f.key === 'type' ? `<span class="chip t-${esc(v)}">${esc(v)}</span>` : esc(v);
			return body + (inList ? '' : ' <span class="miss">not in list</span>');
		}
		function osCell(h) {
			if (h.kind === 'web' || !h.os) return '<span class="sub">—</span>';
			const o = h.os;
			const name = o.name ? esc(o.name) : '<span class="warn">not reported</span>';
			return `<div class="num">${name}</div><div class="sub">${esc(o.kernel || '—')}</div>`
				+ (pastEol(h) ? `<span class="flag">end of support ${esc(new Date(h.eol).toLocaleDateString(undefined, { month: 'short', year: 'numeric' }))}</span>` : '');
		}
		function render() {
			tabs();
			const cols = fields.filter((f) => f.list);
			const list = rows();
			const span = cols.length + 15;
			let html = `<thead><tr>${d.canEdit ? '<th><input type="checkbox" id="i-all" aria-label="Tick all shown"></th>' : '<th></th>'}<th>Server name</th><th>IP address</th><th>Host groups</th>`
				+ cols.map((f) => `<th>${esc(f.label)}</th>`).join('')
				+ '<th>Hostname</th><th>OS</th><th>OS type</th><th>Architecture</th><th>CPU</th><th>Memory</th><th>Disks</th><th>Services</th><th>Monitored by</th><th>Filled</th><th>Last seen</th></tr></thead><tbody>';
			if (!list.length) html += `<tr><td colspan="${span}" class="empty">${hosts.length ? 'No host matches. Clear a filter or the search.' : 'No Linux or Windows server with an agent template, and no website, in Zabbix yet.'}</td></tr>`;
			for (const h of list) {
				const req = fields.filter((f) => f.required), got = req.filter((f) => shown(h, f.key)).length;
				const unk = '<span class="sub">—</span>';
				// A website has no OS or hardware: its check fills those five columns instead.
				const kindCell = `<td><span class="kind k-${h.kind}">${KIND[h.kind]}</span></td>`;
				const measured = h.kind === 'web'
					? `<td colspan="2">${h.web.code === null ? '<span class="warn">no result yet</span>' : `<span class="num${h.web.failed ? ' bad' : ''}">HTTP ${h.web.code} · ${h.web.ms ?? '—'} ms</span>`}</td>`
						+ kindCell + `<td colspan="3"><span class="sub">${h.web.cert_days === null ? 'certificate not checked' : 'certificate ' + (h.web.cert_days < 0 ? 'expired' : h.web.cert_days + ' days')}</span></td>`
					: `<td class="num">${h.hostname ? esc(h.hostname) : unk}</td><td>${osCell(h)}</td>${kindCell}<td>${h.os && h.os.arch ? esc(h.os.arch) : unk}</td>`
						+ `<td class="num">${h.size.cpu === null ? unk : h.size.cpu + ' cores'}</td><td class="num">${h.size.mem === null ? unk : bytes(h.size.mem)}</td>`;
				const disks = h.kind === 'web' ? '<span class="sub">—</span>' : !h.disks.length ? '<span class="sub">not reported</span>'
					: `<div class="disks">${h.disks.slice(0, 2).map(([m, t, p]) => `<div class="disk" title="${esc(m)} · ${bytes(t)}"><span class="m">${esc(m)}</span>${meter(p)}<span class="p">${p === null ? '—' : Math.round(p) + '%'}</span></div>`).join('')}${h.disks.length > 2 ? `<span class="sub">+${h.disks.length - 2} more</span>` : ''}</div>`;
				const down = h.services.filter((s) => s[1] === 'stopped').length;
				const svc = h.kind === 'web' ? (h.web.failed ? '<span class="bad">failing</span>' : h.web.code === null ? '<span class="sub">—</span>' : 'up')
					: !h.services.length ? '<span class="sub">none watched</span>'
					: `${h.services.filter((s) => s[1] === 'running').length} running${down ? ` · <span class="bad">${down} stopped</span>` : ''}`;
				html += `<tr class="h" data-id="${esc(h.hostid)}">`
					+ `<td>${d.canEdit ? `<input type="checkbox" class="pick" value="${esc(h.hostid)}"${sel.has(h.hostid) ? ' checked' : ''} aria-label="Tick ${esc(h.name)}">` : ''}</td>`
					// Server name is {HOST.NAME}; the technical name ({HOST.HOST}) only when it says something more.
					+ `<td><div class="nm">${esc(h.name)}</div>`
					+ (h.host !== h.name && h.host !== h.ip ? `<div class="sub">${esc(h.host)}</div>` : '') + (h.enabled ? '' : '<div class="sub">not monitored</div>') + '</td>'
					+ `<td class="num">${h.kind === 'web' ? `<span class="sub">${esc(h.ip)}</span>` : (h.ip ? esc(h.ip) : '<span class="sub">—</span>')}</td>`
					+ `<td><div class="grps">${h.groups.length ? h.groups.map((g) => `<span>${esc(g)}</span>`).join('') : '<span class="sub">—</span>'}</div></td>`
					+ cols.map((f) => `<td>${fieldCell(h, f)}</td>`).join('')
					+ measured + `<td>${disks}</td><td>${svc}</td><td class="sub">${esc(h.monitored)}</td>`
					+ `<td><span class="fill ${got === req.length ? 'ok' : 'part'}">${got}/${req.length}</span></td>`
					+ `<td class="num${h.stale ? ' bad' : ''}">${h.stale ? 'not reporting · ' : ''}${ago(h.seen)}</td></tr>`;
				if (open === h.hostid) html += detail(h, span);
			}
			$('#i-table').innerHTML = html + '</tbody>';
			bulk();
			zinv();
		}

		function detail(h, span) {
			const typed = fields.map((f) => {
				const v = value(h, f.key), dv = h.defaults[f.key] || '';
				return `<span>${esc(f.label)}${f.required ? ' *' : ''}</span><span>${d.canEdit
					? input(f, v, 'values[' + f.key + ']', 'e-' + f.key, dv ? dv + ' (Cluster Management)' : '')
					: (v ? esc(v) : dv ? `<span class="dflt">${esc(dv)}</span>` : '—')}</span>`;
			}).join('');
			const o = h.os || {};
			const measured = h.kind === 'web'
				? `<span>Address</span><span>${esc(h.ip)}</span>`
					+ `<span>Response</span><span class="num">${h.web.code === null ? '—' : 'HTTP ' + h.web.code + ' · ' + (h.web.ms ?? '—') + ' ms'}<span class="src">web.test.rspcode</span></span>`
					+ `<span>Last error</span><span>${esc(h.web.error || '—')}</span>`
					+ `<span>Certificate</span><span class="num">${h.web.cert_days === null ? 'not checked' : h.web.cert_days < 0 ? '<span class="bad">expired</span>' : 'expires in ' + h.web.cert_days + ' days'}<span class="src">cert.not_after</span></span>`
				: `<span>Host groups</span><span>${esc(h.groups.join(', ') || '—')}</span>`
					+ `<span>Hostname</span><span class="num">${esc(h.hostname || '—')}<span class="src">system.hostname</span></span>`
					+ `<span>OS</span><span>${o.name ? esc(o.name) : `<span class="warn">not reported</span>`}<span class="src">system.sw.os</span>${o.hint ? `<div class="sub">${esc(o.hint)}</div>` : ''}${pastEol(h) ? `<div><span class="flag">end of support ${esc(h.eol)}</span></div>` : ''}</span>`
					+ `<span>${h.kind === 'windows' ? 'Build' : 'Kernel'}</span><span class="num">${esc(o.kernel || '—')}<span class="src">system.uname</span></span>`
					+ `<span>Architecture</span><span>${esc(o.arch || '—')}<span class="src">system.sw.arch</span></span>`
					+ `<span>Up for</span><span class="num">${upFor(o.uptime ?? null)}<span class="src">system.uptime</span></span>`
					+ `<span>Zabbix agent</span><span class="num">${esc(o.agent || '—')}<span class="src">agent.version</span></span>`
					+ `<span>CPU</span><span class="num">${h.size.cpu ?? '—'} cores<span class="src">system.cpu.num</span></span>`
					+ `<span>Memory</span><span class="num">${bytes(h.size.mem)}<span class="src">vm.memory.size[total]</span></span>`
					+ `<span>Monitored by</span><span>${esc(h.monitored)}</span>`;
			const disks = h.kind === 'web' ? '' : `<h4>Disks</h4>${h.disks.length ? `<div class="disks">${h.disks.map(([m, t, p]) => `<div class="disk" style="grid-template-columns: 90px 1fr 110px"><span class="m" title="${esc(m)}">${esc(m)}</span>${meter(p)}<span class="p">${p === null ? '—' : Math.round(p) + '%'} of ${bytes(t)}</span></div>`).join('')}</div>` : '<p class="sub">No file system reported.</p>'}`;
			const svc = h.kind === 'web' ? '' : `<h4 style="margin-top:14px">Services</h4>${h.services.length ? `<div class="svcs">${h.services.map(([n, s]) => `<span class="${s}">${esc(n)}${s === 'running' ? '' : ' · ' + s}</span>`).join('')}</div>` : '<p class="sub">None watched on this host: Windows services come from the Windows template, Linux units from "Systemd by Zabbix agent 2".</p>'}`;
			const form = d.canEdit ? `<form method="post" action="${esc(d.save.url)}"><input type="hidden" name="_csrf_token" value="${esc(d.save.token)}"><input type="hidden" name="hostids[]" value="${esc(h.hostid)}"><input type="hidden" name="open" value="${esc(h.hostid)}"><div class="kv">${typed}</div><div class="acts"><button type="submit">Save</button><a href="${esc(d.hostUrl)}&amp;hostids%5B%5D=${esc(h.hostid)}">Latest data</a></div></form>`
				: `<div class="kv">${typed}</div>`;
			const zi = h.zabbix_inventory || { mode: 'disabled', filled: {} };
			const zinvBlock = h.kind === 'web' ? '' : `<h4 style="margin-top:14px">Zabbix host inventory</h4><p class="sub">${{
				automatic: 'Automatic: Zabbix fills the fields its items are linked to, from their latest values.',
				manual: 'Manual: typed in Zabbix; items do not fill it.',
				disabled: 'Disabled: no inventory is kept, so item values are not copied to it.' }[zi.mode]}</p>`
				+ (Object.keys(zi.filled).length ? `<div class="kv">${Object.entries(zi.filled).map(([k, v]) => `<span>${esc(k)}</span><span>${esc(v)}</span>`).join('')}</div>`
					: zi.mode === 'automatic' ? '<p class="sub">Nothing filled yet: the linked items have not reported.</p>' : '');
			const needs = h.attention.length ? `<div class="why" style="margin-bottom:10px">${h.attention.map((w) => `<span>${esc(w)}</span>`).join('')}</div>` : '';
			return `<tr class="d"><td colspan="${span}"><div class="det"><div><h4>Described</h4>${needs}${form}</div><div><h4>Measured (live)</h4><div class="kv">${measured}</div>${zinvBlock}</div><div>${disks}${svc}${h.kind === 'web' ? '<h4>Web check</h4><p class="sub">Every minute from ' + esc(h.monitored) + '.</p>' : ''}</div></div></td></tr>`;
		}

		function bulk() {
			const b = $('#i-bulk');
			b.hidden = !sel.size;
			if (!sel.size) { b.innerHTML = ''; return; }
			const f0 = fields[0];
			b.innerHTML = `<input type="hidden" name="_csrf_token" value="${esc(d.save.token)}">${[...sel].map((id) => `<input type="hidden" name="hostids[]" value="${esc(id)}">`).join('')}`
				+ `<b>${sel.size}</b> ${sel.size === 1 ? 'host' : 'hosts'} ticked · set <select id="i-bf" aria-label="Field">${fields.map((f) => `<option value="${esc(f.key)}">${esc(f.label)}</option>`).join('')}</select> to <span id="i-bv"></span>`
				+ ' <button type="submit">Apply</button> <button type="button" class="btn-alt" id="i-bclear">Clear</button>';
			const paint = (f) => { $('#i-bv').innerHTML = input(f, '', 'values[' + f.key + ']', 'i-bval'); };
			paint(f0);
			$('#i-bf').addEventListener('change', (e) => paint(fields.find((f) => f.key === e.target.value)));
		}

		// Hosts that keep no Zabbix inventory: one button lets Zabbix fill it from their items.
		function zinv() {
			const z = $('#i-zinv');
			const off = hosts.filter((h) => h.kind !== 'web' && h.zabbix_inventory && h.zabbix_inventory.mode === 'disabled');
			z.hidden = !d.canEdit || !off.length;
			if (z.hidden) { z.innerHTML = ''; return; }
			z.innerHTML = `<input type="hidden" name="_csrf_token" value="${esc(d.automatic.token)}">${off.map((h) => `<input type="hidden" name="hostids[]" value="${esc(h.hostid)}">`).join('')}`
				+ `<span><b>${off.length}</b> ${off.length === 1 ? 'host keeps' : 'hosts keep'} no Zabbix inventory, so ${off.length === 1 ? 'its' : 'their'} item values (OS, hostname, architecture, CPU, memory) are not copied to it.</span>`
				+ ' <button type="submit">Fill it from the items</button>'
				+ `<span class="sub">Sets inventory mode to Automatic. Hosts with a manual inventory are left alone.</span>`;
		}

		// --- export: the rows shown, every column ------------------------------------------------
		function exportSet() {
			const headers = ['Host', 'Server name', 'IP address / URL', 'Host groups', ...fields.map((f) => f.label), 'Hostname', 'OS', 'OS type', 'Kernel / build', 'Architecture', 'CPU cores', 'Memory (GB)',
				'Disks', 'Services running', 'Services stopped', 'Monitored by', 'Last seen', 'End of support', 'Needs attention'];
			const out = rows().map((h) => [h.host, h.name, h.ip, h.groups.join(', ') || null, ...fields.map((f) => value(h, f.key) || null),
				h.hostname || null, h.os ? h.os.name : null, KIND[h.kind], h.os ? h.os.kernel : null, h.os ? h.os.arch : null, h.size.cpu, h.size.mem === null ? null : Math.round(h.size.mem / 1073741824 * 10) / 10,
				h.disks.map(([m, t, p]) => `${m} ${bytes(t)}${p === null ? '' : ' ' + Math.round(p) + '%'}`).join('; ') || null,
				h.services.filter((s) => s[1] === 'running').length, h.services.filter((s) => s[1] === 'stopped').length,
				h.monitored, h.seen ? new Date(h.seen * 1000).toISOString().slice(0, 16).replace('T', ' ') : null, h.eol, h.attention.join('; ') || null]);
			return { headers, rows: out };
		}
		document.querySelectorAll('[data-export]').forEach((b) => b.addEventListener('click', () => {
			window.HostInvExport.download('host-inventory', b.dataset.export, 'Host inventory', exportSet());
		}));

		// --- events -------------------------------------------------------------------------
		root.addEventListener('click', (e) => {
			const t = e.target;
			const tb = t.closest('[data-tab]');
			if (tb) { tab = tb.dataset.tab; render(); return; }
			if (t.id === 'i-bclear') { sel.clear(); render(); return; }
			if (t.matches('[data-close]')) { t.closest('dialog').close(); return; }
			if (t.closest('input, select, button, a, form, tr.d')) return;
			const row = t.closest('tr.h');
			if (row) { open = open === row.dataset.id ? null : row.dataset.id; render(); }
		});
		root.addEventListener('change', (e) => {
			const t = e.target;
			if (t.classList.contains('pick')) { t.checked ? sel.add(t.value) : sel.delete(t.value); bulk(); return; }
			if (t.id === 'i-all') { rows().forEach((h) => t.checked ? sel.add(h.hostid) : sel.delete(h.hostid)); render(); return; }
			if (t.dataset.filter !== undefined) { filt[t.dataset.filter] = t.value; render(); return; }
			if (t.dataset.os !== undefined) { osFilter = t.value; render(); return; }
			if (t.dataset.group !== undefined) { groupFilter = t.value; render(); }
		});
		$('#i-q').addEventListener('input', (e) => { q = e.target.value.trim().toLowerCase(); render(); });
		const openDialog = (id) => { const dlg = root.querySelector(id); if (dlg) dlg.showModal(); };
		document.getElementById('hinv-add-website')?.addEventListener('click', () => openDialog('#i-web'));
		document.getElementById('hinv-import')?.addEventListener('click', () => openDialog('#i-imp'));

		filters();
		render();
		if (open) root.querySelector('tr.d')?.scrollIntoView?.({ block: 'center' });
	}
};
