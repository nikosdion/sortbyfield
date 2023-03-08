<?php
/**
 * @project   sortbyfield
 * @license   GPLv3
 * @copyright Copyright (c) 2021-2023 Nicholas K. Dionysopoulos
 */

defined('_JEXEC') || die;

use Dionysopoulos\Plugin\System\SortByField\Extension\SortByField;
use Joomla\CMS\Extension\MVCComponent;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

return new class implements ServiceProviderInterface {
	/**
	 * Registers the service provider with a DI container.
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  void
	 */
	public function register(Container $container): void
	{
		/** @var MVCComponent $component */
		$container->set(
			PluginInterface::class,
			function (Container $container) {
				$config  = (array) PluginHelper::getPlugin('system', 'sortbyfield');
				$subject = $container->get(DispatcherInterface::class);
				$plugin  = new SortByField($subject, $config);

				$plugin->setApplication(Factory::getApplication());

				return $plugin;
			}
		);
	}
};

