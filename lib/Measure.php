<?php declare(strict_types = 0);

namespace Modules\HostInventory\Lib;

/**
 * What a host is and has, read from the items its templates already collect — no API calls here,
 * so every rule is tested. Unknown stays unknown: a value Zabbix does not have is null, never 0.
 *
 * Items come in as key => ['value' => string, 'clock' => int, 'ok' => bool] (ok = supported and
 * measured at least once).
 */
class Measure {

	/** Templates that make a host a Linux or a Windows server. */
	public const TEMPLATE_WORDS = ['Linux by Zabbix agent' => 'linux', 'Windows by Zabbix agent' => 'windows'];

	/** File systems that are not disks: container bind files, kernel and runtime mounts. */
	public const NOT_DISKS = '#^/(etc|proc|sys|dev|run|snap|var/lib/docker|var/lib/kubelet|boot/efi)(/|$)#';

	/**
	 * Where an agent running in a container sees the machine's own file systems (the host's / mounted
	 * at /hostfs, /rootfs or /host). Shown as the host names them: /hostfs/boot → /boot.
	 */
	public const HOST_ROOTS = ['/hostfs', '/rootfs', '/host'];

	/** A file system's name as the machine knows it, without a containerised agent's host-root prefix. */
	public static function mount(string $fs): string {
		foreach (self::HOST_ROOTS as $root) {
			if ($fs === $root) {
				return '/';
			}
			if (strpos($fs, $root.'/') === 0) {
				return substr($fs, strlen($root));
			}
		}
		return $fs;
	}

	/** No value newer than this: "not reporting". */
	public const STALE = 86400;

	/** linux, windows or web, from the host's templates and whether it has a web check; null: none of these. */
	public static function kind(array $templateNames, bool $hasWebCheck): ?string {
		foreach ($templateNames as $t) {
			foreach (self::TEMPLATE_WORDS as $word => $kind) {
				if (stripos($t, $word) !== false) {
					return $kind;
				}
			}
		}
		return $hasWebCheck ? 'web' : null;
	}

	private static function val(array $items, string $key): ?string {
		return isset($items[$key]) && $items[$key]['ok'] && $items[$key]['value'] !== '' ? (string) $items[$key]['value'] : null;
	}

	/**
	 * The OS: name (null when the distribution is not reported), kernel or build, architecture,
	 * seconds up, agent version, and `hint` naming what would tell the distribution.
	 */
	public static function os(array $items, string $kind): array {
		$named = self::val($items, 'system.sw.os[name]');
		$sw = self::val($items, 'system.sw.os');
		$uname = (string) self::val($items, 'system.uname');
		$out = ['name' => null, 'kernel' => null, 'arch' => self::val($items, 'system.sw.arch'),
			'uptime' => self::val($items, 'system.uptime') !== null ? (int) self::val($items, 'system.uptime') : null,
			'agent' => self::val($items, 'agent.version'), 'hint' => ''];
		if ($kind === 'windows') {
			$text = trim(preg_replace('/^Microsoft\s+/i', '', (string) ($named ?? $sw)));
			$out['name'] = $text !== '' ? trim(preg_replace('/\s+(Build|10\.0\.)\s*\d.*$/i', '', $text)) : null;
			if (preg_match('/Build\s*(\d+(?:\.\d+)?)/i', $text.' '.$uname, $m) || preg_match('/10\.0\.(\d+)/', $text.' '.$uname, $m)) {
				$out['kernel'] = 'build '.$m[1];
			}
			return $out;
		}
		// Linux: system.sw.os is /proc/version ("Linux version 6.8.0-139-generic (…)") on most
		// agents — the kernel, not the distribution. system.sw.os[name] is the distribution.
		if (preg_match('/^Linux version (\S+)/', (string) $sw, $m)) {
			$out['kernel'] = $m[1];
		}
		elseif ($uname !== '' && preg_match('/^\S+\s+\S+\s+(\S+)/', $uname, $m)) {
			$out['kernel'] = $m[1];
		}
		if ($named !== null) {
			$out['name'] = $named;
		}
		elseif ($sw !== null && strpos($sw, 'Linux version') !== 0) {
			$out['name'] = $sw;
		}
		else {
			$k = (string) $out['kernel'];
			if (preg_match('/\.el(\d+)(?:_(\d+))?/', $k, $m)) {
				$out['name'] = 'RHEL-family '.$m[1].(isset($m[2]) ? '.'.$m[2] : '');
			}
			elseif (preg_match('/\.amzn2\./', $k)) {
				$out['name'] = 'Amazon Linux 2';
			}
			elseif (preg_match('/\.amzn2023\./', $k)) {
				$out['name'] = 'Amazon Linux 2023';
			}
			elseif (preg_match('/-Ubuntu/i', $uname)) {
				$out['name'] = 'Ubuntu';
				$out['hint'] = 'version not reported: add the item system.sw.os[name]';
			}
			else {
				$out['hint'] = 'distribution not reported: add the item system.sw.os[name]';
			}
		}
		return $out;
	}

