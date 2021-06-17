<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\web\assets\authentication\login;

use Craft;
use craft\validators\UserPasswordValidator;
use craft\web\AssetBundle;
use craft\web\assets\login\LoginAsset;
use craft\web\View;

/**
 * Asset bundle for the Login auth step
 */
class LoginStepAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public $sourcePath = __DIR__ . '/dist';

    /**
     * @inheritdoc
     */
    public $depends = [
        LoginStepAsset::class
    ];

    /**
     * @inheritdoc
     */
    public $js = [
        'LoginStep.min.js',
    ];

    /**
     * @inheritdoc
     */
    public function registerAssetFiles($view)
    {
        parent::registerAssetFiles($view);

        if ($view instanceof View) {
            $view->registerTranslations('app', [
                'Check your email for instructions to reset your password.',
                'Invalid email.',
                'Invalid username or email.',
                'Login',
                'Password',
                'Reset Password',
            ]);

            $view->registerTranslations('yii', [
                '{attribute} should contain at least {min, number} {min, plural, one{character} other{characters}}.',
                '{attribute} should contain at most {max, number} {max, plural, one{character} other{characters}}.',
            ]);

            $view->registerJs(
                'window.useEmailAsUsername = ' . (Craft::$app->getConfig()->getGeneral()->useEmailAsUsername ? 'true' : 'false') . ";\n" .
                'window.minPasswordLength = ' . UserPasswordValidator::MIN_PASSWORD_LENGTH . ";\n" .
                'window.maxPasswordLength = ' . UserPasswordValidator::MAX_PASSWORD_LENGTH
            );
        }
    }
}
