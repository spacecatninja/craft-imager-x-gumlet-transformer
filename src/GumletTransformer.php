<?php
/**
 * Gumlet transformer for Imager X
 *
 * @link      https://www.spacecat.ninja
 * @copyright Copyright (c) 2026 André Elvan
 */

namespace spacecatninja\gumlettransformer;

use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\events\ElementEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\ReplaceAssetEvent;
use craft\services\Assets;
use craft\services\Elements;

use spacecatninja\gumlettransformer\elementactions\GumletPurgeElementAction;
use spacecatninja\gumlettransformer\models\Settings;
use spacecatninja\gumlettransformer\services\GumletService;
use spacecatninja\gumlettransformer\transformers\Gumlet;
use spacecatninja\imagerx\services\ImagerService;

use yii\base\Event;

/**
 * @property GumletService $gumlet
 */
class GumletTransformer extends Plugin
{
    // Static Properties
    // =========================================================================

    /**
     * @var GumletTransformer
     */
    public static GumletTransformer $plugin;

    // Public Methods
    // =========================================================================

    public function init(): void
    {
        parent::init();

        self::$plugin = $this;

        // Register services
        $this->setComponents([
            'gumlet' => GumletService::class,
        ]);

        // Register transformer with Imager X
        Event::on(\spacecatninja\imagerx\ImagerX::class,
            \spacecatninja\imagerx\ImagerX::EVENT_REGISTER_TRANSFORMERS,
            static function(\spacecatninja\imagerx\events\RegisterTransformersEvent $event) {
                $event->transformers['gumlet'] = Gumlet::class;
            }
        );

        /** @var Settings $settings */
        $settings = $this->getSettings();

        // Register element action for purging
        if ($settings->purgeElementAction && GumletService::getCanPurge()) {
            Event::on(Asset::class, Element::EVENT_REGISTER_ACTIONS,
                static function(RegisterElementActionsEvent $event) {
                    $event->actions[] = GumletPurgeElementAction::class;
                }
            );
        }

        // Event listeners for auto-purging
        if ($settings->autoPurge && GumletService::getCanPurge()) {
            Event::on(Assets::class, Assets::EVENT_AFTER_REPLACE_ASSET,
                static function(ReplaceAssetEvent $event) {
                    if ($event->asset->kind === 'image') {
                        GumletTransformer::$plugin->gumlet->purgeAssetFromGumlet($event->asset);
                    }
                }
            );

            $imagerConfig = ImagerService::getConfig();

            if ($imagerConfig->removeTransformsOnAssetFileops) {
                Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT,
                    static function(ElementEvent $event) {
                        if ($event->element instanceof Asset) {
                            GumletTransformer::$plugin->gumlet->purgeAssetFromGumlet($event->element);
                        }
                    }
                );

                Event::on(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT,
                    static function(ElementEvent $event) {
                        /** @var Element $element */
                        $element = $event->element;

                        if ($element instanceof Asset && $element->getScenario() === Asset::SCENARIO_FILEOPS) {
                            GumletTransformer::$plugin->gumlet->purgeAssetFromGumlet($element);
                        }
                    }
                );
            }
        }
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }
}