	/**
	 * A host's IP address, as {HOST.IP} gives it: the main interface's IP (even when Zabbix connects
	 * by DNS). With no IP there, the technical host name ({HOST.HOST}) when it is an IP address —
	 * many hosts are named by their IP — else the interface's DNS name. '' when none.
	 */
	public static function address(string $hostName, array $interfaces): string {
		$main = null;
		foreach ($interfaces as $i) {
			if ((int) $i['main'] === 1 && ($main === null || (int) $i['type'] === 1)) {
				$main = $i;
			}
		}
		if ($main !== null && trim((string) $main['ip']) !== '') {
			return trim((string) $main['ip']);
		}
		if (filter_var($hostName, FILTER_VALIDATE_IP) !== false) {
			return $hostName;
		}
		return $main !== null ? trim((string) $main['dns']) : '';
	}

	/** The host name the OS reports (system.hostname); null when not measured. */
	public static function hostname(array $items): ?string {
		return self::val($items, 'system.hostname');
	}

	/** Zabbix host inventory fields shown with each host: field => label. */
	public const ZABBIX_INVENTORY = ['name' => 'Name', 'alias' => 'Alias', 'os' => 'OS', 'os_full' => 'OS (full details)', 'hw_arch' => 'HW architecture',
		'chassis' => 'Chassis', 'model' => 'Model', 'hardware' => 'Hardware', 'software' => 'Software'];

	/**
	 * A host's Zabbix inventory: its mode (automatic: items fill the fields linked to them;
	 * manual: typed in Zabbix; disabled: none kept) and the fields that hold a value.
	 */
	public static function zabbixInventory($mode, $inventory): array {
		$m = [-1 => 'disabled', 0 => 'manual', 1 => 'automatic'][(int) $mode] ?? 'disabled';
		$filled = [];
		foreach (self::ZABBIX_INVENTORY as $field => $label) {
			$v = is_array($inventory) ? trim((string) ($inventory[$field] ?? '')) : '';
			if ($v !== '') {
				$filled[$label] = mb_substr($v, 0, 200);
			}
		}
		return ['mode' => $m, 'filled' => $filled];
	}

	/** CPU cores and memory in bytes; null each when not measured. */
	public static function size(array $items): array {
		$cpu = self::val($items, 'system.cpu.num');
		$mem = self::val($items, 'vm.memory.size[total]');
		return ['cpu' => $cpu !== null && (int) $cpu > 0 ? (int) $cpu : null, 'mem' => $mem !== null && (float) $mem > 0 ? (float) $mem : null];
	}

	/** The name inside a key's first parameter: service.info["W32Time",state] → W32Time. */
	public static function param(string $key, int $n = 0): ?string {
		if (!preg_match('/^[^\[]+\[(.*)\]$/', $key, $m)) {
			return null;
		}
		$params = str_getcsv($m[1], ',', '"', '');
		return isset($params[$n]) ? trim($params[$n]) : null;
	}

	/**
	 * The disks: [mount, total bytes, % used or null], the system disk (/ or C:) first. From
	 * vfs.fs.dependent.size[…] (Zabbix 7 templates) or vfs.fs.size[…].
	 */
	public static function disks(array $items): array {
		$by = [];
		foreach ($items as $key => $it) {
			if (!preg_match('/^vfs\.fs\.(?:dependent\.)?size\[/', $key) || !$it['ok']) {
				continue;
			}
			$fs = self::param($key, 0);
			$what = self::param($key, 1);
			$fs = $fs === null ? null : self::mount($fs);
			if ($fs === null || $fs === '' || preg_match(self::NOT_DISKS, $fs) || !in_array($what, ['total', 'pused'], true)) {
				continue;
			}
			$by[$fs][$what] = (float) $it['value'];
		}
		$out = [];
		foreach ($by as $fs => $v) {
			if (($v['total'] ?? 0) > 0) {
				$out[] = [$fs, $v['total'], isset($v['pused']) ? round($v['pused'], 1) : null];
			}
		}
		$sys = fn($fs) => in_array(strtoupper($fs), ['/', 'C:'], true) ? 0 : 1;
		usort($out, fn($a, $b) => [$sys($a[0]), strnatcasecmp($a[0], $b[0])] <=> [$sys($b[0]), 0]);
		return $out;
	}

