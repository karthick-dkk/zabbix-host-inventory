<?php declare(strict_types = 0);

namespace Modules\HostInventory\Actions;

use CControllerResponseData;
use Modules\HostInventory\Lib\{Fields, Store, Support};

/** The Fields page: the typed fields, extra end-of-support rows, the certificate agent. */
class FieldList extends Base {

	protected const NEEDS = 'manage';

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function doAction(): void {
		$this->setResponse(self::page(Fields::load(), Support::settings(), []));
	}

	public static function page(array $fields, array $settings, array $errors, array $done = []): CControllerResponseData {
		$r = new CControllerResponseData(['fields' => $fields, 'settings' => $settings, 'errors' => $errors, 'done' => $done,
			'builtin_eol' => Support::BUILT_IN, 'store_ok' => Store::writable(), 'store_dir' => Store::dir()]);
		$r->setTitle(_('Host Inventory fields'));
		return $r;
	}
}
