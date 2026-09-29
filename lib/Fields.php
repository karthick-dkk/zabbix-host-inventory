<?php declare(strict_types = 0);

namespace Modules\HostInventory\Lib;

/**
 * The typed fields of Host Inventory — data, not code. A Super admin adds, renames, reorders,
 * hides and removes them on the Fields page; each host's value is a Zabbix host tag
 * "inv:<key>", so Problems, maps and dashboards can filter by it too.
 *
 * A field: key (fixed once values exist), label, type (text, choice, date, number, client),
 * choices (for choice), required, list (shown as a column), filter (offered as a filter).
 */
class Fields {

	public const FILE = 'inventory-fields.json';
	public const TAG_PREFIX = 'inv:';
	public const TYPES = ['text', 'choice', 'date', 'number', 'client'];
	public const MAX_FIELDS = 40;

	/** What a new installation starts with. */
	public static function defaults(): array {
		return [
			['key' => 'client', 'label' => 'Client', 'type' => 'client', 'choices' => [], 'required' => true, 'list' => true, 'filter' => true],
			['key' => 'branch', 'label' => 'Branch', 'type' => 'text', 'choices' => [], 'required' => true, 'list' => true, 'filter' => true],
			['key' => 'type', 'label' => 'Type', 'type' => 'choice', 'choices' => ['CI', 'DI', 'On-Prem'], 'required' => true, 'list' => true, 'filter' => true],
			['key' => 'access', 'label' => 'Access from', 'type' => 'text', 'choices' => [], 'required' => false, 'list' => true, 'filter' => true],
			['key' => 'owner', 'label' => 'Owner team', 'type' => 'text', 'choices' => [], 'required' => false, 'list' => false, 'filter' => false],
			['key' => 'environment', 'label' => 'Environment', 'type' => 'choice', 'choices' => ['Prod', 'DR', 'UAT'], 'required' => false, 'list' => false, 'filter' => true]
		];
	}

	/** The fields as saved, or the defaults. */
	public static function load(): array {
		$saved = Store::read(self::FILE, null);
		return is_array($saved) && isset($saved['fields']) && is_array($saved['fields']) ? array_map([self::class, 'shape'], $saved['fields']) : self::defaults();
	}

	public static function save(array $fields): void {
		Store::write(self::FILE, ['version' => 1, 'fields' => array_values($fields)]);
	}

	public static function byKey(array $fields): array {
		return array_column($fields, null, 'key');
	}