	/**
	 * Services: [name, running|stopped|unknown]. Windows services (service.info[…,state]),
	 * systemd units (agent 2), the security agents the SISA template watches (<name>.agent.status,
	 * 1 running / 0 down; not listed when <name>.agent.enabled says not installed or the item is
	 * unsupported), and proc.num[<name>] checks.
	 */
	public static function services(array $items): array {
		$out = [];
		foreach ($items as $key => $it) {
			if (!$it['ok']) {
				continue;
			}
			$v = (string) $it['value'];
			if (strpos($key, 'service.info[') === 0 && in_array(strtolower((string) self::param($key, 1)), ['state', ''], true)) {
				$out[(string) self::param($key, 0)] = $v === '0' ? 'running' : (in_array($v, ['7', '255'], true) ? 'unknown' : 'stopped');
			}
			elseif (strpos($key, 'systemd.service.active_state[') === 0) {
				$out[(string) self::param($key, 0)] = $v === '1' ? 'running' : (in_array($v, ['3', '4'], true) ? 'stopped' : 'unknown');
			}
			elseif (strpos($key, 'systemd.unit.info[') === 0 && strcasecmp((string) self::param($key, 1), 'ActiveState') === 0) {
				$out[(string) self::param($key, 0)] = $v === 'active' ? 'running' : (in_array($v, ['inactive', 'failed'], true) ? 'stopped' : 'unknown');
			}
			elseif (preg_match('/^([a-z0-9_-]+)\.agent\.status$/', $key, $m)) {
				$enabled = $items[$m[1].'.agent.enabled'] ?? null;
				if ($enabled !== null && $enabled['ok'] && (string) $enabled['value'] === '0') {
					continue;
				}
				$out[$m[1]] = $v === '1' ? 'running' : ($v === '0' ? 'stopped' : 'unknown');
			}
			elseif (strpos($key, 'proc.num[') === 0 && (string) self::param($key, 0) !== '') {
				$out[(string) self::param($key, 0)] = (int) $v > 0 ? 'running' : 'stopped';
			}
		}
		ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
		return array_map(fn($n, $s) => [$n, $s], array_keys($out), array_values($out));
	}

	/** A website: HTTP code, response ms, failed step (0 = none), error, certificate expiry. */
	public static function web(array $items, int $now): array {
		$out = ['code' => null, 'ms' => null, 'failed' => null, 'error' => '', 'cert_days' => null];
		foreach ($items as $key => $it) {
			if (!$it['ok']) {
				continue;
			}
			if (strpos($key, 'web.test.rspcode[') === 0 && $out['code'] === null) {
				$out['code'] = (int) $it['value'];
			}
			elseif (strpos($key, 'web.test.time[') === 0 && $out['ms'] === null && self::param($key, 2) === 'resp') {
				$out['ms'] = (int) round((float) $it['value'] * 1000);
			}
			elseif (strpos($key, 'web.test.fail[') === 0) {
				$out['failed'] = (int) $it['value'];
			}
			elseif (strpos($key, 'web.test.error[') === 0) {
				$out['error'] = (string) $it['value'];
			}
			elseif ($key === 'cert.not_after' && (int) $it['value'] > 0) {
				$out['cert_days'] = (int) floor(((int) $it['value'] - $now) / 86400);
			}
		}
		return $out;
	}

	/** Newest value from the host (unix time), or null when it never sent one. */
	public static function lastSeen(array $items): ?int {
		$max = 0;
		foreach ($items as $it) {
			$max = max($max, (int) $it['clock']);
		}
		return $max > 0 ? $max : null;
	}

	public static function stale(?int $lastSeen, int $now): bool {
		return $lastSeen === null || $now - $lastSeen > self::STALE;
	}
}
