<?php declare(strict_types = 0);

namespace Modules\HostInventory\Actions;

use Exception;
use Modules\HostInventory\Lib\{Store, Writer};

/** Apply the import previewed last — by this user, in the last hour. */
class ImportApply extends Base {

	protected const NEEDS = 'edit';

	protected function checkInput(): bool {
		return true;
	}

	protected function doAction(): void {
		$p = Store::read(ImportUpload::PENDING, []);
		if (!$p || ($p['by'] ?? '') !== $this->user() || time() - (int) ($p['at'] ?? 0) > 3600) {
			$this->toList(_('Nothing imported'), [], true, _('No previewed import is waiting: upload the file again.'));
			return;
		}
		$changes = [];
		foreach ($p['changes'] as [$hostid, , $key, , $after]) {
			$changes[(string) $hostid][$key] = (string) $after;
		}
		$writer = new Writer();
		try {
			$backup = $writer->setValues($changes, $this->user(), 'CSV import');
		}
		catch (Exception $e) {
			$this->toList(_('Import stopped'), $writer->done(), true, $e->getMessage());
			return;
		}
		Store::delete(ImportUpload::PENDING);
		$this->toList(_n('%1$s host updated from the file', '%1$s hosts updated from the file', count($writer->done())),
			[_s('Backup of the values before: %1$s', $backup)]);
	}
}
