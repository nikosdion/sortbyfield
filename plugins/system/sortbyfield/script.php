<?php
/**
 * @project   sortbyfield
 * @license   GPLv3
 * @copyright Copyright (c) 2021-2023 Nicholas K. Dionysopoulos
 */

use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\CMS\Installer\InstallerScriptInterface;

defined('_JEXEC') or die;

return new class extends InstallerScript implements InstallerScriptInterface {
	protected $minimumPhp = '8.1';

	protected $minimumJoomla = '4.2';

	public function preflight($type, $adapter): bool
	{
		return parent::preflight($type, $adapter);
	}

	public function install(InstallerAdapter $adapter): bool
	{
		return true;
	}

	public function update(InstallerAdapter $adapter): bool
	{
		return true;
	}

	public function uninstall(InstallerAdapter $adapter): bool
	{
		return true;
	}

	public function postflight(string $type, InstallerAdapter $adapter): bool
	{
		return true;
	}
};