<?php declare(strict_types = 0);

namespace Modules\HostInventory\Actions;

use API;
use CControllerResponseData;
use Modules\HostInventory\Lib\{Collector, Fields, Store, Support};

/** The inventory: every Linux server, Windows server and website the user may see. */
class InventoryList extends Base {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return $this->validateInput(['open' => 'string']);
	}

	protected function doAction(): void {
		$now = time();
		$fields = Fields::load();
		$settings = Support::settings();
		$hosts = Collector::hosts($now, $settings['eol']);
		$clientTypes = Collector::clientTypes();
		foreach ($hosts as &$h) {
			// A value Cluster Management supplies (client, type) counts as filled in.
			$h['attention'] = Support::attention(array_merge($h, ['values' => $h['values'] + $h['defaults']]), $fields, $now);
		}
		unset($h);
		$data = [
			'fields' => $fields,
			'hosts' => array_values($hosts),
			'clients' => array_keys($clientTypes),
			'can_edit' => $this->canEdit(),
			'can_manage' => $this->canManage(),
			'store_ok' => Store::writable(),
			'open' => (string) $this->getInput('open', ''),
			'cert_agent' => $settings['cert_agent'] !== '',
			'proxies' => $this->canEdit() ? array_column(API::Proxy()->get(['output' => ['proxyid', 'name'], 'sortfield' => 'name']) ?: [], 'name', 'proxyid') : [],
			'proxy_groups' => $this->canEdit() ? array_column(API::ProxyGroup()->get(['output' => ['proxy_groupid', 'name'], 'sortfield' => 'name']) ?: [], 'name', 'proxy_groupid') : [],
			'now' => $now
		];
		$response = new CControllerResponseData($data);
		$response->setTitle(_('Host Inventory'));
		$this->setResponse($response);
	}
}
