<?php
/**
 * Gumlet transformer for Imager X
 *
 * @link      https://www.spacecat.ninja
 * @copyright Copyright (c) 2026 André Elvan
 */

namespace spacecatninja\gumlettransformer\elementactions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\AssetQuery;
use craft\elements\db\ElementQueryInterface;

use spacecatninja\gumlettransformer\GumletTransformer as Plugin;

class GumletPurgeElementAction extends ElementAction
{
    /**
     * @inheritdoc
     */
    public function getTriggerLabel(): string
    {
        return Craft::t('imager-x-gumlet-transformer', 'Purge from Gumlet');
    }

    /**
     * Purges selected image Assets from Gumlet
     */
    public function performAction(ElementQueryInterface $query): bool
    {
        /** @var AssetQuery $query */
        $imagesToPurge = $query->kind('image')->all();

        if (empty($imagesToPurge)) {
            $this->setMessage(Craft::t('imager-x-gumlet-transformer', 'No images to purge'));

            return true;
        }

        $gumletPlugin = Plugin::$plugin;

        try {
            foreach ($imagesToPurge as $imageToPurge) {
                $gumletPlugin->gumlet->purgeAssetFromGumlet($imageToPurge);
            }
        } catch (\Throwable $throwable) {
            $this->setMessage($throwable->getMessage());

            return false;
        }

        $numImagesToPurge = \count($imagesToPurge);

        if ($numImagesToPurge > 1) {
            $this->setMessage(Craft::t('imager-x-gumlet-transformer', 'Purging {count} images from Gumlet...', [
                'count' => $numImagesToPurge,
            ]));

            return true;
        }

        $this->setMessage(Craft::t('imager-x-gumlet-transformer', 'Purging image from Gumlet...'));

        return true;
    }
}
