<?php declare(strict_types = 0);

namespace Modules\HostInventory\Actions;

use API;

/**
 * Let Zabbix fill these hosts' inventory from their items: inventory mode Disabled → Automatic.
 * A host in Manual mode is left as it is (its fields may have been typed in Zabbix); Zabbix checks
 * the user may write each host.
 */
class InventoryAutomatic extends Base {

	protected const NEEDS = 'edit';

	protected function checkInput(): bool {
		return $this->validateInput(['hostids' => 'required|array']);
	}

	protected function doAction(): void {
		$ids = array_values(array_filter(array_map('strval', (array) $this->getInput('hostids')), 'ctype_digit'));
		$hosts = $ids ? API::Host()->get(['output' => ['hostid', 'inventory_mode'], 'hostids' => $ids, 'editable' => true]) ?: [] : [];
		$off = array_values(array_filter($hosts, fn($h) => (int) $h['inventory_mode'] === HOST_INVENTORY_DISABLED));
		if (!$off) {
			$this->toList(_('Nothing changed'), [], false, _('Every host you may change already keeps a Zabbix inventory.'));
			return;
		}
		if (API::Host()->massUpdate(['hosts' => array_map(fn($h) => ['hostid' => $h['hostid']], $off), 'inventory_mode' => HOST_INVENTORY_AUTOMATIC]) === false) {
			$said = array_column(get_and_clear_messages(), 'message');
			$this->toList(_('Nothing changed'), [], true, implode(' ', $said) ?: _('Zabbix refused.'));
			return;
		}
		$skipped = count($ids) - count($off);
		$this->toList(_n('Zabbix now fills the inventory of %1$s host from its items', 'Zabbix now fills the inventory of %1$s hosts from its items', count($off)),
			array_filter([_('Values appear as the items next report.'),
				$skipped ? _n('%1$s host left as it was (manual inventory, already automatic, or not yours to change).', '%1$s hosts left as they were (manual inventory, already automatic, or not yours to change).', $skipped) : null]));
	}
}
