<?php
/**
 * @project   sortbyfield
 * @license   GPLv3
 * @copyright Copyright (c) 2021-2023 Nicholas K. Dionysopoulos
 */

namespace Dionysopoulos\Plugin\System\SortByField\Field;

// Prevent direct access
defined('_JEXEC') || die;

use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\GroupedlistField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseDriver;
use UnexpectedValueException;

class JoomlafieldField extends GroupedlistField
{
	/**
	 * The form field type.
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	protected $type = 'Joomlafield';

	/**
	 * Method to get the field option groups.
	 *
	 * @return  array  The field option objects as a nested array in groups.
	 *
	 * @throws  UnexpectedValueException
	 * @since   1.0.0
	 *
	 */
	public function getGroups(): array
	{
		/** @var DatabaseDriver $db */
		$db    = Factory::getContainer()->get('DatabaseDriver');
		$query = $db->getQuery(true)
			->select([
				$db->qn('g.title', 'gtitle'),
				$db->qn('f.id', 'id'),
				$db->qn('f.title', 'title'),
			])
			->from($db->qn('#__fields', 'f'))
			->leftJoin(
				$db->qn('#__fields_groups', 'g') . ' ON(' .
				$db->qn('g.id') . ' = ' . $db->qn('f.group_id') . ')'
			)
			->where(
				$db->qn('f.state') . ' = 1'
			)
			->where(
				$db->qn('g.state') . ' = 1 OR ' . $db->qn('g.state') . ' IS NULL'
			);

		try
		{
			$fieldData = $db->setQuery($query)->loadAssocList();
		}
		catch (Exception)
		{
			return [];
		}

		$groups = [
			'' => [
				HTMLHelper::_('select.option', 0, Text::_('JNONE')),
			],
		];

		foreach ($fieldData as $fieldDatum)
		{
			$groupName          = $fieldDatum['gtitle'] ?? '';
			$groups[$groupName] = $groups[$groupName] ?? [];

			$groups[$groupName][] = HTMLHelper::_('select.option', $fieldDatum['id'], $fieldDatum['title']);
		}

		return $groups;
	}

}