<?php declare(strict_types = 0);

namespace Modules\HostInventory;

use APP;
use CController;
use CMenuItem;
use CViewHelper;
use CWebUser;
use Zabbix\Core\CModule as CoreModule;

/**
 * Puts "Host Inventory" in Zabbix's Inventory menu for every signed-in user: each sees the hosts
 * Zabbix lets them see; Admins edit the values of hosts they may write; Super admins manage the
 * fields.
 *
 * Added before each page is drawn; Zabbix marks the current page's menu entry before modules run,
 * so the selection is run again once the entry is in — otherwise the Inventory section would close
 * on these pages.
 */
class Module extends CoreModule {

	private $added = false;

	public function onBeforeAction(CController $action): void {
		if ($this->added || CWebUser::isGuest() || !CWebUser::isLoggedIn()) {
			return;
		}
		$this->added = true;
		$menu = APP::Component()->get('menu.main');
		$item = (new CMenuItem(_('Host Inventory')))->setAction('hostinventory.list')
			->setAliases(['hostinventory.fields', 'hostinventory.fields.save', 'hostinventory.import']);
		$home = $menu->find(_('Inventory'));
		if ($home !== null && $home->hasSubMenu()) {
			$home->getSubMenu()->add($item);
			$menu->setSelectedByAction($action->getAction(), $_REQUEST,
				CViewHelper::loadSidebarMode() != ZBX_SIDEBAR_VIEW_MODE_COMPACT);
		}
	}
}
