<?php declare(strict_types = 0);

namespace Modules\HostInventory\Actions;

foreach (['Store', 'Fields', 'Measure', 'Support', 'InvCsv', 'Collector', 'Writer'] as $lib) {
	require_once __DIR__.'/../lib/'.$lib.'.php';
}

use CController;
use CControllerResponseRedirect;
use CMessageHelper;
use CUrl;
use CWebUser;

/**
 * What every Host Inventory page shares. Seeing: any signed-in user (Zabbix decides which hosts).
 * Editing values: Admins and Super admins (Zabbix decides which hosts). Fields: Super admins.
 */
abstract class Base extends CController {

	/** Who may use this action: 'see', 'edit' or 'manage'. */
	protected const NEEDS = 'see';

	protected function checkPermissions(): bool {
		if (CWebUser::isGuest()) {
			return false;
		}
		return ['see' => true, 'edit' => $this->canEdit(), 'manage' => $this->canManage()][static::NEEDS];
	}

	public function canEdit(): bool {
		return in_array($this->getUserType(), [USER_TYPE_ZABBIX_ADMIN, USER_TYPE_SUPER_ADMIN]);
	}

	public function canManage(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function user(): string {
		return (string) (CWebUser::$data['username'] ?? '?');
	}

	/** Back to the list with a notice; `$open` reopens one host's details. */
	protected function toList(string $title, array $lines = [], bool $error = false, ?string $detail = null, string $open = ''): void {
		$error ? CMessageHelper::setErrorTitle($title) : CMessageHelper::setSuccessTitle($title);
		if ($detail !== null) {
			$error ? CMessageHelper::addError($detail) : CMessageHelper::addSuccess($detail);
		}
		foreach ($lines as $line) {
			CMessageHelper::addSuccess($line);
		}
		$url = (new CUrl('zabbix.php'))->setArgument('action', 'hostinventory.list');
		if ($open !== '') {
			$url->setArgument('open', $open);
		}
		$this->setResponse(new CControllerResponseRedirect($url));
	}
}
