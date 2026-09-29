<?php
/**
 * Host Inventory's pure parts: the fields and their tags, what a host's items say (OS, disks,
 * services, websites), end of support, what needs attention, and the CSV import.
 *   php tests/spec.test.php     (or: docker run --rm -v "$PWD":/m -w /m php:8.4-cli-alpine php tests/spec.test.php)
 * Exit 0 when every check holds; each failure is printed. A PHP warning is a failure.
 */
namespace {
	set_error_handler(function ($no, $msg, $file, $line) { throw new \ErrorException($msg, 0, $no, $file, $line); });
	function _($s) { return $s; }
	function _s($s, ...$a) { foreach ($a as $i => $v) { $s = str_replace('%'.($i + 1).'$s', (string) $v, $s); } return $s; }
	// As Zabbix's: the count is the LAST argument, and every argument fills a placeholder.
	function _n($a, $b, ...$args) { if (!is_int(end($args))) { throw new \TypeError('_n(): the count must come last'); } return _s(end($args) == 1 ? $a : $b, ...$args); }
}
namespace Modules\HostInventory\Test {
	foreach (['Store', 'Fields', 'Measure', 'Support', 'InvCsv', 'Writer'] as $lib) {
		require __DIR__.'/../lib/'.$lib.'.php';
	}
	use Modules\HostInventory\Lib\{Fields, InvCsv, Measure, Store, Support, Writer};

	$failed = 0; $passed = 0;
	function check(string $what, bool $ok, $detail = null): void {
		global $failed, $passed;
		if ($ok) { $passed++; return; }
		$failed++; echo "FAIL: $what", $detail !== null ? ' — '.json_encode($detail, JSON_UNESCAPED_SLASHES) : '', PHP_EOL;
	}
	$dir = sys_get_temp_dir().'/hinv-test-'.getmypid();
	mkdir($dir);
	putenv('HOST_INVENTORY_DATA_DIR='.$dir);
	$it = fn($v, $ok = true, $clock = 1790000000) => ['value' => (string) $v, 'clock' => $clock, 'ok' => $ok];

	/* ---------------- fields ---------------- */
	$def = Fields::defaults();
	check('the default fields: client, branch, type, access, owner, environment', array_column($def, 'key') === ['client', 'branch', 'type', 'access', 'owner', 'environment']);
	check('type offers CI, DI, On-Prem', Fields::byKey($def)['type']['choices'] === ['CI', 'DI', 'On-Prem']);
	check('a choice is matched whatever the case, spaces, dashes or underscores', Fields::normalize(Fields::byKey($def)['type'], ' on prem ') === 'On-Prem'
		&& Fields::normalize(Fields::byKey($def)['type'], 'ON_PREM') === 'On-Prem' && Fields::normalize(Fields::byKey($def)['type'], 'Cloud') === 'Cloud'
		&& Fields::normalize(Fields::byKey($def)['branch'], ' Chennai ') === 'Chennai');
	check('the data folder is HOST_INVENTORY_DATA_DIR', Store::dir() === $dir && Store::writable());
	check('the defaults are valid', Fields::errors($def) === [], Fields::errors($def));
	check('load gives the defaults until something is saved', array_column(Fields::load(), 'key') === array_column($def, 'key'));
	$mine = array_merge($def, [Fields::shape(['key' => Fields::keyFor('Rack position'), 'label' => 'Rack position', 'type' => 'text', 'list' => '1'])]);
	Fields::save($mine);
	check('saved fields load back, a new one with its key made from the name', array_column(Fields::load(), 'key')[6] === 'rack_position' && Fields::load()[6]['list'] === true);
	check('a choice needs choices; keys are unique and well formed', count(Fields::errors([Fields::shape(['key' => 'x', 'label' => 'X', 'type' => 'choice']),
		Fields::shape(['key' => 'x', 'label' => 'Y']), Fields::shape(['key' => '9bad', 'label' => 'Z'])])) === 3);
	check('an unknown type is refused', count(Fields::errors([Fields::shape(['key' => 'k', 'label' => 'K', 'type' => 'colour'])])) === 1);
	check('choices arrive as text or a list', Fields::shape(['choices' => 'Prod, DR ,UAT'])['choices'] === ['Prod', 'DR', 'UAT']);
	$by = Fields::byKey($def);
	check('values are checked by type', Fields::valueError($by['type'], 'Cloud') !== null && Fields::valueError($by['type'], 'CI') === null
		&& Fields::valueError(Fields::shape(['label' => 'N', 'type' => 'number']), '12a') !== null && Fields::valueError(Fields::shape(['label' => 'D', 'type' => 'date']), '2026-02-30') !== null
		&& Fields::valueError(Fields::shape(['label' => 'D', 'type' => 'date']), '2026-02-28') === null);
	check('a client must be one Cluster Management has (when it has any)', Fields::valueError($by['client'], 'nobody', ['acme']) !== null
		&& Fields::valueError($by['client'], 'acme', ['acme']) === null && Fields::valueError($by['client'], 'anyone', []) === null);
	check('an empty value always passes (it clears)', Fields::valueError($by['type'], '') === null);
	check('one line, at most 255 characters', Fields::valueError($by['branch'], "a\nb") !== null && Fields::valueError($by['branch'], str_repeat('x', 256)) !== null);

