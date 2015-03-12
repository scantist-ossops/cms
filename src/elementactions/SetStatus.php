<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\elementactions;

use Craft;
use craft\app\base\Element;
use craft\app\elements\db\ElementQueryInterface;
use craft\app\enums\AttributeType;
use craft\app\events\SetStatusEvent;

/**
 * Set Status Element Action
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class SetStatus extends BaseElementAction
{
	// Constants
	// =========================================================================

	/**
     * @event SetStatusEvent The event that is triggered after the statuses have been updated.
     */
    const EVENT_AFTER_SET_STATUS = 'afterSetStatus';

	// Public Methods
	// =========================================================================

	/**
	 * @inheritDoc ElementActionInterface::getTriggerHtml()
	 *
	 * @return string|null
	 */
	public function getTriggerHtml()
	{
		return Craft::$app->templates->render('_components/elementactions/SetStatus/trigger');
	}

	/**
	 * @inheritdoc
	 */
	public function performAction(ElementQueryInterface $query)
	{
		$status = $this->getParams()->status;

		// Figure out which element IDs we need to update
		if ($status == Element::ENABLED)
		{
			$sqlNewStatus = '1';
		}
		else
		{
			$sqlNewStatus = '0';
		}

		$elementIds = $query->ids();

		// Update their statuses
		Craft::$app->getDb()->createCommand()->update(
			'elements',
			['enabled' => $sqlNewStatus],
			['in', 'id', $elementIds]
		)->execute();

		if ($status == Element::ENABLED)
		{
			// Enable their locale as well
			Craft::$app->getDb()->createCommand()->update(
				'elements_i18n',
				['enabled' => $sqlNewStatus],
				['and', ['in', 'elementId', $elementIds], 'locale = :locale'],
				[':locale' => $query->locale]
			)->execute();
		}

		// Clear their template caches
		Craft::$app->templateCache->deleteCacheById($elementIds);

		// Fire an 'afterSetStatus' event
		$this->trigger(static::EVENT_AFTER_SET_STATUS, new SetStatusEvent([
			'elementQuery' => $query,
			'elementIds'   => $elementIds,
			'status'       => $status,
		]));

		$this->setMessage(Craft::t('app', 'Statuses updated.'));

		return true;
	}

	// Protected Methods
	// =========================================================================

	/**
	 * @inheritDoc BaseElementAction::defineParams()
	 *
	 * @return array
	 */
	protected function defineParams()
	{
		return [
			'status' => [AttributeType::Enum, 'values' => [Element::ENABLED, Element::DISABLED], 'required' => true]
		];
	}
}
