<?php declare(strict_types = 0);

namespace Modules\HostInventory\Actions;

use CControllerResponseData;
use Modules\HostInventory\Lib\{Collector, Fields, InvCsv, Store, Support};

/** A CSV of typed values, previewed: what would change, what is refused. Nothing applied yet. */
class ImportUpload extends Base {

	protected const NEEDS = 'edit';
	public const PENDING = 'inventory-import.json';

	protected function checkInput(): bool {
		return true;
	}

	protected function doAction(): void {
		$plan = ['changes' => [], 'errors' => [], 'ignored' => []];
		$file = $_FILES['csv'] ?? null;
		if (!$file || (int) $file['error'] !== UPLOAD_ERR_OK || (int) $file['size'] === 0) {
			$plan['errors'][] = _('Choose a CSV file.');
		}
		elseif ((int) $file['size'] > InvCsv::MAX_BYTES) {
			$plan['errors'][] = _('The file is larger than 2 MB.');
		}
		else {
			$fields = Fields::load();
			$hosts = InvCsv::index(Collector::hosts(time(), Support::settings()['eol']));
			$plan = InvCsv::plan((string) file_get_contents($file['tmp_name']), $fields, $hosts, array_keys(Collector::clientTypes()));
			if ($plan['changes'] && Store::writable()) {
				Store::write(self::PENDING, ['at' => time(), 'by' => $this->user(), 'changes' => $plan['changes']]);
			}
		}
		$r = new CControllerResponseData(['plan' => $plan, 'store_ok' => Store::writable()]);
		$r->setTitle(_('Import inventory values'));
		$this->setResponse($r);
	}
}
