<?php declare(strict_types = 0);

namespace Modules\HostInventory\Lib;

/**
 * CSV in: the typed fields, by host name. The file the page exports is the file it takes back;
 * measured columns in it (OS, CPU, disks …) are ignored. Nothing is applied before the preview.
 */
class InvCsv {

	public const MAX_BYTES = 2097152;

	/**
	 * What a row's Host may say, for each inventory host: its technical name ({HOST.HOST}), its
	 * visible name ({HOST.NAME}) or its IP address. Technical names win, then visible names; an IP
	 * that two hosts share names neither.
	 */
	public static function index(array $inventoryHosts): array {
		$byHost = [];
		$byName = [];
		$byIp = [];
		foreach ($inventoryHosts as $h) {
			$entry = ['hostid' => (string) $h['hostid'], 'values' => $h['values']];
			$byHost[$h['host']] = $entry;
			$byName[$h['name']] = $byName[$h['name']] ?? $entry;
			if ($h['kind'] !== 'web' && $h['ip'] !== '') {
				$byIp[$h['ip']][] = $entry;
			}
		}
		$out = $byHost + $byName;
		foreach ($byIp as $ip => $list) {
			if (count($list) === 1 && !isset($out[$ip])) {
				$out[$ip] = $list[0];
			}
		}
		return $out;
	}

	/** Rows of a CSV text as arrays; the header row first. A leading BOM is dropped. */
	public static function rows(string $text): array {
		$text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
		$fh = fopen('php://temp', 'r+');
		fwrite($fh, $text);
		rewind($fh);
		$out = [];
		while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
			if ($r !== [null]) {
				$out[] = array_map(fn($c) => trim((string) $c), $r);
			}
		}
		fclose($fh);
		return $out;
	}

	/**
	 * What an import would change. `$hosts` is host name => ['hostid', 'values' => key => value].
	 * Columns are matched to fields by label or key (any case). Returns
	 * ['changes' => [[hostid, host, key, before, after]], 'errors' => [..], 'ignored' => [column names]].
	 * An empty cell leaves a value as it is; "-" clears it.
	 */
	public static function plan(string $text, array $fields, array $hosts, array $clients = []): array {
		$rows = self::rows($text);
		$out = ['changes' => [], 'errors' => [], 'ignored' => []];
		if (!$rows) {
			$out['errors'][] = _('The file is empty.');
			return $out;
		}
		$head = array_map('strtolower', array_shift($rows));
		$hostCol = array_search('host', $head, true);
		if ($hostCol === false) {
			$out['errors'][] = _('The file needs a "Host" column with the Zabbix host names.');
			return $out;
		}
		$cols = [];
		foreach ($head as $i => $h) {
			if ($i === $hostCol) {
				continue;
			}
			foreach ($fields as $f) {
				if ($h === strtolower($f['label']) || $h === $f['key']) {
					$cols[$i] = $f;
					continue 2;
				}
			}
			$out['ignored'][] = $h;
		}
		$n = 1;
		foreach ($rows as $r) {
			$n++;
			$name = $r[$hostCol] ?? '';
			if ($name === '') {
				continue;
			}
			if (!isset($hosts[$name])) {
				$out['errors'][] = _s('Line %1$s: no host "%2$s" in the inventory.', $n, $name);
				continue;
			}
			foreach ($cols as $i => $f) {
				$cell = $r[$i] ?? '';
				if ($cell === '') {
					continue;
				}
				$after = $cell === '-' ? '' : $cell;
				$after = Fields::normalize($f, $after);
				$err = Fields::valueError($f, $after, $clients);
				if ($err !== null) {
					$out['errors'][] = _s('Line %1$s (%2$s): %3$s', $n, $name, $err);
					continue;
				}
				$before = (string) ($hosts[$name]['values'][$f['key']] ?? '');
				if ($before !== $after) {
					$out['changes'][] = [$hosts[$name]['hostid'], $name, $f['key'], $before, $after];
				}
			}
		}
		return $out;
	}
}