	/* ---------------- tags ---------------- */
	$tags = [['tag' => 'evp-client', 'value' => 'acme'], ['tag' => 'inv:branch', 'value' => 'Chennai'], ['tag' => 'inv:type', 'value' => 'DI'], ['tag' => 'scope', 'value' => 'x']];
	check('values are read from inv: tags only', Fields::valuesOf($tags) === ['branch' => 'Chennai', 'type' => 'DI']);
	$after = Fields::applyTo($tags, ['branch' => 'Mumbai', 'type' => '', 'owner' => 'SOC']);
	check('applying values: changed, cleared, added — every other tag kept as it was', $after === [
		['tag' => 'evp-client', 'value' => 'acme'], ['tag' => 'scope', 'value' => 'x'], ['tag' => 'inv:branch', 'value' => 'Mumbai'], ['tag' => 'inv:owner', 'value' => 'SOC']], $after);
	check('a field not given is left alone', Fields::valuesOf(Fields::applyTo($tags, ['owner' => 'SOC']))['branch'] === 'Chennai');

	/* ---------------- what a host is ---------------- */
	check('kind from templates: Linux, Windows, or a website with a web check', Measure::kind(['Linux by Zabbix agent -SISA'], false) === 'linux'
		&& Measure::kind(['Windows by Zabbix agent active'], false) === 'windows' && Measure::kind(['Template X'], true) === 'web' && Measure::kind(['Template X'], false) === null);

