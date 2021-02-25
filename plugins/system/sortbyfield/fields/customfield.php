<?php
/**
 *  @project   sortbyfield
 *  @license   GPLv3
 *  @copyright Copyright (c) 2021 Nicholas K. Dionysopoulos
 */

// Prevent direct access
defined('_JEXEC') || die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

if (class_exists('JFormFieldCustomField'))
{
	return;
}

FormHelper::loadFieldClass('groupedlist');

class JFormFieldCustomField extends JFormFieldGroupedList
{
	/**
	 * The form field type.
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	protected $type = 'CustomField';

	/**
	 * Method to get the field option groups.
	 *
	 * @return  array  The field option objects as a nested array in groups.
	 *
	 * @throws  UnexpectedValueException
	 * @since   1.0.0
	 *
	 */
	public function getGroups()
	{
		$db = Factory::getDbo();
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
		catch (Exception $e)
		{
			return [];
		}

		$groups = [
			'' => [
				HTMLHelper::_('select.option', 0, Text::_('JNONE'))
			]
		];

		foreach ($fieldData as $fieldDatum)
		{
			$groupName = $fieldDatum['gtitle'] ?? '';
			$groups[$groupName] = $groups[$groupName] ?? [];

			$groups[$groupName][] = HTMLHelper::_('select.option', $fieldDatum['id'], $fieldDatum['title']);
		}

		return $groups;
	}

}