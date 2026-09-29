<?php declare(strict_types = 0);

namespace Modules\HostInventory\Lib;

use API;
use Exception;

/**
 * The only writes Host Inventory makes: a host's inv: tags (every other tag kept), and a new host
 * for a website. Each change of tags is preceded by a backup of the tags it touches, newest ten
 * kept; Zabbix checks the signed-in user may write each host.
 */
class Writer {

	public const BACKUPS = 'inventory-backups';
	public const KEEP = 10;
	public const WEB_GROUP = 'Websites';
	public const MADE_TAG = 'managed-by';
	public const MADE_VALUE = 'zabbix-host-inventory';
	public const CERT_TEMPLATE = 'Website certificate by Zabbix agent 2';

	private array $done = [];

	public function done(): array {
		return $this->done;
	}

	/**
	 * Set typed values: hostid => [key => value ('' clears)]. Returns the backup's name.
	 * Values are checked by the caller (Fields::valueError).
	 */
	public function setValues(array $changes, string $who, string $why): string {
		if (!$changes) {
			return '';
		}
		$hosts = API::Host()->get(['output' => ['hostid', 'name'], 'hostids' => array_map('strval', array_keys($changes)), 'selectTags' => ['tag', 'value'], 'editable' => true,
			'preservekeys' => true]) ?: [];
		$missing = array_diff(array_map('strval', array_keys($changes)), array_map('strval', array_keys($hosts)));
		if ($missing) {
			throw new Exception(_n('You may not change %1$s of these hosts.', 'You may not change %1$s of these hosts.', count($missing)));
		}
		$backup = $this->backup(array_map(fn($h) => ['name' => $h['name'], 'inv' => Fields::valuesOf($h['tags'])], $hosts), $who, $why);
		foreach ($changes as $hostid => $values) {
			$h = $hosts[$hostid];
			$tags = Fields::applyTo($h['tags'], $values);
			if ($tags == array_map(fn($t) => ['tag' => (string) $t['tag'], 'value' => (string) $t['value']], $h['tags'])) {
				continue;
			}
			self::api(API::Host()->update(['hostid' => (string) $hostid, 'tags' => $tags]), _s('update "%1$s"', $h['name']));
			$this->done[] = $h['name'];
		}
		return $backup;
	}

	/** Remove a field's tag from every host that has it (a field removed with its values). */
	public function purge(string $key, string $who): int {
		$hosts = API::Host()->get(['output' => ['hostid'], 'tags' => [['tag' => Fields::TAG_PREFIX.$key, 'operator' => 4]], 'editable' => true, 'preservekeys' => true]) ?: [];
		if (!$hosts) {
			return 0;
		}
		$this->setValues(array_map(fn() => [$key => ''], $hosts), $who, 'remove field '.$key);
		return count($this->done);
	}

	/** A copy of the inv: tags about to change. */
	private function backup(array $hosts, string $who, string $why): string {
		$name = 'inventory-'.date('Ymd-His').'-'.substr(bin2hex(random_bytes(3)), 0, 4);
		Store::write(self::BACKUPS.'/'.$name.'.json', ['at' => time(), 'by' => $who, 'why' => $why, 'hosts' => $hosts]);
		foreach (array_slice(Store::listing(self::BACKUPS), self::KEEP) as $old) {
			Store::delete($old);
		}
		return $name;
	}

	/** The host name for a website: its name as given, or the URL's host. */
	public static function websiteName(string $url, string $name): string {
		$n = trim($name) !== '' ? trim($name) : (string) parse_url($url, PHP_URL_HOST);
		return substr(preg_replace('/[^A-Za-z0-9._\- ]+/', '-', $n), 0, 128);
	}

	/** Why a website cannot be added; null when it can. */
	public static function websiteError(string $url, string $name): ?string {
		if (!preg_match('#^https?://[^\s/$.?\#][^\s]*$#i', $url) || parse_url($url, PHP_URL_HOST) === null) {
			return _('Give the full address, starting with https:// or http://.');
		}
		return self::websiteName($url, $name) === '' ? _('Give the website a name.') : null;
	}

