<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\controllers;

use Craft;
use craft\app\base\Element;
use craft\app\base\ElementInterface;
use craft\app\errors\Exception;
use craft\app\errors\HttpException;
use craft\app\web\Controller;

/**
 * The BaseElementsController class provides some common methods for [[ElementsController]] and [[ElementIndexController]].
 *
 * Note that all actions in the controller require an authenticated Craft session via [[Controller::allowAnonymous]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
abstract class BaseElementsController extends Controller
{
	// Public Methods
	// =========================================================================

	/**
	 * Initializes the application component.
	 *
	 * @throws HttpException
	 * @return null
	 */
	public function init()
	{
		// Element controllers only support JSON responses
		$this->requireAjaxRequest();

		// Element controllers are only available to the Control Panel
		if (!Craft::$app->getRequest()->getIsCpRequest())
		{
			throw new HttpException(403);
		}
	}

	// Protected Methods
	// =========================================================================

	/**
	 * Returns the posted element type class.
	 *
	 * @throws Exception
	 * @return ElementInterface
	 */
	protected function getElementClass()
	{
		$class = Craft::$app->getRequest()->getRequiredParam('elementType');

		if (!class_exists($class) || !($class instanceof ElementInterface))
		{
			throw new Exception(Craft::t('app', 'No element type exists with the class “{class}”', ['class' => $class]));
		}

		return $class;
	}

	/**
	 * Returns the context that this controller is being called in.
	 *
	 * @return string
	 */
	protected function getContext()
	{
		return Craft::$app->getRequest()->getParam('context');
	}
}
