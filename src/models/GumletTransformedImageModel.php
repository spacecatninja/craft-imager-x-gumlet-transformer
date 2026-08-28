<?php
/**
 * Gumlet transformer for Imager X
 *
 * @link      https://www.spacecat.ninja
 * @copyright Copyright (c) 2026 André Elvan
 */

namespace spacecatninja\gumlettransformer\models;

use Craft;
use craft\elements\Asset;

use spacecatninja\gumlettransformer\helpers\GumletHelpers;
use spacecatninja\imagerx\exceptions\ImagerException;
use spacecatninja\imagerx\helpers\ImagerHelpers;
use spacecatninja\imagerx\models\BaseTransformedImageModel;
use spacecatninja\imagerx\models\LocalSourceImageModel;
use spacecatninja\imagerx\models\TransformedImageInterface;

use yii\base\InvalidConfigException;

class GumletTransformedImageModel extends BaseTransformedImageModel implements TransformedImageInterface
{
    /**
     * Gumlet modes that fit the image inside the target box, rather than filling it.
     */
    private const CONTAIN_MODES = ['fit', 'min', 'max'];

    /**
     * @var string
     */
    private string $gumletPath;

    /**
     * @throws ImagerException|InvalidConfigException
     */
    public function __construct(?string $imageUrl = null, Asset|string|null $source = null, private ?array $params = null, private ?GumletSettings $profileConfig = null)
    {
        $this->source = $source;
        $this->gumletPath = GumletHelpers::getGumletFilePath($source, $profileConfig);

        $this->path = '';
        $this->extension = '';
        $this->mimeType = '';
        $this->size = 0;

        if ($imageUrl !== null) {
            $this->url = $imageUrl;
        }

        $this->width = 0;
        $this->height = 0;

        $params ??= [];
        $mode = $params['mode'] ?? 'fit';

        if (isset($params['width'], $params['height'])) {
            $this->width = (int)$params['width'];
            $this->height = (int)$params['height'];

            if ($source !== null && \in_array($mode, self::CONTAIN_MODES, true)) {
                [$sourceWidth, $sourceHeight] = $this->getSourceImageDimensions($source);

                if ($sourceWidth && $sourceHeight) {
                    $scale = min((int)$params['width'] / $sourceWidth, (int)$params['height'] / $sourceHeight);

                    if ($this->clampsToSource($params, $mode)) {
                        $scale = min($scale, 1);
                    }

                    $this->width = (int)round($sourceWidth * $scale);
                    $this->height = (int)round($sourceHeight * $scale);
                }
            }
        } elseif (isset($params['width']) || isset($params['height'])) {
            if ($source !== null) {
                [$sourceWidth, $sourceHeight] = $this->getSourceImageDimensions($source);

                if ((int)$sourceWidth === 0 || (int)$sourceHeight === 0) {
                    if (isset($params['width'])) {
                        $this->width = (int)$params['width'];
                    }

                    if (isset($params['height'])) {
                        $this->height = (int)$params['height'];
                    }
                } else {
                    [$w, $h] = $this->calculateTargetSize($params, $sourceWidth, $sourceHeight);

                    $this->width = $w;
                    $this->height = $h;
                }
            }
        } else {
            // Neither is set, image is not resized. Just get dimensions and return.
            [$sourceWidth, $sourceHeight] = $this->getSourceImageDimensions($source);

            $this->width = (int)$sourceWidth;
            $this->height = (int)$sourceHeight;
        }
    }

    /**
     * @throws ImagerException
     */
    protected function getSourceImageDimensions($source): array
    {
        if ($source instanceof Asset) {
            return [$source->getWidth(), $source->getHeight()];
        }

        if ($this->profileConfig !== null && $this->profileConfig->getExternalImageDimensions) {
            $sourceModel = new LocalSourceImageModel($source);
            $sourceModel->getLocalCopy();

            $sourceImageSize = ImagerHelpers::getSourceImageSize($sourceModel);

            return [$sourceImageSize[0], $sourceImageSize[1]];
        }

        return [0, 0];
    }

    protected function calculateTargetSize($params, $sourceWidth, $sourceHeight): array
    {
        $mode = $params['mode'] ?? 'fit';
        $ratio = $sourceWidth / $sourceHeight;
        $clamp = $this->clampsToSource($params, $mode);

        $w = isset($params['width']) ? (int)$params['width'] : null;
        $h = isset($params['height']) ? (int)$params['height'] : null;

        if ($w) {
            if ($clamp) {
                $w = min($w, (int)$sourceWidth);
            }

            return [$w, (int)round($w / $ratio)];
        }

        if ($h) {
            if ($clamp) {
                $h = min($h, (int)$sourceHeight);
            }

            return [(int)round($h * $ratio), $h];
        }

        return [0, 0];
    }

    /**
     * Whether the result is capped at the source dimensions.
     *
     * Gumlet's `min` and `max` modes never enlarge; the rest only do when `enlarge` is on,
     * which the transformer sets from Imager's `allowUpscale`.
     */
    private function clampsToSource(array $params, string $mode): bool
    {
        if ($mode === 'min' || $mode === 'max') {
            return true;
        }

        return ($params['enlarge'] ?? 'false') !== 'true';
    }

    public function getSize(string $unit = 'b', int $precision = 2): float|int
    {
        return $this->size;
    }

    public function getIsNew(): bool
    {
        return false;
    }

    /**
     * Gets a color palette for the image, using Gumlet's palette API.
     *
     * Returns a decoded object for the `json` format, and a string of CSS rules for `css`.
     *
     * @see https://docs.gumlet.com/image/image-transform-api/color-operations
     */
    public function getPalette(string $format = 'json', int $numColors = 6, string $cssPrefix = ''): object|string|null
    {
        if ($this->profileConfig === null) {
            return null;
        }

        $params = $this->params ?? [];
        $params['palette'] = $format;
        $params['colors'] = max(0, min(16, $numColors));

        if ($cssPrefix !== '') {
            $params['prefix'] = $cssPrefix;
        }

        $paletteUrl = GumletHelpers::buildUrl($this->gumletPath, $params, $this->profileConfig);
        $key = 'imager-x-gumlet-palette-' . base64_encode($paletteUrl);

        $paletteData = Craft::$app->getCache()?->getOrSet($key, static fn() => GumletHelpers::fetchUrl($paletteUrl));

        if (!$paletteData) {
            Craft::error('An error occured when trying to get palette data from Gumlet. The URL was: ' . $paletteUrl, __METHOD__);

            return null;
        }

        return $format === 'json' ? json_decode($paletteData, false, 512, JSON_THROW_ON_ERROR) : $paletteData;
    }
}
