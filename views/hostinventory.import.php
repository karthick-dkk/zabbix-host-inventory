<?php declare(strict_types = 0);
/**
 * Import preview: every value the CSV would change, what it refused, the columns it ignored.
 *
 * @var CView $this
 * @var array $data
 */

$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$url = fn(string $action) => (new CUrl('zabbix.php'))->setArgument('action', $action)->getUrl();
$p = $data['plan'];

$html = ['<div class="hinv-im">'];
if ($p['errors']) {
	$html[] = '<div class="hinv-note hinv-bad"><b>'.$e(_n('%1$s line refused', '%1$s lines refused', count($p['errors']))).'</b><ul>'
		.implode('', array_map(fn($x) => '<li>'.$e($x).'</li>', array_slice($p['errors'], 0, 50))).'</ul>'
		.(count($p['errors']) > 50 ? '<p>'.$e(_s('… and %1$s more.', count($p['errors']) - 50)).'</p>' : '').'</div>';
}
if ($p['ignored']) {
	$html[] = '<p class="sub">'.$e(_s('Ignored columns (not fields): %1$s', implode(', ', $p['ignored']))).'</p>';
}
if (!$p['changes']) {
	$html[] = '<p>'.$e(_('Nothing would change.')).'</p>';
}
else {
	$html[] = '<p><b>'.$e(_n('%1$s value would change:', '%1$s values would change:', count($p['changes']))).'</b></p>';
	$html[] = '<div class="wrap"><table class="im"><thead><tr><th>'.$e(_('Host')).'</th><th>'.$e(_('Field')).'</th><th>'.$e(_('Now')).'</th><th>'.$e(_('After')).'</th></tr></thead><tbody>';
	foreach ($p['changes'] as [, $host, $key, $before, $after]) {
		$html[] = '<tr><td>'.$e($host).'</td><td>'.$e($key).'</td><td>'.($before === '' ? '<i>'.$e(_('empty')).'</i>' : $e($before)).'</td><td>'.($after === '' ? '<i>'.$e(_('cleared')).'</i>' : $e($after)).'</td></tr>';
	}
	$html[] = '</tbody></table></div>';
}
$html[] = '<div class="foot"><a class="btn-alt" href="'.$e($url('hostinventory.list')).'">'.$e(_('Cancel')).'</a>';
if ($p['changes'] && $data['store_ok']) {
	$html[] = '<form method="post" action="'.$e($url('hostinventory.import.apply')).'"><input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.$e(CCsrfTokenHelper::get('hostinventory.import.apply')).'">'
		.'<button type="submit">'.$e(_n('Apply %1$s change', 'Apply %1$s changes', count($p['changes']))).'</button></form>';
}
$html[] = '</div></div>';

(new CHtmlPage())->setTitle(_('Import inventory values'))->addItem(new CObject(implode("\n", $html)))->show();
?>
<style>
	.hinv-im { display: grid; gap: 10px; font-size: 13px; max-width: 1000px; }
	.hinv-im .sub { opacity: .75; margin: 0; }
	.hinv-im .wrap { overflow-x: auto; }
	.hinv-im table.im { border-collapse: collapse; min-width: 600px; }
	.hinv-im table.im th { text-align: left; font-weight: 500; font-size: 12px; padding: 6px 10px; opacity: .75; }
	.hinv-im table.im td { padding: 6px 10px; border-top: 1px solid rgba(127, 127, 127, .25); }
	.hinv-im .hinv-note { border: 1px solid rgba(192, 53, 44, .5); border-radius: 10px; padding: 10px 14px; }
	.hinv-im .foot { display: flex; gap: 10px; align-items: center; }
</style>