	/** A field as submitted or read, with every attribute present and typed. */
	public static function shape(array $f): array {
		$choices = is_array($f['choices'] ?? null) ? $f['choices'] : preg_split('/\s*,\s*/', (string) ($f['choices'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
		return [
			'key' => strtolower(trim((string) ($f['key'] ?? ''))),
			'label' => trim((string) ($f['label'] ?? '')),
			'type' => (string) ($f['type'] ?? 'text'),
			'choices' => array_values(array_unique(array_map(fn($c) => trim((string) $c), $choices))),
			'required' => !empty($f['required']),
			'list' => !empty($f['list']),
			'filter' => !empty($f['filter'])
		];
	}

	/** A key made from a label: "Rack position" → "rack_position". */
	public static function keyFor(string $label): string {
		return substr(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_'), 0, 40);
	}

	/**
	 * Problems with a list of fields, in words; [] when it can be saved. `$before` is the list
	 * as saved: a key that has values is never renamed (the label can be).
	 */
	public static function errors(array $fields, array $before = []): array {
		$errors = [];
		$seen = [];
		if (count($fields) > self::MAX_FIELDS) {
			$errors[] = _s('At most %1$s fields.', self::MAX_FIELDS);
		}
		foreach ($fields as $f) {
			$name = $f['label'] !== '' ? $f['label'] : $f['key'];
			if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $f['key'])) {
				$errors[] = _s('Field "%1$s": its key must start with a letter and hold only a-z, 0-9 and _.', $name);
			}
			if (isset($seen[$f['key']])) {
				$errors[] = _s('Two fields have the key "%1$s".', $f['key']);
			}
			$seen[$f['key']] = true;
			if ($f['label'] === '' || mb_strlen($f['label']) > 60) {
				$errors[] = _s('Field "%1$s" needs a name of at most 60 characters.', $f['key']);
			}
			if (!in_array($f['type'], self::TYPES, true)) {
				$errors[] = _s('Field "%1$s": type "%2$s" is not one of %3$s.', $name, $f['type'], implode(', ', self::TYPES));
			}
			if ($f['type'] === 'choice' && !$f['choices']) {
				$errors[] = _s('Field "%1$s" is a choice: give its choices.', $name);
			}
			foreach ($f['choices'] as $c) {
				if ($c === '' || mb_strlen($c) > 100) {
					$errors[] = _s('Field "%1$s": a choice must have 1 to 100 characters.', $name);
					break;
				}
			}
		}
		return $errors;
	}

	/**
	 * A value as the field spells it: for a choice, the choice it names whatever the case, spaces,
	 * dashes or underscores ("on prem", "ON_PREM" → "On-Prem"); anything else trimmed, unchanged.
	 */
	public static function normalize(array $f, string $value): string {
		$value = trim($value);
		if ($f['type'] !== 'choice' || $value === '') {
			return $value;
		}
		$k = fn($v) => preg_replace('/[^a-z0-9]/', '', strtolower($v));
		foreach ($f['choices'] as $c) {
			if ($k($c) === $k($value) && $k($c) !== '') {
				return $c;
			}
		}
		return $value;
	}

	/** Why a value cannot be set for a field; null when it can. '' always can (it clears). */
	public static function valueError(array $f, string $value, array $clients = []): ?string {
		if ($value === '') {
			return null;
		}
		if (mb_strlen($value) > 255 || preg_match('/[\r\n\t]/', $value)) {
			return _s('%1$s: at most 255 characters on one line.', $f['label']);
		}
		switch ($f['type']) {
			case 'choice':
				return in_array($value, $f['choices'], true) ? null : _s('%1$s: "%2$s" is not one of %3$s.', $f['label'], $value, implode(', ', $f['choices']));
			case 'number':
				return is_numeric($value) ? null : _s('%1$s: "%2$s" is not a number.', $f['label'], $value);
			case 'date':
				$t = \DateTime::createFromFormat('!Y-m-d', $value);
				return $t && $t->format('Y-m-d') === $value ? null : _s('%1$s: "%2$s" is not a date like 2026-12-31.', $f['label'], $value);
			case 'client':
				return !$clients || in_array($value, $clients, true) ? null : _s('%1$s: "%2$s" is not a client in Cluster Management.', $f['label'], $value);
		}
		return null;
	}

	/** A host's typed values, from its tags: key => value. */
	public static function valuesOf(array $tags): array {
		$out = [];
		foreach ($tags as $t) {
			if (strpos((string) $t['tag'], self::TAG_PREFIX) === 0) {
				$out[substr($t['tag'], strlen(self::TAG_PREFIX))] = (string) $t['value'];
			}
		}
		return $out;
	}

	/**
	 * A host's tags with these values applied: each given key's inv: tag replaced, or removed for
	 * ''. Every other tag — Cluster Management's, anyone's — stays exactly as it was.
	 */
	public static function applyTo(array $tags, array $values): array {
		$out = [];
		foreach ($tags as $t) {
			$key = strpos((string) $t['tag'], self::TAG_PREFIX) === 0 ? substr($t['tag'], strlen(self::TAG_PREFIX)) : null;
			if ($key === null || !array_key_exists($key, $values)) {
				$out[] = ['tag' => (string) $t['tag'], 'value' => (string) $t['value']];
			}
		}
		foreach ($values as $key => $value) {
			if ((string) $value !== '') {
				$out[] = ['tag' => self::TAG_PREFIX.$key, 'value' => (string) $value];
			}
		}
		return $out;
	}
}