	$ubuntu = ['system.sw.os' => $it('Linux version 6.8.0-139-generic (buildd@bos03-arm64-058) (aarch64-linux-gnu-gcc-13 …'),
		'system.uname' => $it('Linux ubuntu 6.8.0-139-generic #139-Ubuntu SMP PREEMPT_DYNAMIC Sat Aug 9 aarch64'),
		'system.sw.arch' => $it('aarch64'), 'system.uptime' => $it('292571'), 'agent.version' => $it('7.0.31')];
	$o = Measure::os($ubuntu, 'linux');
	check('Linux: the kernel from /proc/version, never taken for the distribution', $o['kernel'] === '6.8.0-139-generic' && $o['name'] === 'Ubuntu' && $o['arch'] === 'aarch64'
		&& $o['uptime'] === 292571 && $o['agent'] === '7.0.31' && str_contains($o['hint'], 'system.sw.os[name]'), $o);
	$named = Measure::os($ubuntu + ['system.sw.os[name]' => $it('Ubuntu 22.04.4 LTS')], 'linux');
	check('Linux: system.sw.os[name] is the distribution when the host has it', $named['name'] === 'Ubuntu 22.04.4 LTS' && $named['hint'] === '');
	$el = Measure::os(['system.sw.os' => $it('Linux version 3.10.0-1160.119.1.el7.x86_64 (mockbuild@…)')], 'linux');
	check('Linux: an el7 kernel says RHEL-family 7', $el['name'] === 'RHEL-family 7' && $el['kernel'] === '3.10.0-1160.119.1.el7.x86_64', $el);
	check('Linux: el9_4 gives the minor version too', Measure::os(['system.sw.os' => $it('Linux version 5.14.0-427.13.1.el9_4.x86_64 (x)')], 'linux')['name'] === 'RHEL-family 9.4');
	$unk = Measure::os(['system.sw.os' => $it('Linux version 6.1.0-18-amd64 (debian-kernel@…)')], 'linux');
	check('Linux: a kernel that does not say is "not reported", with what would fix it', $unk['name'] === null && str_contains($unk['hint'], 'distribution not reported'));
	$win = Measure::os(['system.sw.os' => $it('Microsoft Windows Server 2019 Datacenter Build 17763'), 'system.uname' => $it('Windows JUMP-CHN-01 10.0.17763 Microsoft Windows Server 2019 Datacenter x64'),
		'system.sw.arch' => $it('x64')], 'windows');
	check('Windows: edition and build', $win['name'] === 'Windows Server 2019 Datacenter' && $win['kernel'] === 'build 17763', $win);
	check('an unsupported or never-measured item is unknown, not a value', Measure::os(['system.sw.arch' => $it('x', false)], 'linux')['arch'] === null
		&& Measure::size(['system.cpu.num' => $it('0'), 'vm.memory.size[total]' => $it('0')]) === ['cpu' => null, 'mem' => null]);
	$if = fn($ip, $dns = '', $main = 1, $type = 1) => ['ip' => $ip, 'dns' => $dns, 'main' => $main, 'type' => $type, 'useip' => $ip !== '' ? 1 : 0];
	check('IP address is {HOST.IP}: the main interface IP, even when Zabbix connects by DNS', Measure::address('es-node-1', [$if('10.0.0.7', 'es1.local')]) === '10.0.0.7'
		&& Measure::address('x', [$if('10.9.9.9', '', 0), $if('10.0.0.8')]) === '10.0.0.8');
	check('no interface IP: the host name when it is an IP ({HOST.HOST}), else the DNS name', Measure::address('192.168.64.11', [$if('', 'x.local')]) === '192.168.64.11'
		&& Measure::address('es-node-1', [$if('', 'es1.local')]) === 'es1.local' && Measure::address('10.1.1.1', []) === '10.1.1.1' && Measure::address('web', []) === '');
	check('hostname is the OS\'s own (system.hostname), unknown when not measured', Measure::hostname(['system.hostname' => $it('es-node-1')]) === 'es-node-1'
		&& Measure::hostname(['system.hostname' => $it('', false)]) === null);
	$zi = Measure::zabbixInventory('1', ['alias' => 'es-node-1', 'os' => 'Linux version 6.8', 'hw_arch' => 'aarch64', 'chassis' => '4', 'model' => '4093935616', 'name' => '']);
	check('Zabbix inventory: mode and the fields the items filled', $zi['mode'] === 'automatic' && $zi['filled'] === ['Alias' => 'es-node-1', 'OS' => 'Linux version 6.8',
		'HW architecture' => 'aarch64', 'Chassis' => '4', 'Model' => '4093935616'], $zi);
	check('Zabbix inventory disabled: no fields', Measure::zabbixInventory('-1', []) === ['mode' => 'disabled', 'filled' => []] && Measure::zabbixInventory('0', [])['mode'] === 'manual');
	check('CPU and memory', Measure::size(['system.cpu.num' => $it('4'), 'vm.memory.size[total]' => $it('4093935616')]) === ['cpu' => 4, 'mem' => 4093935616.0]);

	$fs = ['vfs.fs.dependent.size[/data,total]' => $it(2e12), 'vfs.fs.dependent.size[/data,pused]' => $it(71.26),
		'vfs.fs.dependent.size[/,total]' => $it(1e11), 'vfs.fs.dependent.size[/,pused]' => $it(41),
		'vfs.fs.dependent.size[/etc/hostname,total]' => $it(3.2e10), 'vfs.fs.dependent.size[/etc/hostname,pused]' => $it(40),
		'vfs.fs.size[/data2,total]' => $it(2e12), 'vfs.fs.dependent.size[/boot/efi,total]' => $it(5e8)];
	check('disks: / first, the rest by name; container bind files and runtime mounts left out; no % shown when not measured',
		Measure::disks($fs) === [['/', 1e11, 41.0], ['/data', 2e12, 71.3], ['/data2', 2e12, null]], Measure::disks($fs));
	$hostfs = ['vfs.fs.dependent.size[/hostfs,total]' => $it(3.2e10), 'vfs.fs.dependent.size[/hostfs,pused]' => $it(96.38),
		'vfs.fs.dependent.size[/hostfs/boot,total]' => $it(2.1e9), 'vfs.fs.dependent.size[/hostfs/boot,pused]' => $it(10.2),
		'vfs.fs.dependent.size[/hostfs/boot/efi,total]' => $it(1.1e9), 'vfs.fs.dependent.size[/etc/hostname,total]' => $it(3.2e10)];
	check('an agent in a container: the host\'s disks under /hostfs, shown as the host names them', Measure::disks($hostfs) === [['/', 3.2e10, 96.4], ['/boot', 2.1e9, 10.2]], Measure::disks($hostfs));
	check('host-root prefixes only as a whole path part', Measure::mount('/hostfs') === '/' && Measure::mount('/rootfs/data') === '/data' && Measure::mount('/hostfsx') === '/hostfsx'
		&& Measure::mount('/home/host') === '/home/host' && Measure::mount('C:') === 'C:');
	check('Windows drives: C: first', array_column(Measure::disks(['vfs.fs.dependent.size[D:,total]' => $it(5e11), 'vfs.fs.dependent.size[C:,total]' => $it(1.2e11)]), 0) === ['C:', 'D:']);
	check('a quoted parameter is read', Measure::param('service.info["Zabbix Agent 2",state]', 0) === 'Zabbix Agent 2' && Measure::param('service.info["x",state]', 1) === 'state');

