<?php declare(strict_types = 0);
/**
 * Host Inventory → Fields (Super admins): the typed fields — name, type, choices, required, in the
 * list, as a filter, order — plus extra end-of-support rows and the agent 2 that checks website
 * certificates. One form; the order of the rows is the order saved.
 *
 * @var CView $this
 * @var array $data
 */

$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$url = fn(string $action) => (new CUrl('zabbix.php'))->setArgument('action', $action)->getUrl();
$types = ['text' => _('Text'), 'choice' => _('Choice'), 'date' => _('Date'), 'number' => _('Number'), 'client' => _('Client (Cluster Management)')];

$html = [];
$html[] = '<div class="hinv-fl">';
if (!$data['store_ok']) {
	$html[] = '<div class="hinv-note hinv-bad">'.$e(_s('The data folder %1$s is missing or not writable: nothing can be saved. See the Host Inventory README.', $data['store_dir'])).'</div>';
}
if ($data['errors']) {
	$html[] = '<div class="hinv-note hinv-bad"><b>'.$e(_('Nothing was saved.')).'</b><ul>'.implode('', array_map(fn($x) => '<li>'.$e($x).'</li>', $data['errors'])).'</ul></div>';
}
if ($data['done']) {
	$html[] = '<div class="hinv-note hinv-good">'.implode(' ', array_map($e, $data['done'])).'</div>';
}
$html[] = '<form method="post" action="'.$e($url('hostinventory.fields.save')).'" id="hinv-fl-form">';
$html[] = '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.$e(CCsrfTokenHelper::get('hostinventory.fields.save')).'">';

$html[] = '<h4>'.$e(_('Fields')).'</h4><p class="sub">'.$e(_('Each host\'s value is kept in Zabbix as the host tag inv:<key>. Renaming changes the name only; the key stays. Measured columns (OS, CPU, memory, disks, services, monitored by, last seen) come from Zabbix and are always shown.')).'</p>';
$html[] = '<div class="wrap"><table class="fl"><thead><tr><th></th><th>'.$e(_('Name')).'</th><th>'.$e(_('Key')).'</th><th>'.$e(_('Type')).'</th><th>'.$e(_('Choices')).'</th>'
	.'<th>'.$e(_('Required')).'</th><th>'.$e(_('In list')).'</th><th>'.$e(_('Filter')).'</th><th></th></tr></thead><tbody id="hinv-fl-rows">';
$row = function (array $f, int $i) use ($e, $types): string {
	$sel = '';
	foreach ($types as $v => $l) {
		$sel .= '<option value="'.$v.'"'.($f['type'] === $v ? ' selected' : '').'>'.$e($l).'</option>';
	}
	$n = 'fields['.$i.']';
	$box = fn($k) => '<input type="checkbox" name="'.$n.'['.$k.']" value="1"'.($f[$k] ? ' checked' : '').' aria-label="'.$e($k).'">';
	return '<tr data-key="'.$e($f['key']).'"><td class="ord"><button type="button" class="btn-alt" data-up aria-label="'.$e(_('Up')).'">↑</button><button type="button" class="btn-alt" data-down aria-label="'.$e(_('Down')).'">↓</button></td>'
		.'<td><input type="text" name="'.$n.'[label]" value="'.$e($f['label']).'" maxlength="60" aria-label="'.$e(_('Name')).'"></td>'
		.'<td><code>inv:'.$e($f['key'] !== '' ? $f['key'] : '…').'</code><input type="hidden" name="'.$n.'[key]" value="'.$e($f['key']).'"></td>'
		.'<td><select name="'.$n.'[type]" aria-label="'.$e(_('Type')).'">'.$sel.'</select></td>'
		.'<td><input type="text" name="'.$n.'[choices]" value="'.$e(implode(', ', $f['choices'])).'" placeholder="'.$e(_('for Choice: a, b, c')).'" aria-label="'.$e(_('Choices')).'"></td>'
		.'<td>'.$box('required').'</td><td>'.$box('list').'</td><td>'.$box('filter').'</td>'
		.'<td><button type="button" class="btn-link hinv-danger" data-remove>'.$e(_('Remove')).'</button></td></tr>';
};
foreach ($data['fields'] as $i => $f) {
	$html[] = $row($f, $i);
}
$html[] = '</tbody></table></div>';
$html[] = '<template id="hinv-fl-new">'.$row(['key' => '', 'label' => '', 'type' => 'text', 'choices' => [], 'required' => false, 'list' => true, 'filter' => false], 999).'</template>';
$html[] = '<p><button type="button" class="btn-alt" id="hinv-fl-add">'.$e(_('Add field')).'</button></p>';
$html[] = '<div id="hinv-fl-purge"></div>';

$html[] = '<h4>'.$e(_('End of support')).'</h4><p class="sub">'.$e(_('A host is flagged when its OS name contains the text, with no digit straight after it ("Ubuntu 20.04" matches "Ubuntu 20.04.6 LTS"). Your rows are checked first.')).'</p>';
$html[] = '<div class="wrap"><table class="fl"><thead><tr><th>'.$e(_('OS name contains')).'</th><th>'.$e(_('End of support')).'</th><th></th></tr></thead><tbody id="hinv-eol-rows">';
$eolRow = fn(array $r, int $i) => '<tr><td><input type="text" name="eol['.$i.'][text]" value="'.$e($r[0]).'" aria-label="'.$e(_('OS name contains')).'"></td>'
	.'<td><input type="date" name="eol['.$i.'][date]" value="'.$e($r[1]).'" aria-label="'.$e(_('End of support')).'"></td>'
	.'<td><button type="button" class="btn-link hinv-danger" data-remove-eol>'.$e(_('Remove')).'</button></td></tr>';
