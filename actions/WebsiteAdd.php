<?php declare(strict_types = 0);

namespace Modules\HostInventory\Actions;

use Exception;
use Modules\HostInventory\Lib\{Collector, Fields, Support, Writer};

/** Add a website: a host with a web check (and a certificate check when an agent 2 is set). */
class WebsiteAdd extends Base {

	protected const NEEDS = 'edit';

	protected function checkInput(): bool {
		return $this->validateInput(['url' => 'required|string', 'name' => 'string', 'monitored_by' => 'string', 'values' => 'array']);
	}

	protected function doAction(): void {
		$url = trim((string) $this->getInput('url'));
		$name = trim((string) $this->getInput('name', ''));
		$fields = Fields::byKey(Fields::load());
		$clients = array_keys(Collector::clientTypes());
		$errors = array_filter([Writer::websiteError($url, $name)]);
		$values = [];
		foreach ((array) $this->getInput('values', []) as $key => $value) {
			$value = trim((string) $value);
			if (!isset($fields[$key]) || $value === '') {
				continue;
			}
			$value = Fields::normalize($fields[$key], $value);
			$err = Fields::valueError($fields[$key], $value, $clients);
			$err === null ? $values[$key] = $value : $errors[] = $err;
		}
		$by = (string) $this->getInput('monitored_by', '');
		$monitor = preg_match('/^(proxy|group):(\d+)$/', $by, $m) ? [$m[1], $m[2]] : ['server'];
		if ($errors) {
			$this->toList(_('Website not added'), [], true, implode(' ', $errors));
			return;
		}
		$writer = new Writer();
		try {
			$hostid = $writer->addWebsite($url, $name, $values, $monitor, Support::settings()['cert_agent']);
		}
		catch (Exception $e) {
			$this->toList(_('Website not added'), $writer->done(), true, $e->getMessage());
			return;
		}
		$this->toList(_('Website added'), $writer->done(), false, null, $hostid);
	}
}
