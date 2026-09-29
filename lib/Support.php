<?php declare(strict_types = 0);

namespace Modules\HostInventory\Lib;

/**
 * End of support per OS version, and what makes a host need attention.
 *
 * A row matches an OS name that contains its text, with no digit straight after it:
 * "Ubuntu 20.04" matches "Ubuntu 20.04.6 LTS"; "Amazon Linux 2" does not match "Amazon Linux 2023".
 * Admins add rows on the Fields page; the built-in ones stay.
 */
class Support {

	public const FILE = 'inventory-settings.json';

	/** [contains, end of support (Y-m-d)] — the vendor's end of standard support. */
	public const BUILT_IN = [
		['CentOS Linux 6', '2020-11-30'], ['CentOS Linux 7', '2024-06-30'], ['CentOS Linux 8', '2021-12-31'], ['CentOS Stream 8', '2024-05-31'],
		['CentOS 7', '2024-06-30'], ['RHEL-family 6', '2020-11-30'], ['RHEL-family 7', '2024-06-30'],
		['Red Hat Enterprise Linux Server 6', '2020-11-30'], ['Red Hat Enterprise Linux Server 7', '2024-06-30'], ['Red Hat Enterprise Linux 7', '2024-06-30'],
		['Ubuntu 14.04', '2019-04-30'], ['Ubuntu 16.04', '2021-04-30'], ['Ubuntu 18.04', '2023-05-31'], ['Ubuntu 20.04', '2025-05-31'],
		['Debian GNU/Linux 8', '2020-06-30'], ['Debian GNU/Linux 9', '2022-06-30'], ['Debian GNU/Linux 10', '2024-06-30'],
		['SUSE Linux Enterprise Server 12', '2024-10-31'], ['Amazon Linux 2', '2026-06-30'],
		['Windows Server 2008', '2020-01-14'], ['Windows Server 2012', '2023-10-10'], ['Windows 7', '2020-01-14'], ['Windows 8.1', '2023-01-10']
	];

	/** Settings kept by the page: extra support rows, and the agent 2 that checks certificates. */
	public static function settings(): array {
		$s = Store::read(self::FILE, []);
		return ['eol' => array_values(array_filter((array) ($s['eol'] ?? []), fn($r) => is_array($r) && count($r) === 2)),
			'cert_agent' => (string) ($s['cert_agent'] ?? '')];
	}

	public static function saveSettings(array $eol, string $certAgent): void {
		Store::write(self::FILE, ['version' => 1, 'eol' => array_values($eol), 'cert_agent' => $certAgent]);
	}

	/** Problems with admin rows and the certificate agent, in words. */
	public static function errors(array $eol, string $certAgent): array {
		$errors = [];
		foreach ($eol as [$text, $date]) {
			$t = \DateTime::createFromFormat('!Y-m-d', (string) $date);
			if (trim((string) $text) === '' || !$t || $t->format('Y-m-d') !== $date) {
				$errors[] = _s('End of support "%1$s": give the OS text and a date like 2026-12-31.', $text);
			}
		}
		if ($certAgent !== '' && !preg_match('/^[A-Za-z0-9.\-]{1,253}(:\d{1,5})?$/', $certAgent)) {
			$errors[] = _s('"%1$s" is not an address like 10.0.0.5 or agent2.example.com:10050.', $certAgent);
		}
		return $errors;
	}

	/** The end-of-support date for an OS name, or null (unknown, or no row). Admin rows first. */
	public static function endOf(?string $os, array $extra = []): ?string {
		if ($os === null || $os === '') {
			return null;
		}
		foreach (array_merge($extra, self::BUILT_IN) as [$text, $date]) {
			if (preg_match('/(^|\W)'.preg_quote((string) $text, '/').'(?!\d)/i', $os)) {
				return (string) $date;
			}
		}
		return null;
	}

	/** Why a host needs attention, in words; [] when it does not. */
	public static function attention(array $host, array $fields, int $now): array {
		$why = [];
		foreach ($fields as $f) {
			$v = (string) ($host['values'][$f['key']] ?? '');
			if ($f['required'] && $v === '') {
				$why[] = _s('%1$s missing', $f['label']);
			}
			elseif ($f['type'] === 'choice' && $v !== '' && !in_array($v, $f['choices'], true)) {
				$why[] = _s('%1$s "%2$s" not in its list', $f['label'], $v);
			}
		}
		if (!empty($host['stale'])) {
			$why[] = _('not reporting');
		}
		if (!empty($host['eol']) && strtotime($host['eol']) < $now) {
			$why[] = _s('OS out of support since %1$s', date('M Y', strtotime($host['eol'])));
		}
		return $why;
	}
}
