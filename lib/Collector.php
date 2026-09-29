<?php declare(strict_types = 0);

namespace Modules\HostInventory\Lib;

use API;

/**
 * Every Linux server, Windows server and website Zabbix has, with what its items say — read with
 * the signed-in user's own permissions, so each person sees the hosts they may see. Nothing is
 * created or changed here.
 */
class Collector {

	/** Items read by exact key. */
	public const KEYS = ['system.hostname', 'system.sw.os', 'system.sw.os[name]', 'system.uname', 'system.sw.arch', 'system.uptime', 'agent.version',
		'agent.ping', 'system.cpu.num', 'vm.memory.size[total]', 'cert.not_after'];
	/** Items read by the start of their key. */
	public const PREFIXES = ['vfs.fs.dependent.size[', 'vfs.fs.size[', 'service.info[', 'systemd.service.active_state[', 'systemd.unit.info[',
		'proc.num[', 'web.test.rspcode[', 'web.test.time[', 'web.test.fail[', 'web.test.error['];
	public const AGENT_STATUS = '/^[a-z0-9_-]+\.agent\.(status|enabled)$/';

	/**
	 * The inventory: hostid => [hostid, host, name, kind, ip, monitored, values (inv: tags),
	 * defaults (from Cluster Management), os, cpu, mem, disks, services, web, seen, stale, eol].
	 */
	public static function hosts(int $now, array $eolRows): array {
		$tplIds = [];
		foreach (array_keys(Measure::TEMPLATE_WORDS) as $word) {
			foreach (API::Template()->get(['output' => ['templateid'], 'search' => ['host' => $word]]) ?: [] as $t) {
				$tplIds[] = $t['templateid'];
			}
		}
		// Websites: hosts with a web check, and the first URL each checks.
		$urls = [];
		foreach (API::HttpTest()->get(['output' => ['hostid'], 'selectSteps' => ['url', 'no']]) ?: [] as $t) {
			$steps = $t['steps'] ?? [];
			usort($steps, fn($a, $b) => (int) $a['no'] <=> (int) $b['no']);
			$urls[$t['hostid']] = $urls[$t['hostid']] ?? (string) ($steps[0]['url'] ?? '');
		}
		$ids = array_keys($urls);
		if ($tplIds) {
			$ids = array_merge($ids, array_column(API::Host()->get(['output' => ['hostid'], 'templateids' => $tplIds]) ?: [], 'hostid'));
		}
		$ids = array_values(array_unique($ids));
		if (!$ids) {
			return [];
		}
		$hosts = API::Host()->get(['output' => ['hostid', 'host', 'name', 'status', 'monitored_by', 'proxyid', 'proxy_groupid', 'inventory_mode'], 'hostids' => $ids,
			'selectInventory' => array_keys(Measure::ZABBIX_INVENTORY),
			'selectTags' => ['tag', 'value'], 'selectParentTemplates' => ['host'], 'selectInterfaces' => ['type', 'ip', 'dns', 'useip', 'main', 'available'],
			'selectHttpTests' => ['name'], 'selectHostGroups' => ['name'], 'preservekeys' => true]) ?: [];

		$items = [];
		$read = function (array $params) use (&$items, $hosts) {
			// webitems: web check items (web.test.*) are left out of item.get without it.
			foreach (API::Item()->get($params + ['output' => ['hostid', 'key_', 'lastvalue', 'lastclock', 'state'], 'hostids' => array_keys($hosts), 'webitems' => true]) ?: [] as $it) {
				$items[$it['hostid']][$it['key_']] = ['value' => (string) $it['lastvalue'], 'clock' => (int) $it['lastclock'],
					'ok' => (int) $it['state'] === 0 && (int) $it['lastclock'] > 0];
			}
		};
		$read(['filter' => ['key_' => self::KEYS]]);
		$read(['search' => ['key_' => array_merge(self::PREFIXES, ['.agent.status', '.agent.enabled'])], 'searchByAny' => true, 'startSearch' => false]);

		$proxies = array_column(API::Proxy()->get(['output' => ['proxyid', 'name']]) ?: [], 'name', 'proxyid');
		$groups = array_column(API::ProxyGroup()->get(['output' => ['proxy_groupid', 'name']]) ?: [], 'name', 'proxy_groupid');
		$clientTypes = self::clientTypes();

		$out = [];
		foreach ($hosts as $id => $h) {
			$its = array_filter($items[$id] ?? [], fn($k) => in_array($k, self::KEYS, true) || preg_match(self::AGENT_STATUS, $k)
				|| array_filter(self::PREFIXES, fn($p) => strpos($k, $p) === 0), ARRAY_FILTER_USE_KEY);
			$kind = Measure::kind(array_column($h['parentTemplates'], 'host'), !empty($h['httpTests']));
			if ($kind === null) {
				continue;
			}
			$tags = $h['tags'] ?? [];
			$client = '';
			foreach ($tags as $t) {
				if ($t['tag'] === 'evp-client') {
					$client = (string) $t['value'];
				}
			}
			$os = $kind === 'web' ? null : Measure::os($its, $kind);
			$seen = Measure::lastSeen($its);
			$url = $urls[$id] ?? '';
			$out[$id] = [
				'hostid' => (string) $id,
				'host' => $h['host'],
				'name' => $h['name'],
				'kind' => $kind,
				'enabled' => (int) $h['status'] === 0,
				'ip' => $kind === 'web' ? $url : Measure::address($h['host'], $h['interfaces'] ?? []),
				'groups' => self::groupNames($h['hostgroups'] ?? []),
				'monitored' => (int) $h['monitored_by'] === 1 ? ($proxies[$h['proxyid']] ?? 'proxy') : ((int) $h['monitored_by'] === 2 ? 'group: '.($groups[$h['proxy_groupid']] ?? '?') : 'Zabbix server'),
				'values' => Fields::valuesOf($tags),
				'defaults' => array_filter(['client' => $client, 'type' => $client !== '' ? ($clientTypes[$client] ?? '') : '']),
				'hostname' => $kind === 'web' ? null : Measure::hostname($its),
				'os' => $os,
				'zabbix_inventory' => Measure::zabbixInventory($h['inventory_mode'], $h['inventory'] ?? []),
				'size' => $kind === 'web' ? ['cpu' => null, 'mem' => null] : Measure::size($its),
				'disks' => $kind === 'web' ? [] : Measure::disks($its),
				'services' => $kind === 'web' ? [] : Measure::services($its),
				'web' => $kind === 'web' ? Measure::web($its, $now) : null,
				'seen' => $seen,
				'stale' => $h['status'] == 0 && Measure::stale($seen, $now),
				'eol' => $os ? Support::endOf($os['name'], $eolRows) : null
			];
		}
		uasort($out, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
		return $out;
	}

	/** Host group names, sorted. */
	public static function groupNames(array $groups): array {
		$names = array_values(array_unique(array_map(fn($g) => (string) $g['name'], $groups)));
		sort($names, SORT_NATURAL | SORT_FLAG_CASE);
		return $names;
	}

	/**
	 * Client name => its type, when ElasticVue Pro's Cluster Management runs on this Zabbix: its
	 * master hosts (tag evp-kind=master, macros {$GRP.CLIENT} and {$EVP.CLIENT.TYPE}). Empty
	 * otherwise — clients are then typed freely.
	 */
	public static function clientTypes(): array {
		$masters = API::Host()->get(['output' => ['hostid'], 'tags' => [['tag' => 'evp-kind', 'value' => 'master', 'operator' => TAG_OPERATOR_EQUAL]],
			'selectMacros' => ['macro', 'value']]) ?: [];
		$out = [];
		foreach ($masters as $m) {
			$macros = array_column($m['macros'] ?? [], 'value', 'macro');
			if (($macros['{$GRP.CLIENT}'] ?? '') !== '') {
				$out[$macros['{$GRP.CLIENT}']] = $macros['{$EVP.CLIENT.TYPE}'] ?? 'On-Prem';
			}
		}
		ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
		return $out;
	}
}