	/**
	 * A host for a website: a web check of the URL every minute (a HIGH problem when it fails),
	 * its typed values as inv: tags, in the Websites group (and the client's group when there is
	 * one); with the certificate check when an agent 2 is set to run it.
	 * `$monitor` is ['server'] | ['proxy', id] | ['group', id].
	 */
	public function addWebsite(string $url, string $name, array $values, array $monitor, string $certAgent): string {
		$host = self::websiteName($url, $name);
		if (API::Host()->get(['output' => ['hostid'], 'filter' => ['host' => $host]])) {
			throw new Exception(_s('A host named "%1$s" is already in Zabbix.', $host));
		}
		$groups = [['groupid' => $this->groupId(self::WEB_GROUP)]];
		$client = (string) ($values['client'] ?? '');
		if ($client !== '' && ($g = API::HostGroup()->get(['output' => ['groupid'], 'filter' => ['name' => $client]]))) {
			$groups[] = ['groupid' => $g[0]['groupid']];
		}
		$tags = Fields::applyTo([['tag' => self::MADE_TAG, 'value' => self::MADE_VALUE]], $values);
		$params = ['host' => $host, 'groups' => $groups, 'tags' => $tags];
		if ($monitor[0] === 'proxy') {
			$params += ['monitored_by' => ZBX_MONITORED_BY_PROXY, 'proxyid' => $monitor[1]];
		}
		elseif ($monitor[0] === 'group') {
			$params += ['monitored_by' => ZBX_MONITORED_BY_PROXY_GROUP, 'proxy_groupid' => $monitor[1]];
		}
		$https = stripos($url, 'https://') === 0;
		if ($https && $certAgent !== '') {
			[$addr, $port] = array_pad(explode(':', $certAgent, 2), 2, '10050');
			$ip = filter_var($addr, FILTER_VALIDATE_IP) !== false;
			$tpl = API::Template()->get(['output' => ['templateid'], 'filter' => ['host' => self::CERT_TEMPLATE]]);
			if ($tpl) {
				$params['interfaces'] = [['type' => 1, 'main' => 1, 'useip' => $ip ? 1 : 0, 'ip' => $ip ? $addr : '', 'dns' => $ip ? '' : $addr, 'port' => $port]];
				$params['templates'] = [['templateid' => $tpl[0]['templateid']]];
				$params['macros'] = [
					['macro' => '{$CERT.WEBSITE.HOSTNAME}', 'value' => (string) parse_url($url, PHP_URL_HOST)],
					['macro' => '{$CERT.WEBSITE.PORT}', 'value' => (string) (parse_url($url, PHP_URL_PORT) ?: 443)]
				];
			}
		}
		$hostid = self::api(API::Host()->create($params), _s('create "%1$s"', $host))['hostids'][0];
		self::api(API::HttpTest()->create([
			'name' => 'Website', 'hostid' => $hostid, 'delay' => '1m', 'retries' => 2,
			'steps' => [['name' => 'Open', 'url' => $url, 'no' => 1, 'status_codes' => '200-399', 'follow_redirects' => 1, 'timeout' => '15s']]
		]), _s('add the web check to "%1$s"', $host));
		self::api(API::Trigger()->create([
			'description' => _s('%1$s is not answering', $host),
			'expression' => 'last(/'.$host.'/web.test.fail[Website])<>0',
			'priority' => TRIGGER_SEVERITY_HIGH,
			'comments' => 'The web check of '.$url.' failed. Latest data shows which step and why.',
			'tags' => [['tag' => 'scope', 'value' => 'availability']]
		]), _s('add the "not answering" problem to "%1$s"', $host));
		$this->done[] = _s('Created "%1$s", checking %2$s every minute.', $host, $url);
		if ($https && !isset($params['templates'])) {
			$this->done[] = $certAgent === '' ? _('Its certificate is not checked: set the agent 2 that checks certificates on the Fields page.')
				: _s('Its certificate is not checked: the template "%1$s" is not in Zabbix.', self::CERT_TEMPLATE);
		}
		return (string) $hostid;
	}

	private function groupId(string $name): string {
		$g = API::HostGroup()->get(['output' => ['groupid'], 'filter' => ['name' => $name]]);
		return $g ? $g[0]['groupid'] : self::api(API::HostGroup()->create(['name' => $name]), _s('create the group "%1$s"', $name))['groupids'][0];
	}

	private static function api($result, string $what) {
		if ($result === false) {
			$said = array_column(get_and_clear_messages(), 'message');
			throw new Exception(_s('Zabbix would not %1$s: %2$s', $what, implode(' ', $said) ?: _('no reason given')));
		}
		return $result;
	}
}
