<?php declare(strict_types = 0);

namespace Modules\HostInventory\Actions;

use Exception;
use Modules\HostInventory\Lib\{Fields, Store, Support, Writer};

/**
 * Save the fields (their order is the order sent), the extra end-of-support rows and the
 * certificate agent. A removed field's values stay on the hosts unless purge[<key>] is ticked.
 */
class FieldSave extends Base {

	protected const NEEDS = 'manage';

	protected function checkInput(): bool {
		return $this->validateInput(['fields' => 'array', 'purge' => 'array', 'eol' => 'array', 'cert_agent' => 'string']);
	}

	protected function doAction(): void {
		$before = Fields::byKey(Fields::load());
		$fields = [];
		foreach ((array) $this->getInput('fields', []) as $f) {
			if (!is_array($f) || trim((string) ($f['label'] ?? '')) === '' && trim((string) ($f['key'] ?? '')) === '') {
				continue;
			}
			// A field saved before keeps its key; a new one gets it from its name.
			$f['key'] = trim((string) ($f['key'] ?? '')) !== '' ? $f['key'] : Fields::keyFor((string) $f['label']);
			$fields[] = Fields::shape($f);
		}
		$eol = [];
		foreach ((array) $this->getInput('eol', []) as $row) {
			if (is_array($row) && (trim((string) ($row['text'] ?? '')) !== '' || trim((string) ($row['date'] ?? '')) !== '')) {
				$eol[] = [trim((string) $row['text']), trim((string) $row['date'])];
			}
		}
		$certAgent = trim((string) $this->getInput('cert_agent', ''));
		$errors = array_merge(Fields::errors($fields, $before), Support::errors($eol, $certAgent));
		if (!Store::writable()) {
			$errors[] = _s('The data folder %1$s is missing or not writable. See the Host Inventory README.', Store::dir());
		}
		if ($errors) {
			$this->setResponse(FieldList::page($fields, ['eol' => $eol, 'cert_agent' => $certAgent], $errors));
			return;
		}
		Fields::save($fields);
		Support::saveSettings($eol, $certAgent);
		$done = [_('Saved.')];
		$kept = array_column($fields, 'key');
		foreach (array_keys((array) $this->getInput('purge', [])) as $key) {
			if (isset($before[$key]) && !in_array($key, $kept, true)) {
				try {
					$n = (new Writer())->purge((string) $key, $this->user());
					$done[] = _n('"%1$s" removed from %2$s host.', '"%1$s" removed from %2$s hosts.', $key, $n);
				}
				catch (Exception $e) {
					$errors[] = $e->getMessage();
				}
			}
		}
		$this->setResponse(FieldList::page(Fields::load(), Support::settings(), $errors, $done));
	}
}
