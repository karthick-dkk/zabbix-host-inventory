<?php declare(strict_types = 0);

namespace Modules\HostInventory\Actions;

use Exception;
use Modules\HostInventory\Lib\{Collector, Fields, Store, Writer};

/**
 * Set typed values on hosts: one host's form (every field), or one field for many ticked hosts.
 * values[<key>] = value; a key not sent is left as it is; '' clears it.
 */
class InventorySave extends Base {

	protected const NEEDS = 'edit';

	protected function checkInput(): bool {
		return $this->validateInput(['hostids' => 'required|array', 'values' => 'required|array', 'open' => 'string']);
	}

	protected function doAction(): void {
		$open = (string) $this->getInput('open', '');
		$fields = Fields::byKey(Fields::load());
		$clients = array_keys(Collector::clientTypes());
		$values = [];
		$errors = [];
		foreach ((array) $this->getInput('values') as $key => $value) {
			if (!isset($fields[$key])) {
				$errors[] = _s('There is no field "%1$s".', $key);
				continue;
			}
			$value = Fields::normalize($fields[$key], (string) $value);
			$err = Fields::valueError($fields[$key], $value, $clients);
			$err === null ? $values[$key] = $value : $errors[] = $err;
		}
		$hostids = array_values(array_filter(array_map('strval', (array) $this->getInput('hostids')), 'ctype_digit'));
		if ($errors || !$values || !$hostids) {
			$this->toList(_('Nothing changed'), [], true, $errors ? implode(' ', $errors) : _('Tick at least one host.'), $open);
			return;
		}
		if (!Store::writable()) {
			$this->toList(_('Nothing changed'), [], true, _s('The data folder %1$s is missing or not writable, so no backup can be taken. See the Host Inventory README.', Store::dir()), $open);
			return;
		}
		$writer = new Writer();
		try {
			$backup = $writer->setValues(array_fill_keys($hostids, $values), $this->user(), count($hostids) === 1 ? 'edit' : 'bulk edit');
		}
		catch (Exception $e) {
			$this->toList(_('Not saved'), $writer->done(), true, $e->getMessage(), $open);
			return;
		}
		$n = count($writer->done());
		$this->toList($n ? _n('%1$s host updated', '%1$s hosts updated', $n) : _('Nothing to change'),
			$n ? [_s('Backup of the values before: %1$s', $backup)] : [], false, null, $open);
	}
}