	$svc = Measure::services([
		'service.info["W32Time",state]' => $it('0'), 'service.info[Spooler,state]' => $it('6'), 'service.info["x",startup]' => $it('0'),
		'systemd.service.active_state["sshd.service"]' => $it('1'), 'systemd.unit.info["cron.service",ActiveState]' => $it('failed'),
		'kaspersky.agent.status' => $it('1'), 'kaspersky.agent.enabled' => $it('1'),
		'sisaone.agent.status' => $it('0'), 'sisaone.agent.enabled' => $it('0'),
		'trendmicro.agent.status' => $it('0', false),
		'saltminion.agent.status' => $it('0'),
		'proc.num[mysqld]' => $it('2'), 'proc.num' => $it('439'), 'proc.num[,,run]' => $it('0')]);
	check('services: Windows, systemd, the SISA security agents, watched processes — running or stopped', $svc === [
		['cron.service', 'stopped'], ['kaspersky', 'running'], ['mysqld', 'running'], ['saltminion', 'stopped'], ['Spooler', 'stopped'], ['sshd.service', 'running'], ['W32Time', 'running']], $svc);
	check('an agent reported not installed, or whose item is unsupported, is not listed as stopped', !in_array('sisaone', array_column($svc, 0), true) && !in_array('trendmicro', array_column($svc, 0), true));

	$now = 1790000000;
	$web = Measure::web(['web.test.rspcode[Website,Open]' => $it('200'), 'web.test.time[Website,Open,resp]' => $it('0.412'), 'web.test.fail[Website]' => $it('0'),
		'cert.not_after' => $it((string) ($now + 64 * 86400 + 100))], $now);
	check('a website: code, response time, no failed step, certificate days', $web === ['code' => 200, 'ms' => 412, 'failed' => 0, 'error' => '', 'cert_days' => 64], $web);
	check('a website never checked: all unknown', Measure::web([], $now) === ['code' => null, 'ms' => null, 'failed' => null, 'error' => '', 'cert_days' => null]);
	check('last seen: the newest value; not reporting after a day, or never', Measure::lastSeen(['a' => $it(1, true, 100), 'b' => $it(1, true, 300)]) === 300
		&& Measure::stale($now - 90000, $now) && !Measure::stale($now - 60, $now) && Measure::stale(null, $now));

	/* ---------------- end of support and attention ---------------- */
	check('end of support by OS name, no digit straight after', Support::endOf('Ubuntu 20.04.6 LTS') === '2025-05-31' && Support::endOf('CentOS Linux 7 (Core)') === '2024-06-30'
		&& Support::endOf('Amazon Linux 2023') === null && Support::endOf('Amazon Linux 2') === '2026-06-30' && Support::endOf('RHEL-family 7') === '2024-06-30'
		&& Support::endOf('Windows Server 2012 R2 Standard') === '2023-10-10' && Support::endOf('Ubuntu 22.04.4 LTS') === null && Support::endOf(null) === null);
	check('an unknown distribution is never given an end of support', Support::endOf('Ubuntu') === null);
	check('admin rows are checked first', Support::endOf('Ubuntu 22.04.4 LTS', [['Ubuntu 22.04', '2027-06-01']]) === '2027-06-01');
	check('admin rows and the certificate agent are validated', count(Support::errors([['', '2026-01-01'], ['Rocky Linux 8', '2029-13-01']], 'bad address!')) === 3
		&& Support::errors([['Rocky Linux 8', '2029-05-31']], '10.0.0.5:10050') === []);
	Support::saveSettings([['Rocky Linux 8', '2029-05-31']], 'agent2.example.com');
	check('settings load back', Support::settings() === ['eol' => [['Rocky Linux 8', '2029-05-31']], 'cert_agent' => 'agent2.example.com']);
	$why = Support::attention(['values' => ['client' => 'acme', 'type' => 'Cloud'], 'stale' => true, 'eol' => '2024-06-30'], $def, $now);
	check('needs attention: a required field missing, a value outside its list, not reporting, OS out of support', $why === ['Branch missing', 'Type "Cloud" not in its list', 'not reporting', 'OS out of support since Jun 2024'], $why);
	check('a complete, reporting host on a supported OS needs nothing', Support::attention(['values' => ['client' => 'a', 'branch' => 'b', 'type' => 'DI'], 'stale' => false, 'eol' => null], $def, $now) === []);

