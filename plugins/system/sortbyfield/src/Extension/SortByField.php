<?php
/**
 * @project   sortbyfield
 * @copyright Copyright (c) 2021-2023 Nicholas K. Dionysopoulos
 * @license   GPLv3
 */

namespace Dionysopoulos\Plugin\System\SortByField\Extension;

defined('_JEXEC') || die;

use Exception;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Content\Site\Model\ArticlesModel;
use Joomla\Database\Query\QueryElement;
use Joomla\Database\QueryInterface;
use ReflectionObject;

class SortByField extends CMSPlugin
{
	/** @var CMSApplication */
	public $app;

	/** @inheritDoc */
	public function __construct(&$subject, $config = [])
	{
		parent::__construct($subject, $config);

		require_once __DIR__ . '/../Buffer.php';
	}

	/**
	 * Runs when Joomla is initializing its application.
	 *
	 * This is used to patch Joomla's Articles modeil in-memory
	 *
	 * @noinspection PhpUnused
	 *
	 * @return  void
	 *
	 * @since        1.0.0
	 */
	public function onAfterInitialise(): void
	{
		// Only applies to the frontend application
		if (!is_object($this->getApplication()) || !($this->getApplication() instanceof SiteApplication))
		{
			return;
		}

		// Load our language strings
		$this->loadLanguage();

		// Make sure the class isn't loaded yet
		if (class_exists(ArticlesModel::class, false))
		{
			Log::add(
				'SortByFields: Cannot initialize. ArticlesModel has already been loaded. Please reorder this plugin to be the first one loaded.',
				Log::CRITICAL
			);

			return;
		}

		// In-memory patching of Joomla's ContentModelArticles class
		$source     = JPATH_SITE . '/components/com_content/src/Model/ArticlesModel.php';
		$foobar     = <<< PHP
		// Import the appropriate plugin group.
		\Joomla\CMS\Plugin\PluginHelper::importPlugin('content');

		// Trigger the form preparation event.
		\Joomla\CMS\Factory::getApplication()->triggerEvent('onComContentArticlesGetListQuery', [\$query]);

		return \$query;
PHP;
		$phpContent = file_get_contents($source);
		$phpContent = str_replace('return $query;', $foobar, $phpContent);

		$bufferLocation = 'plgSystemSortbyfieldsBuffer://JoomlaArticlesContentModel.php';

		file_put_contents($bufferLocation, $phpContent);

		require_once $bufferLocation;
	}

	public function onContentPrepareForm(Form $form, $data): bool
	{
		$this->loadLanguage();
		$this->loadLanguage('plg_system_sortbyfield.sys');

		Form::addFormPath(__DIR__ . '/../../forms');

		// A menu item is being added/edited
		if ($form->getName() === 'com_menus.item')
		{
			$form->loadFile('menu', false);
		}

		return true;
	}

	public function onContentBeforeSave(?string $context, $table, $isNew = false, $data = null): bool
	{
		// Joomla 4 Media Manager freaks out when the plugin is enabled; skip this plugin if the context is com_media.
		if (str_starts_with($context, 'com_media.'))
		{
			return true;
		}
		// Joomla 3 does not pass the data from com_menus. Therefore, we have to fake it.
		if (is_null($data) && version_compare(JVERSION, '3.999.999', 'le'))
		{
			$input = $this->getApplication()->input;
			$data  = $input->get('jform', [], 'array');
		}

		// Make sure I have data to save
		if (!isset($data['sortbyfield']))
		{
			return true;
		}

		$key = null;

		if ($context === 'com_menus.item')
		{
			$key = 'params';
		}

		if (is_null($key))
		{
			return true;
		}

		$params        = @json_decode($table->{$key}, true) ?? [];
		$table->{$key} = json_encode(array_merge($params, ['sortbyfield' => $data['sortbyfield']]));

		return true;
	}

	public function onContentPrepareData(?string $context, $data)
	{
		$key = null;

		if ($context == 'com_menus.item')
		{
			$key = 'params';
		}

		if (is_null($key))
		{
			return true;
		}

		if (!isset($data->{$key}) || !isset($data->{$key}['sortbyfield']))
		{
			return true;
		}

		$data->sortbyfield = $data->{$key}['sortbyfield'];
		unset ($data->{$key}['sortbyfield']);

		return true;
	}

	/**
	 * @param   QueryInterface  $query
	 *
	 * @return  void
	 */
	public function onComContentArticlesGetListQuery(QueryInterface $query)
	{
		/** @var CMSApplication|mixed $app */
		$app = $this->getApplication();

		// Is this the frontend HTML application?
		if (!is_object($app) || !($app instanceof CMSApplication))
		{
			return;
		}

		// Try to get the active menu item
		try
		{
			$menu        = $app->getMenu();
			$currentItem = $menu->getActive();
		}
		catch (Exception)
		{
			return;
		}

		/**
		 * Addresses the case where com_content tries to get articles but there is no menu item present. We randomly saw
		 * this happen on a com_ajax(!!!) request because Joomla tried to render the latest articles modules regardless.
		 * Of course Joomla should not be rendering modules in this case but, hey, it's Joomla — it does weird stuff
		 * like this.
		 */
		if (empty($currentItem))
		{
			return;
		}

		$fieldId   = $currentItem->getParams()->get('sortbyfield.field', '');
		$sortOrder = $currentItem->getParams()->get('sortbyfield.ordering', 'ASC');

		if (!$fieldId)
		{
			return;
		}

		$this->orderByCustomField($query, $fieldId, $sortOrder);
	}

	/**
	 * Order a category's articles by the values of a custom field
	 *
	 * @param   QueryInterface  $query      The query to select articles from a category
	 * @param   int             $fieldId    Custom field ID
	 * @param   string          $sortOrder  Sort order (ASC/DESC)
	 *
	 * @return  void
	 *
	 * @since   1.0.0
	 */
	private function orderByCustomField(QueryInterface $query, int $fieldId, string $sortOrder): void
	{
		$query->leftJoin(
			$query->qn('#__fields_values', 'jcsv' . $fieldId) . ' ON(' .
			$query->qn(sprintf('jcsv%d.item_id', $fieldId)) . ' = ' . $query->qn('a.id')
			. ' AND ' .
			$query->qn(sprintf('jcsv%d.field_id', $fieldId)) . ' = ' . $query->q($fieldId) .
			')'
		);

		/** @var QueryElement $order */
		$order       = $query->order;
		$elements    = $order->getElements();
		$elements[0] = $query->qn(sprintf('jcsv%d.value', $fieldId)) . ' ' . $sortOrder . ', ' . $elements[0];

		$refOrder    = new ReflectionObject($order);
		$refElements = $refOrder->getProperty('elements');
		$refElements->setAccessible(true);
		$refElements->setValue($order, $elements);
	}
}
