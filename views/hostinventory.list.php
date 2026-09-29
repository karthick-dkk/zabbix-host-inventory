<?php declare(strict_types = 0);
/**
 * Host Inventory: every Linux server, Windows server and website the user may see — the typed
 * fields, and what Zabbix measures (OS, CPU, memory, disks, services). Drawn by
 * js/hostinventory.list.js from the data below; edits post to hostinventory.save.
 * Follows Zabbix's light or dark theme.
 *
 * @var CView $this
 * @var array $data
 */

$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$url = fn(string $action) => (new CUrl('zabbix.php'))->setArgument('action', $action)->getUrl();

$js = [
	'fields' => $data['fields'],
	'hosts' => $data['hosts'],
	'clients' => $data['clients'],
	'canEdit' => $data['can_edit'],
	'open' => $data['open'],
	'now' => $data['now'],
	'certAgent' => $data['cert_agent'],
	'proxies' => (object) $data['proxies'],
	'proxyGroups' => (object) $data['proxy_groups'],
	'save' => ['url' => $url('hostinventory.save'), 'token' => CCsrfTokenHelper::get('hostinventory.save')],
	'website' => ['url' => $url('hostinventory.website'), 'token' => CCsrfTokenHelper::get('hostinventory.website')],
	'import' => ['url' => $url('hostinventory.import'), 'token' => CCsrfTokenHelper::get('hostinventory.import')],
	'automatic' => ['url' => $url('hostinventory.automatic'), 'token' => CCsrfTokenHelper::get('hostinventory.automatic')],
	'hostUrl' => (new CUrl('zabbix.php'))->setArgument('action', 'latest.view')->setArgument('filter_set', '1')->getUrl()
];

$html = [];
$html[] = '<div class="hinv" id="hinv">';
if ($data['can_edit'] && !$data['store_ok']) {
	$html[] = '<div class="hinv-note hinv-bad">'.$e(_('The data folder is missing or not writable, so values cannot be saved (every change takes a backup first). See the Host Inventory README.')).'</div>';
}
if (!$data['can_edit']) {
	$html[] = '<div class="hinv-note">'.$e(_('Read only: Admins and Super admins change the values.')).'</div>';
}
$html[] = '<noscript><div class="hinv-note hinv-bad">'.$e(_('Host Inventory needs JavaScript.')).'</div></noscript>';
$html[] = '</div>';

$controls = (new CList());
$controls->addItem((new CButton('hinv-export-csv', _('Export CSV')))->addClass(ZBX_STYLE_BTN_ALT)->setAttribute('data-export', 'csv'));
$controls->addItem((new CButton('hinv-export-xlsx', _('Export Excel')))->addClass(ZBX_STYLE_BTN_ALT)->setAttribute('data-export', 'xlsx'));
if ($data['can_edit']) {
	$controls->addItem((new CButton('hinv-import', _('Import CSV')))->addClass(ZBX_STYLE_BTN_ALT));
	$controls->addItem(new CButton('hinv-add-website', _('Add website')));
}
if ($data['can_manage']) {
	$controls->addItem(new CRedirectButton(_('Fields'), $url('hostinventory.fields')));
}
(new CHtmlPage())->setTitle(_('Host Inventory'))->setControls((new CTag('nav', true, $controls))->setAttribute('aria-label', _('Content controls')))
	->addItem(new CObject(implode("\n", $html)))->show();
