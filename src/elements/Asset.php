<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\elements;

use Craft;
use craft\app\base\Element;
use craft\app\base\ElementInterface;
use craft\app\elements\db\AssetQuery;
use craft\app\helpers\HtmlHelper;
use craft\app\helpers\ImageHelper;
use craft\app\helpers\IOHelper;
use craft\app\helpers\TemplateHelper;
use craft\app\helpers\UrlHelper;
use craft\app\models\AssetFolder as AssetFolderModel;
use craft\app\models\AssetFolder;
use craft\app\models\AssetSource;
use craft\app\models\FieldLayout;
use Exception;
use yii\base\ErrorHandler;
use yii\base\InvalidCallException;
use yii\base\UnknownPropertyException;

/**
 * The Asset class is responsible for implementing and defining assets as a native element type in Craft.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class Asset extends Element
{
	// Properties
	// =========================================================================

	/**
	 * @var integer Source ID
	 */
	public $sourceId;

	/**
	 * @var integer Folder ID
	 */
	public $folderId;

	/**
	 * @var string Filename
	 */
	public $filename;

	/**
	 * @var string Original name
	 */
	public $originalName;

	/**
	 * @var string Kind
	 */
	public $kind;

	/**
	 * @var integer Width
	 */
	public $width;

	/**
	 * @var integer Height
	 */
	public $height;

	/**
	 * @var integer Size
	 */
	public $size;

	/**
	 * @var \DateTime Date modified
	 */
	public $dateModified;

	/**
	 * @var
	 */
	private $_transform;

	/**
	 * @var string
	 */
	private $_transformSource = '';

	// Public Methods
	// =========================================================================

	/**
	 * @inheritDoc ElementInterface::hasContent()
	 *
	 * @return bool
	 */
	public static function hasContent()
	{
		return true;
	}

	/**
	 * @inheritDoc ElementInterface::hasTitles()
	 *
	 * @return bool
	 */
	public static function hasTitles()
	{
		return true;
	}

	/**
	 * @inheritDoc ElementInterface::isLocalized()
	 *
	 * @return bool
	 */
	public static function isLocalized()
	{
		return true;
	}

	/**
	 * @inheritdoc
	 *
	 * @return AssetQuery The newly created [[AssetQuery]] instance.
	 */
	public static function find()
	{
		return new AssetQuery(get_called_class());
	}

	/**
	 * @inheritDoc ElementInterface::getSources()
	 *
	 * @param string|null $context
	 *
	 * @return array|false
	 */
	public static function getSources($context = null)
	{
		if ($context == 'index')
		{
			$sourceIds = Craft::$app->assetSources->getViewableSourceIds();
		}
		else
		{
			$sourceIds = Craft::$app->assetSources->getAllSourceIds();
		}

		$tree = Craft::$app->assets->getFolderTreeBySourceIds($sourceIds);

		return static::_assembleSourceList($tree);
	}

	/**
	 * @inheritDoc ElementInterface::getSourceByKey()
	 *
	 * @param string      $key
	 * @param string|null $context
	 *
	 * @return array|null
	 */
	public static function getSourceByKey($key, $context = null)
	{
		if (preg_match('/folder:(\d+)(:single)?/', $key, $matches))
		{
			$folder = Craft::$app->assets->getFolderById($matches[1]);

			if ($folder)
			{
				return static::_assembleSourceInfoForFolder($folder, empty($matches[2]));
			}
		}

		return parent::getSourceByKey($key, $context);
	}

	/**
	 * @inheritDoc ElementInterface::getAvailableActions()
	 *
	 * @param string|null $source
	 *
	 * @return array|null
	 */
	public static function getAvailableActions($source = null)
	{
		if (!preg_match('/^folder:(\d+)$/', $source, $matches))
		{
			return;
		}

		$folderId = $matches[1];

		$actions = [];

		// View
		$viewAction = Craft::$app->elements->getAction('View');
		$viewAction->setParams([
			'label' => Craft::t('app', 'View asset'),
		]);
		$actions[] = $viewAction;

		// Edit
		$editAction = Craft::$app->elements->getAction('Edit');
		$editAction->setParams([
			'label' => Craft::t('app', 'Edit asset'),
		]);
		$actions[] = $editAction;

		// Rename File
		if (
			Craft::$app->assets->canUserPerformAction($folderId, 'removeFromAssetSource') &&
			Craft::$app->assets->canUserPerformAction($folderId, 'uploadToAssetSource')
		)
		{
			$actions[] = 'RenameFile';
		}

		// Replace File
		if (Craft::$app->assets->canUserPerformAction($folderId, 'uploadToAssetSource'))
		{
			$actions[] = 'ReplaceFile';
		}

		// Copy Reference Tag
		$copyRefTagAction = Craft::$app->elements->getAction('CopyReferenceTag');
		$copyRefTagAction->setParams([
			'elementType' => 'asset',
		]);
		$actions[] = $copyRefTagAction;

		// Delete
		if (Craft::$app->assets->canUserPerformAction($folderId, 'removeFromAssetSource'))
		{
			$actions[] = 'DeleteAssets';
		}

		// Allow plugins to add additional actions
		$allPluginActions = Craft::$app->plugins->call('addAssetActions', [$source], true);

		foreach ($allPluginActions as $pluginActions)
		{
			$actions = array_merge($actions, $pluginActions);
		}

		return $actions;
	}

	/**
	 * @inheritDoc ElementInterface::defineSearchableAttributes()
	 *
	 * @return array
	 */
	public static function defineSearchableAttributes()
	{
		return ['filename', 'extension', 'kind'];
	}

	/**
	 * @inheritDoc ElementInterface::defineSortableAttributes()
	 *
	 * @retrun array
	 */
	public static function defineSortableAttributes()
	{
		$attributes = [
			'title'        => Craft::t('app', 'Title'),
			'filename'     => Craft::t('app', 'Filename'),
			'size'         => Craft::t('app', 'Size'),
			'dateModified' => Craft::t('app', 'Date Modified'),
		];

		// Allow plugins to modify the attributes
		Craft::$app->plugins->call('modifyAssetSortableAttributes', [&$attributes]);

		return $attributes;
	}

	/**
	 * @inheritDoc ElementInterface::defineTableAttributes()
	 *
	 * @param string|null $source
	 *
	 * @return array
	 */
	public static function defineTableAttributes($source = null)
	{
		$attributes = [
			'title'        => Craft::t('app', 'Title'),
			'filename'     => Craft::t('app', 'Filename'),
			'size'         => Craft::t('app', 'Size'),
			'dateModified' => Craft::t('app', 'Date Modified'),
		];

		// Allow plugins to modify the attributes
		Craft::$app->plugins->call('modifyAssetTableAttributes', [&$attributes, $source]);

		return $attributes;
	}

	/**
	 * @inheritDoc ElementInterface::getTableAttributeHtml()
	 *
	 * @param ElementInterface $element
	 * @param string           $attribute
	 *
	 * @return string
	 */
	public static function getTableAttributeHtml(ElementInterface $element, $attribute)
	{
		/** @var Asset $element */
		// First give plugins a chance to set this
		$pluginAttributeHtml = Craft::$app->plugins->callFirst('getAssetTableAttributeHtml', [$element, $attribute], true);

		if ($pluginAttributeHtml !== null)
		{
			return $pluginAttributeHtml;
		}

		switch ($attribute)
		{
			case 'filename':
			{
				return '<span style="word-break: break-word;">'.$element->filename.'</span>';
			}

			case 'size':
			{
				if ($element->size)
				{
					return Craft::$app->getFormatter()->asSize($element->size);
				}
				else
				{
					return '';
				}
			}

			default:
			{
				return parent::getTableAttributeHtml($element, $attribute);
			}
		}
	}

	/**
	 * @inheritDoc ElementInterface::populateElementModel()
	 *
	 * @param array $row
	 *
	 * @return array
	 */
	public static function populateElementModel($row)
	{
		return Asset::populateModel($row);
	}

	/**
	 * @inheritDoc ElementInterface::getEditorHtml()
	 *
	 * @param ElementInterface $element
	 *
	 * @return string
	 */
	public static function getEditorHtml(ElementInterface $element)
	{
		/** @var Asset $element */
		$html = Craft::$app->templates->renderMacro('_includes/forms', 'textField', [
			[
				'label'     => Craft::t('app', 'Filename'),
				'id'        => 'filename',
				'name'      => 'filename',
				'value'     => $element->filename,
				'errors'    => $element->getErrors('filename'),
				'first'     => true,
				'required'  => true
			]
		]);

		$html .= Craft::$app->templates->renderMacro('_includes/forms', 'textField', [
			[
				'label'     => Craft::t('app', 'Title'),
				'locale'    => $element->locale,
				'id'        => 'title',
				'name'      => 'title',
				'value'     => $element->title,
				'errors'    => $element->getErrors('title'),
				'required'  => true
			]
		]);

		$html .= parent::getEditorHtml($element);

		return $html;
	}

	/**
	 * @inheritDoc ElementInterface::saveElement()
	 *
	 * @param ElementInterface $element
	 * @param array            $params
	 *
	 * @return bool
	 */
	public static function saveElement(ElementInterface $element, $params)
	{
		/** @var Asset $element */
		// Is the filename changing?
		if (!empty($params['filename']) && $params['filename'] != $element->filename)
		{
			// Validate the content before we do anything drastic
			if (!Craft::$app->content->validateContent($element))
			{
				return false;
			}

			$oldFilename = $element->filename;
			$newFilename = $params['filename'];

			// Rename the file
			$response = Craft::$app->assets->renameFile($element, $newFilename);

			// Did it work?
			if ($response->isConflict())
			{
				$element->addError('filename', $response->getDataItem('prompt')->message);
				return false;
			}

			if ($response->isError())
			{
				$element->addError('filename', $response->errorMessage);
				return false;
			}
		}
		else
		{
			$newFilename = null;
		}

		$success = parent::saveElement($element, $params);

		if (!$success && $newFilename)
		{
			// Better rename it back
			Craft::$app->assets->renameFile($element, $oldFilename);
		}

		return $success;
	}

	// Instance Methods
	// -------------------------------------------------------------------------

	/**
	 * @inheritdoc
	 */
	public function __toString()
	{
		try
		{
			if (isset($this->_transform))
			{
				return $this->getUrl();
			}
			else
			{
				return parent::__toString();
			}
		}
		catch (Exception $e)
		{
			ErrorHandler::convertExceptionToError($e);
		}
	}

	/**
	 * Checks if a property is set.
	 *
	 * This method will check if $name is one of the following:
	 *
	 * - a magic property supported by [[Element::__isset()]]
	 * - an image transform handle
	 *
	 * @param string $name The property name
	 * @return boolean Whether the property is set
	 */
	public function __isset($name)
	{
		if (parent::__isset($name) || Craft::$app->assetTransforms->getTransformByHandle($name))
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Returns a property value.
	 *
	 * This method will check if $name is one of the following:
	 *
	 * - a magic property supported by [[Element::__get()]]
	 * - an image transform handle
	 *
	 * @param string $name The property name
	 * @return mixed The property value
	 * @throws UnknownPropertyException if the property is not defined
	 * @throws InvalidCallException if the property is write-only.
	 */
	public function __get($name)
	{
		try
		{
			return parent::__get($name);
		}
		catch (UnknownPropertyException $e)
		{
			// Is $name a transform handle?
			$transform = Craft::$app->assetTransforms->getTransformByHandle($name);

			if ($transform)
			{
				// Duplicate this model and set it to that transform
				$model = new Asset();

				// Can't just use attributes() here because we'll get thrown into an infinite loop.
				foreach ($this->attributes() as $attributeName)
				{
					$model->$attributeName = $this->$attributeName;
				}

				$model->setContent($this->getContent());
				$model->setTransform($transform);

				return $model;
			}
			else
			{
				throw $e;
			}
		}
	}

	/**
	 * @inheritdoc
	 */
	public function rules()
	{
		$rules = parent::rules();

		$rules[] = [['sourceId'], 'number', 'min' => -2147483648, 'max' => 2147483647, 'integerOnly' => true];
		$rules[] = [['folderId'], 'number', 'min' => -2147483648, 'max' => 2147483647, 'integerOnly' => true];
		$rules[] = [['width'], 'number', 'min' => -2147483648, 'max' => 2147483647, 'integerOnly' => true];
		$rules[] = [['height'], 'number', 'min' => -2147483648, 'max' => 2147483647, 'integerOnly' => true];
		$rules[] = [['size'], 'number', 'min' => -2147483648, 'max' => 2147483647, 'integerOnly' => true];
		$rules[] = [['dateModified'], 'craft\\app\\validators\\DateTime'];

		return $rules;
	}

	/**
	 * @inheritDoc ElementInterface::getFieldLayout()
	 *
	 * @return FieldLayout|null
	 */
	public function getFieldLayout()
	{
		$source = $this->getSource();

		if ($source->id)
		{
			return $source->getFieldLayout();
		}
		else
		{
			$folder = $this->getFolder();

			if (preg_match('/field_([0-9]+)/', $folder->name, $matches))
			{
				$fieldId = $matches[1];
				$field = Craft::$app->fields->getFieldById($fieldId);
				$settings = $field->settings;

				if ($settings['useSingleFolder'])
				{
					$sourceId = $settings['singleUploadLocationSource'];
				}
				else
				{
					$sourceId = $settings['defaultUploadLocationSource'];
				}

				$source = Craft::$app->assetSources->getSourceById($sourceId);

				if ($source)
				{
					return $source->getFieldLayout();
				}
			}
		}

		return null;
	}

	/**
	 * @inheritDoc ElementInterface::isEditable()
	 *
	 * @return bool
	 */
	public function isEditable()
	{
		return Craft::$app->getUser()->checkPermission('uploadToAssetSource:'.$this->sourceId);
	}

	/**
	 * Returns an <img> tag based on this asset.
	 *
	 * @return \Twig_Markup|null
	 */
	public function getImg()
	{
		if ($this->kind == 'image')
		{
			$img = '<img src="'.$this->url.'" width="'.$this->getWidth().'" height="'.$this->getHeight().'" alt="'.HtmlHelper::encode($this->title).'" />';
			return TemplateHelper::getRaw($img);
		}
	}

	/**
	 * @return AssetFolder|null
	 */
	public function getFolder()
	{
		return Craft::$app->assets->getFolderById($this->folderId);
	}

	/**
	 * @return AssetSource|null
	 */
	public function getSource()
	{
		return Craft::$app->assetSources->getSourceById($this->sourceId);
	}

	/**
	 * Sets the transform.
	 *
	 * @param mixed $transform
	 *
	 * @return Asset
	 */
	public function setTransform($transform)
	{
		$this->_transform = Craft::$app->assetTransforms->normalizeTransform($transform);
		return $this;
	}

	/**
	 * Returns the URL to the file.
	 *
	 * @param string|null $transform
	 *
	 * @return mixed
	 */
	public function getUrl($transform = null)
	{
		if ($transform === null && isset($this->_transform))
		{
			$transform = $this->_transform;
		}

		return Craft::$app->assets->getUrlForFile($this, $transform);
	}

	/**
	 * @inheritDoc ElementInterface::getThumbUrl()
	 *
	 * @param int $size
	 *
	 * @return string
	 */
	public function getThumbUrl($size = 125)
	{
		if ($this->hasThumb())
		{
			return UrlHelper::getResourceUrl('assetthumbs/'.$this->id.'/'.$size, [
				Craft::$app->resources->dateParam => $this->dateModified->getTimestamp()
			]);
		}
		else
		{
			return false;
		}
	}

	/**
	 * @inheritDoc ElementInterface::getIconUrl()
	 *
	 * @param int $size
	 *
	 * @return string
	 */
	public function getIconUrl($size = 125)
	{
		if ($this->hasThumb())
		{
			return false;
		}
		else
		{
			return UrlHelper::getResourceUrl('icons/'.$this->getExtension().'/'.$size);
		}
	}

	/**
	 * Does this file have a thumbnail?
	 *
	 * @return bool
	 */
	public function hasThumb()
	{
		if ($this->kind == 'image' && $this->height && $this->width)
		{
			// Gd doesn't process bitmaps
			if ($this->getExtension() == 'bmp' && Craft::$app->images->isGd())
			{
				return false;
			}

			return true;
		}

		return false;
	}

	/**
	 * Get the file extension.
	 *
	 * @return mixed
	 */
	public function getExtension()
	{
		return IOHelper::getExtension($this->filename);
	}

	/**
	 * @return string
	 */
	public function getMimeType()
	{
		return IOHelper::getMimeType($this->filename);
	}

	/**
	 * Get image height.
	 *
	 * @param string|null $transform The optional transform handle for which to get thumbnail.
	 *
	 * @return bool|float|mixed
	 */

	public function getHeight($transform = null)
	{
		return $this->_getDimension('height', $transform);
	}

	/**
	 * Get image width.
	 *
	 * @param string|null $transform The optional transform handle for which to get thumbnail.
	 *
	 * @return bool|float|mixed
	 */
	public function getWidth($transform = null)
	{
		return $this->_getDimension('width', $transform);
	}

	/**
	 * @return string
	 */
	public function getTransformSource()
	{
		if (!$this->_transformSource)
		{
			Craft::$app->assetTransforms->getLocalImageSource($this);
		}

		return $this->_transformSource;
	}

	/**
	 * Set a source to use for transforms for this Assets File.
	 *
	 * @param $uri
	 */
	public function setTransformSource($uri)
	{
		$this->_transformSource = $uri;
	}

	// Private Methods
	// =========================================================================

	/**
	 * Transforms an asset folder tree into a source list.
	 *
	 * @param array $folders
	 * @param bool  $includeNestedFolders
	 *
	 * @return array
	 */
	private static function _assembleSourceList($folders, $includeNestedFolders = true)
	{
		$sources = [];

		foreach ($folders as $folder)
		{
			$sources['folder:'.$folder->id] = static::_assembleSourceInfoForFolder($folder, $includeNestedFolders);
		}

		return $sources;
	}

	/**
	 * Transforms an AssetFolderModel into a source info array.
	 *
	 * @param AssetFolderModel $folder
	 * @param bool             $includeNestedFolders
	 *
	 * @return array
	 */
	private static function _assembleSourceInfoForFolder(AssetFolderModel $folder, $includeNestedFolders = true)
	{
		$source = [
			'label'     => ($folder->parentId ? $folder->name : Craft::t('app', $folder->name)),
			'hasThumbs' => true,
			'criteria'  => ['folderId' => $folder->id],
			'data'      => ['upload' => is_null($folder->sourceId) ? true : Craft::$app->assets->canUserPerformAction($folder->id, 'uploadToAssetSource')]
		];

		if ($includeNestedFolders)
		{
			$source['nested'] = static::_assembleSourceList($folder->getChildren(), true);
		}

		return $source;
	}

	/**
	 * Return a dimension of the image.
	 *
	 * @param $dimension 'height' or 'width'
	 * @param $transform
	 *
	 * @return null|float|mixed
	 */
	private function _getDimension($dimension, $transform)
	{
		if ($this->kind != 'image')
		{
			return null;
		}

		if ($transform === null && isset($this->_transform))
		{
			$transform = $this->_transform;
		}

		if (!$transform)
		{
			return $this->$dimension;
		}

		$transform = Craft::$app->assetTransforms->normalizeTransform($transform);

		$dimensions = [
			'width' => $transform->width,
			'height' => $transform->height
		];

		if (!$transform->width || !$transform->height)
		{
			// Fill in the blank
			list($dimensions['width'], $dimensions['height']) = ImageHelper::calculateMissingDimension($dimensions['width'], $dimensions['height'], $this->width, $this->height);
		}

		// Special case for 'fit' since that's the only one whose dimensions vary from the transform dimensions
		if ($transform->mode == 'fit')
		{
			$factor = max($this->width / $dimensions['width'], $this->height / $dimensions['height']);
			$dimensions['width']  = round($this->width / $factor);
			$dimensions['height'] = round($this->height / $factor);
		}

		return $dimensions[$dimension];
	}
}