	/* ---------------- CSV import ---------------- */
	$hosts = ['acme-ES-Data-1' => ['hostid' => '101', 'values' => ['branch' => 'Chennai', 'type' => 'DI']], 'nw-AD-01' => ['hostid' => '102', 'values' => []]];
	$csv = "\xEF\xBB\xBFHost,Name,Client,Branch,Type,OS,Rack position\nacme-ES-Data-1,x,acme,Chennai,on prem,Rocky,-\nnw-AD-01,y,ghost,Mumbai,,,R12\nnobody,z,acme,,,,\n";
	$plan = InvCsv::plan($csv, Fields::load(), $hosts, ['acme', 'northwind']);
	check('import: changes by host name; the same value is no change; "on prem" means On-Prem', in_array(['101', 'acme-ES-Data-1', 'type', 'DI', 'On-Prem'], $plan['changes'], true)
		&& !array_filter($plan['changes'], fn($c) => $c[1] === 'acme-ES-Data-1' && $c[2] === 'branch'), $plan);
	check('import: an unknown client and an unknown host are refused, with their line', count($plan['errors']) === 2 && str_contains($plan['errors'][0], 'Line 3') && str_contains($plan['errors'][1], 'nobody'), $plan['errors']);
	check('import: columns that are not fields are ignored and named; an empty cell changes nothing', $plan['ignored'] === ['name', 'os']
		&& in_array(['102', 'nw-AD-01', 'rack_position', '', 'R12'], $plan['changes'], true), $plan);
	check('import: "-" clears a value (only when there is one)', !array_filter($plan['changes'], fn($c) => $c[1] === 'acme-ES-Data-1' && $c[2] === 'rack_position'));
	$idx = InvCsv::index([
		['hostid' => 1, 'host' => '10.1.0.11', 'name' => 'acme-es-data-1', 'ip' => '10.1.0.11', 'kind' => 'linux', 'values' => []],
		['hostid' => 2, 'host' => 'db-7', 'name' => 'db-7', 'ip' => '10.1.2.70', 'kind' => 'linux', 'values' => []],
		['hostid' => 3, 'host' => 'a', 'name' => 'a', 'ip' => '10.9.9.9', 'kind' => 'linux', 'values' => []],
		['hostid' => 4, 'host' => 'b', 'name' => 'b', 'ip' => '10.9.9.9', 'kind' => 'linux', 'values' => []]]);
	check('import matches {HOST.HOST}, {HOST.NAME} or the IP address', $idx['10.1.0.11']['hostid'] === '1' && $idx['acme-es-data-1']['hostid'] === '1' && $idx['10.1.2.70']['hostid'] === '2');
	check('an IP two hosts share matches neither', !isset($idx['10.9.9.9']));
	check('import: a file without a Host column is refused', InvCsv::plan("Name,Branch\na,b\n", Fields::load(), $hosts)['errors'] !== []);

	/* ---------------- websites ---------------- */
	check('a website is named from its address unless named', Writer::websiteName('https://portal.acme.com/login', '') === 'portal.acme.com' && Writer::websiteName('https://x.io', 'ACME portal') === 'ACME portal');
	check('a website needs a full http(s) address', Writer::websiteError('portal.acme.com', '') !== null && Writer::websiteError('ftp://x.io', '') !== null && Writer::websiteError('https://portal.acme.com', '') === null);

	$it2 = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($it2 as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
	rmdir($dir);
	echo "$passed passed, $failed failed", PHP_EOL;
	exit($failed ? 1 : 0);
}