?>
<script><?php readfile(__DIR__.'/../assets/js/xlsx.js'); ?></script>
<script><?php readfile(__DIR__.'/../assets/js/export.js'); ?></script>
<script><?php readfile(__DIR__.'/js/hostinventory.list.js'); ?></script>
<script>
	window.HostInventory.init(document.getElementById('hinv'), <?= json_encode($js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>);
</script>
<style>
	.hinv { --c-card: #fff; --c-line: #e3e6ea; --c-muted: #6b7380; --c-accent: #0a66d6; --c-chip: #eef1f5; --c-soft: #f6f8fa;
		--c-good: #1f7a36; --c-good-s: #e4f4e8; --c-bad: #c0352c; --c-bad-s: #fbe7e6; --c-warn: #9a6400; --c-warn-s: #fdf1dc;
		--c-lin: #8a5a00; --c-lin-s: #fbf0db; --c-win: #0a66d6; --c-win-s: #e6f0fc; --c-web: #6a3fb5; --c-web-s: #efe8fb;
		display: grid; gap: 12px; font-size: 13px; }
	html[color-scheme="dark"] .hinv { --c-card: #25282c; --c-line: #383c42; --c-muted: #9aa1ab; --c-accent: #4d9bff; --c-chip: #31353b; --c-soft: #2c3035;
		--c-good: #5cc97a; --c-good-s: #173222; --c-bad: #ff7a70; --c-bad-s: #3a1c1b; --c-warn: #f0b04a; --c-warn-s: #3a2c14;
		--c-lin: #f0b04a; --c-lin-s: #3a2c14; --c-win: #6aaeff; --c-win-s: #1c2b40; --c-web: #b594f5; --c-web-s: #2c2340; }
	.hinv .hinv-note { border: 1px solid var(--c-line); background: var(--c-card); border-radius: 10px; padding: 10px 14px; }
	.hinv .hinv-bad { border-color: color-mix(in srgb, var(--c-bad) 50%, transparent); }
	.hinv .i-tools { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
	.hinv .i-tools input[type=search] { flex: 1; min-width: 240px; max-width: 420px; border-radius: 7px; padding: 6px 10px; height: auto; }
	.hinv .i-tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--c-line); flex-wrap: wrap; }
	.hinv .i-tabs button { border: 0; background: none; color: var(--c-muted); font: inherit; font-weight: 500; padding: 8px 12px; cursor: pointer; border-bottom: 2px solid transparent; }
	.hinv .i-tabs button[aria-selected=true] { color: inherit; border-bottom-color: var(--c-accent); }
	.hinv .i-tabs .n { font-variant-numeric: tabular-nums; background: var(--c-chip); border-radius: 999px; padding: 0 7px; margin-left: 4px; font-size: 11.5px; }
	.hinv .i-tabs .att .n { background: var(--c-warn-s); color: var(--c-warn); }
	.hinv .i-bulk { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding: 8px 12px; border-radius: 8px; background: color-mix(in srgb, var(--c-accent) 12%, var(--c-card)); }
	.hinv .i-bulk[hidden], .hinv .i-zinv[hidden] { display: none; }
	.hinv .i-zinv { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding: 8px 12px; border-radius: 8px; border: 1px solid color-mix(in srgb, var(--c-warn) 45%, transparent); background: var(--c-warn-s); }
	.hinv .i-wrap { overflow-x: auto; border: 1px solid var(--c-line); border-radius: 10px; background: var(--c-card); }
	.hinv table.i-t { width: 100%; border-collapse: collapse; min-width: 1600px; }
	.hinv .i-t th { text-align: left; font-weight: 500; font-size: 12px; color: var(--c-muted); padding: 9px 10px; border-bottom: 1px solid var(--c-line); white-space: nowrap; }
	.hinv .i-t td { padding: 9px 10px; border-top: 1px solid var(--c-line); vertical-align: top; }
	.hinv .i-t tr.h { cursor: pointer; }
	.hinv .i-t tr.h:hover td { background: var(--c-soft); }
	.hinv .nm { font-weight: 600; display: flex; gap: 6px; align-items: center; }
	.hinv .sub { color: var(--c-muted); font-size: 12px; }
	.hinv .num { font-variant-numeric: tabular-nums; white-space: nowrap; }
	.hinv .kind { font-size: 10.5px; font-weight: 700; letter-spacing: .03em; border-radius: 4px; padding: 1px 6px; text-transform: uppercase; }
	.hinv .k-linux { background: var(--c-lin-s); color: var(--c-lin); } .hinv .k-windows { background: var(--c-win-s); color: var(--c-win); } .hinv .k-web { background: var(--c-web-s); color: var(--c-web); }
	.hinv .chip { display: inline-block; font-size: 11.5px; font-weight: 600; border-radius: 5px; padding: 1px 7px; background: var(--c-chip); white-space: nowrap; }
	.hinv .t-CI { background: var(--c-web-s); color: var(--c-web); } .hinv .t-DI { background: var(--c-win-s); color: var(--c-win); } .hinv .t-On-Prem { background: var(--c-lin-s); color: var(--c-lin); }
	.hinv .miss, .hinv .warn { color: var(--c-warn); font-size: 12px; font-weight: 600; }
	.hinv .bad { color: var(--c-bad); font-weight: 600; }
	.hinv .flag { display: inline-block; margin-top: 3px; font-size: 11px; font-weight: 600; color: var(--c-bad); background: var(--c-bad-s); border-radius: 5px; padding: 0 6px; white-space: nowrap; }
	.hinv .dflt { color: var(--c-muted); font-style: italic; }
	.hinv .disks { display: grid; gap: 3px; min-width: 160px; }
	.hinv .disk { display: grid; grid-template-columns: 62px 1fr 38px; gap: 6px; align-items: center; font-size: 11.5px; }
	.hinv .disk .m { font-family: monospace; color: var(--c-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
	.hinv .meter { height: 6px; background: var(--c-chip); border-radius: 3px; overflow: hidden; }
	.hinv .meter i { display: block; height: 100%; background: var(--c-good); } .hinv .meter i.w { background: var(--c-warn); } .hinv .meter i.b { background: var(--c-bad); }
	.hinv .disk .p { text-align: right; font-variant-numeric: tabular-nums; color: var(--c-muted); }
	.hinv .fill { font-size: 12px; font-variant-numeric: tabular-nums; font-weight: 600; border-radius: 5px; padding: 1px 7px; }
	.hinv .fill.ok { color: var(--c-good); background: var(--c-good-s); } .hinv .fill.part { color: var(--c-warn); background: var(--c-warn-s); }
	.hinv tr.d td { background: var(--c-soft); padding: 14px 16px; }
	.hinv .det { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; }
	@media (max-width: 1100px) { .hinv .det { grid-template-columns: 1fr; } }
	.hinv .det h4 { margin: 0 0 8px; font-size: 11px; color: var(--c-muted); text-transform: uppercase; letter-spacing: .06em; }
	.hinv .kv { display: grid; grid-template-columns: 130px minmax(0, 1fr); gap: 6px 10px; align-items: center; }
	.hinv .kv > span:nth-child(odd) { color: var(--c-muted); }
	.hinv .kv input, .hinv .kv select { width: 100%; max-width: 260px; }
	.hinv .src { font-size: 10.5px; color: var(--c-muted); margin-left: 5px; font-family: monospace; }
	.hinv .svcs { display: flex; flex-wrap: wrap; gap: 5px; }
	.hinv .svcs span { font-size: 11.5px; border-radius: 5px; padding: 1px 7px; background: var(--c-good-s); color: var(--c-good); font-family: monospace; }
	.hinv .svcs span.stopped { background: var(--c-bad-s); color: var(--c-bad); } .hinv .svcs span.unknown { background: var(--c-chip); color: var(--c-muted); }
	.hinv .grps { display: flex; flex-wrap: wrap; gap: 3px; max-width: 220px; }
	.hinv .grps span:not(.sub) { font-size: 11px; background: var(--c-chip); border-radius: 4px; padding: 0 6px; white-space: nowrap; }
	.hinv .why { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 3px; }
	.hinv .why span { font-size: 11px; color: var(--c-warn); background: var(--c-warn-s); border-radius: 4px; padding: 0 6px; }
	.hinv .empty { padding: 26px; text-align: center; color: var(--c-muted); }
	.hinv dialog { border: 1px solid var(--c-line); border-radius: 12px; background: var(--c-card); color: inherit; padding: 18px 20px; width: min(520px, 92vw); }
	.hinv dialog::backdrop { background: rgba(0, 0, 0, .35); }
	.hinv dialog h3 { margin: 0 0 12px; font-size: 15px; }
	.hinv dialog .kv input, .hinv dialog .kv select { max-width: none; }
	.hinv .dfoot { display: flex; gap: 10px; justify-content: flex-end; margin-top: 14px; }
	.hinv .acts { display: flex; gap: 10px; margin-top: 12px; align-items: center; }
</style>