foreach ($data['settings']['eol'] as $i => $r) {
	$html[] = $eolRow($r, $i);
}
$html[] = '</tbody></table></div>';
$html[] = '<template id="hinv-eol-new">'.$eolRow(['', ''], 999).'</template>';
$html[] = '<p><button type="button" class="btn-alt" id="hinv-eol-add">'.$e(_('Add a row')).'</button></p>';
$html[] = '<details><summary>'.$e(_s('Built in (%1$s rows)', count($data['builtin_eol']))).'</summary><p class="sub">'
	.$e(implode(' · ', array_map(fn($r) => $r[0].': '.$r[1], $data['builtin_eol']))).'</p></details>';

$html[] = '<h4>'.$e(_('Website certificates')).'</h4>';
$html[] = '<p class="sub">'.$e(_('An agent 2 that checks the certificate of each https website added here (template "Website certificate by Zabbix agent 2"). Empty: certificates are not checked.')).'</p>';
$html[] = '<p><input type="text" name="cert_agent" value="'.$e($data['settings']['cert_agent']).'" placeholder="10.0.0.5 or agent2.example.com:10050" aria-label="'.$e(_('Agent 2 address')).'" style="width: 320px"></p>';

$html[] = '<div class="foot"><a class="btn-alt" href="'.$e($url('hostinventory.list')).'">'.$e(_('Back to Host Inventory')).'</a><button type="submit">'.$e(_('Save')).'</button></div>';
$html[] = '</form></div>';

(new CHtmlPage())->setTitle(_('Host Inventory fields'))->addItem(new CObject(implode("\n", $html)))->show();
?>
<script>
	(function () {
		const form = document.getElementById('hinv-fl-form');
		const rows = document.getElementById('hinv-fl-rows');
		const purge = document.getElementById('hinv-fl-purge');
		// Names follow the order of the rows, so the order on screen is the order saved.
		const renumber = () => {
			[...rows.children].forEach((tr, i) => tr.querySelectorAll('[name^="fields["]').forEach((el) => { el.name = el.name.replace(/^fields\[\d+\]/, 'fields[' + i + ']'); }));
			[...document.getElementById('hinv-eol-rows').children].forEach((tr, i) => tr.querySelectorAll('[name^="eol["]').forEach((el) => { el.name = el.name.replace(/^eol\[\d+\]/, 'eol[' + i + ']'); }));
		};
		form.addEventListener('click', (e) => {
			const t = e.target, tr = t.closest('tr');
			if (t.matches('[data-up]') && tr.previousElementSibling) { tr.parentNode.insertBefore(tr, tr.previousElementSibling); renumber(); }
			if (t.matches('[data-down]') && tr.nextElementSibling) { tr.parentNode.insertBefore(tr.nextElementSibling, tr); renumber(); }
			if (t.matches('[data-remove]')) {
				const key = tr.dataset.key;
				tr.remove();
				renumber();
				// A saved field that goes: its values stay on the hosts unless this is ticked.
				if (key) {
					const label = document.createElement('label');
					label.className = 'purge';
					const box = document.createElement('input');
					box.type = 'checkbox'; box.name = 'purge[' + key + ']'; box.value = '1';
					label.append(box, ' Also remove the tag inv:' + key + ' from every host (a backup is taken first)');
					purge.append(label);
				}
			}
			if (t.matches('[data-remove-eol]')) { tr.remove(); renumber(); }
			if (t.id === 'hinv-fl-add') { rows.append(document.getElementById('hinv-fl-new').content.firstElementChild.cloneNode(true)); renumber(); rows.lastElementChild.querySelector('input[type=text]').focus(); }
			if (t.id === 'hinv-eol-add') { document.getElementById('hinv-eol-rows').append(document.getElementById('hinv-eol-new').content.firstElementChild.cloneNode(true)); renumber(); }
		});
	})();
</script>
<style>
	.hinv-fl { display: grid; gap: 10px; font-size: 13px; max-width: 1100px; }
	.hinv-fl h4 { margin: 12px 0 0; font-size: 14px; }
	.hinv-fl .sub { color: #6b7380; margin: 0; }
	html[color-scheme="dark"] .hinv-fl .sub { color: #9aa1ab; }
	.hinv-fl .wrap { overflow-x: auto; }
	.hinv-fl table.fl { border-collapse: collapse; min-width: 860px; }
	.hinv-fl table.fl th { text-align: left; font-weight: 500; font-size: 12px; padding: 6px 8px; opacity: .75; }
	.hinv-fl table.fl td { padding: 5px 8px; border-top: 1px solid rgba(127, 127, 127, .25); }
	.hinv-fl table.fl input[type=text] { width: 100%; min-width: 140px; }
	.hinv-fl .ord { white-space: nowrap; } .hinv-fl .ord button { padding: 0 7px; min-width: 0; }
	.hinv-fl .hinv-note { border: 1px solid rgba(127, 127, 127, .3); border-radius: 10px; padding: 10px 14px; }
	.hinv-fl .hinv-bad { border-color: rgba(192, 53, 44, .5); } .hinv-fl .hinv-good { border-color: rgba(31, 122, 54, .5); }
	.hinv-fl .hinv-danger { color: #c0352c; }
	.hinv-fl .purge { display: block; color: #9a6400; }
	.hinv-fl .foot { display: flex; gap: 10px; justify-content: flex-end; margin-top: 12px; }
</style>
