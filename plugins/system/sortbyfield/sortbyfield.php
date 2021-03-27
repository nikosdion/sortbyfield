<?php
/**
 * @project   sortbyfield
 * @license   GPLv3
 * @copyright Copyright (c) 2021 Nicholas K. Dionysopoulos
 */

defined('_JEXEC') || die;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Menu\AbstractMenu;
use Joomla\CMS\Plugin\CMSPlugin;

class plgSystemSortbyfield extends CMSPlugin
{
	/** @var CMSApplication */
	public $app;

	/** @inheritDoc */
	public function __construct(&$subject, $config = [])
	{
		parent::__construct($subject, $config);

		require_once 'library/buffer.php';
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
		if (!is_object($this->app) || !($this->app instanceof SiteApplication))
		{
			return;
		}

		// Load our language strings
		$this->loadLanguage();

		// Make sure the class isn't loaded yet
		if (class_exists('ContentModelArticles', false))
		{
			Log::add('SortByFields: Cannot initialize. ContentModelArticles has already been loaded. Please reorder this plugin to be the first one loaded.', Log::CRITICAL);

			return;
		}

		// In-memory patching of Joomla's ContentModelArticles class
		$source     = JPATH_SITE . '/components/com_content/models/articles.php';
		$foobar     = <<< PHP
		// Import the appropriate plugin group.
		\JPluginHelper::importPlugin('content');

		// Get the dispatcher.
		\$dispatcher = \JEventDispatcher::getInstance();

		// Trigger the form preparation event.
		\$dispatcher->trigger('onComContentArticlesGetListQuery', [&\$query]);

		return \$query;
PHP;
		$phpContent = file_get_contents($source);
		$phpContent = str_replace('return $query;', $foobar, $phpContent);

		$bufferLocation = 'plgSystemSortbyfieldsBuffer://plgSystemMailmagicBufferMail.php';

		file_put_contents($bufferLocation, $phpContent);

		require_once $bufferLocation;
	}

	public function onContentPrepareForm(Form $form, $data): bool
	{
		$this->loadLanguage();
		$this->loadLanguage('plg_system_socialmagick.sys');

		Form::addFormPath(__DIR__ . '/forms');

		switch ($form->getName())
		{
			// A menu item is being added/edited
			case 'com_menus.item':
				$form->loadFile('menu', false);
				break;
		}

		return true;
	}

	public function onContentBeforeSave(?string $context, $table, $isNew = false, $data = null): bool
	{
		// Joomla 3 does not pass the data from com_menus. Therefore, we have to fake it.
		if (is_null($data) && version_compare(JVERSION, '3.999.999', 'le'))
		{
			$input = $this->app->input;
			$data  = $input->get('jform', [], 'array');
		}

		// Make sure I have data to save
		if (!isset($data['sortbyfield']))
		{
			return true;
		}

		$key = null;

		switch ($context)
		{
			case 'com_menus.item':
				$key = 'params';
				break;
		}

		if (is_null($key))
		{
			return true;
		}

		$params        = @json_decode($table->{$key}, true) ?? [];
		$table->{$key} = json_encode(array_merge($params, ['sortbyfield' => $data['sortbyfield']]));

		return true;
	}

	public function onContentPrepareData(?string $context, &$data)
	{
		$key = null;

		switch ($context)
		{
			case 'com_menus.item':
				$key = 'params';
				break;
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

	public function onComContentArticlesGetListQuery(JDatabaseQuery &$query)
	{
		// Is this the frontend HTML application?
		if (!is_object($this->app) || !($this->app instanceof CMSApplication))
		{
			return;
		}

		// Try to get the active menu item
		try
		{
			$menu        = AbstractMenu::getInstance('site');
			$currentItem = $menu->getActive();
		}
		catch (Exception $e)
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
	 * @param   JDatabaseQuery  $query      The query to select articles from a category
	 * @param   int             $fieldId    Custom field ID
	 * @param   string          $sortOrder  Sort order (ASC/DESC)
	 *
	 * @return  void
	 *
	 * @since   1.0.0
	 */
	private function orderByCustomField(JDatabaseQuery $query, int $fieldId, string $sortOrder): void
	{
		$query->leftJoin(
			$query->qn('#__fields_values', 'jcsv' . $fieldId) . ' ON(' .
			$query->qn(sprintf('jcsv%d.item_id', $fieldId)) . ' = ' . $query->qn('a.id')
			. ' AND ' .
			$query->qn(sprintf('jcsv%d.field_id', $fieldId)) . ' = ' . $query->q($fieldId) .
			')'
		);

		/** @var JDatabaseQueryElement $order */
		$order       = $query->order;
		$elements    = $order->getElements();
		$elements[0] = $query->qn(sprintf('jcsv%d.value', $fieldId)) . ' ' . $sortOrder . ', ' . $elements[0];

		$refOrder    = new ReflectionObject($order);
		$refElements = $refOrder->getProperty('elements');
		$refElements->setAccessible(true);
		$refElements->setValue($order, $elements);
	}
}