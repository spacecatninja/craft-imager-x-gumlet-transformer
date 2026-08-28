<?php
/**
 * Gumlet transformer for Imager X
 *
 * @link      https://www.spacecat.ninja
 * @copyright Copyright (c) 2026 André Elvan
 */

namespace spacecatninja\gumlettransformer\transformers;

use Craft;
use craft\base\Component;
use craft\elements\Asset;

use spacecatninja\gumlettransformer\GumletTransformer as Plugin;
use spacecatninja\gumlettransformer\helpers\GumletHelpers;
use spacecatninja\gumlettransformer\models\GumletSettings;
use spacecatninja\gumlettransformer\models\GumletTransformedImageModel;
use spacecatninja\gumlettransformer\models\Settings;
use spacecatninja\imagerx\exceptions\ImagerException;
use spacecatninja\imagerx\helpers\TransformHelpers;
use spacecatninja\imagerx\services\ImagerService;
use spacecatninja\imagerx\transformers\TransformerInterface;

class Gumlet extends Component implements TransformerInterface
{
    /**
     * Imager transform keys that map straight onto a Gumlet param.
     */
    public static array $transformKeyTranslate = [
        'width' => 'width',
        'height' => 'height',
        'format' => 'format',
        'bgColor' => 'bg',
    ];

    /**
     * Imager resize modes translated to Gumlet's `mode` param.
     *
     * `croponly` has no Gumlet equivalent and falls back to `fit`, the way the imgix
     * transformer falls back to `clip`.
     */
    public static array $modeTranslate = [
        'fit' => 'fit',
        'stretch' => 'stretch',
        'letterbox' => 'fill',
        'croponly' => 'fit',
        'crop' => 'crop',
    ];

    /**
     * Gumlet wants `jpeg`, Imager says `jpg`.
     */
    public static array $formatTranslate = [
        'jpg' => 'jpeg',
    ];

    /**
     * Main transform method
     *
     * @throws ImagerException
     */
    public function transform(Asset|string $image, array $transforms): ?array
    {
        $transformedImages = [];

        foreach ($transforms as $transform) {
            $transformedImages[] = $this->getTransformedImage($image, $transform);
        }

        return $transformedImages;
    }

    /**
     * Transform one image
     *
     * @throws ImagerException
     */
    private function getTransformedImage(Asset|string $image, array $transform): GumletTransformedImageModel
    {
        $config = ImagerService::getConfig();

        /** @var Settings $pluginSettings */
        $pluginSettings = Plugin::$plugin->getSettings();

        $profile = $config->transformerConfig['profile']
            ?? $transform['transformerParams']['profile']
            ?? $pluginSettings->defaultProfile;

        $profilesArr = $pluginSettings->profiles;

        if (!isset($profilesArr[$profile])) {
            $msg = 'Gumlet profile "' . $profile . '" does not exist.';
            Craft::error($msg, __METHOD__);
            throw new ImagerException($msg);
        }

        $gumletConfig = new GumletSettings($profilesArr[$profile]);

        if (GumletHelpers::getDomain($gumletConfig) === '') {
            $msg = Craft::t('imager-x-gumlet-transformer', 'No domain was given for Gumlet profile "{profile}". Set the profile\'s `domain` setting to your Gumlet source\'s delivery domain, ie `mysource.gumlet.io`, or your custom domain.', ['profile' => $profile]);
            Craft::error($msg, __METHOD__);
            throw new ImagerException($msg);
        }

        $params = $this->createParams($transform, $image, $gumletConfig);
        $path = GumletHelpers::getGumletFilePath($image, $gumletConfig);
        $url = GumletHelpers::buildUrl($path, $this->applyPadding($params, $transform), $gumletConfig);

        return new GumletTransformedImageModel($url, $image, $params, $gumletConfig);
    }

    /**
     * Create Gumlet transform params
     */
    private function createParams(array $transform, Asset|string $image, GumletSettings $gumletConfig): array
    {
        $config = ImagerService::getConfig();

        $r = [];

        // Merge in default values
        $transform['transformerParams'] = array_merge($gumletConfig->defaultParams, $transform['transformerParams'] ?? []);

        // Directly translate some keys
        foreach (self::$transformKeyTranslate as $key => $val) {
            if (isset($transform[$key])) {
                $r[$val] = $transform[$key];
                unset($transform[$key]);
            }
        }

        if (isset($r['format'])) {
            $r['format'] = self::$formatTranslate[$r['format']] ?? $r['format'];
        }

        if (isset($r['bg'])) {
            $r['bg'] = GumletHelpers::normalizeColor((string)$r['bg']);
        }

        // Set quality
        if (!isset($transform['quality']) && !isset($transform['pngcomp'])) {
            $ext = $r['format'] ?? null;

            if ($ext === null) {
                if ($image instanceof Asset) {
                    $ext = $image->getExtension();
                } else {
                    $pathParts = pathinfo($image);
                    $ext = $pathParts['extension'] ?? '';
                }

                $ext = self::$formatTranslate[$ext] ?? $ext;
            }

            $r += $this->getQualityParams((string)$ext, $transform);
        }

        unset(
            $transform['jpegQuality'],
            $transform['pngCompressionLevel'],
            $transform['webpQuality'],
            $transform['avifQuality'],
            $transform['jxlQuality']
        );

        // Deal with resize mode. Note that Gumlet's param is also called `mode`, so anything
        // passed through `transformerParams` will override what we set here.
        if (isset($transform['mode'])) {
            $r['mode'] = self::$modeTranslate[$transform['mode']] ?? 'crop';

            if ($transform['mode'] === 'letterbox') {
                $r['fill'] = 'solid';
                $r['fill-color'] = $this->getLetterboxColor($config->getSetting('letterbox', $transform));
            }
        } elseif (isset($r['width'], $r['height'])) {
            $r['mode'] = 'crop';
        } else {
            $r['mode'] = 'fit';
        }

        // Cropping needs both dimensions to mean anything
        if ($r['mode'] === 'crop' && !isset($r['width'], $r['height'])) {
            $r['mode'] = 'fit';
        }

        // If mode is crop, and crop isn't explicitly set, use position as focal point.
        if ($r['mode'] === 'crop' && !isset($transform['crop'])) {
            $position = $config->getSetting('position', $transform);
            [$left, $top] = explode(' ', $position);
            $r['crop'] = 'focalpoint';
            $r['fp-x'] = ((float)$left) / 100;
            $r['fp-y'] = ((float)$top) / 100;

            if (isset($transform['cropZoom'])) {
                $r['fp-z'] = $transform['cropZoom'];
            }
        }

        // Unset everything that has to do with mode and crop
        unset(
            $transform['mode'],
            $transform['cropZoom'],
            $transform['position'],
            $transform['letterbox']
        );

        // Trim. Imager's trim is a 0.0–1.0 fuzz factor applied before resizing, Gumlet's is a
        // 1–99 percentage similarity to the top left pixel, applied after resizing.
        if (isset($transform['trim'])) {
            $trim = (float)$transform['trim'];

            if ($trim > 0) {
                $r['trim'] = max(1, min(99, (int)round($trim * 100)));
            }

            unset($transform['trim']);
        }

        // Padding is dealt with separately, in applyPadding(), since it needs to be applied
        // after the target size has been settled.
        unset($transform['pad']);

        // If upscaling is disabled, tell Gumlet, and clamp the target size to the source. Gumlet
        // ignores `enlarge` in crop and stretch mode, where it never upscales, so without the
        // clamping we'd report dimensions the returned image doesn't have.
        $allowUpscale = $config->getSetting('allowUpscale', $transform);
        $r['enlarge'] = $allowUpscale ? 'true' : 'false';

        if (!$allowUpscale && $r['mode'] === 'crop' && $image instanceof Asset && isset($r['width'], $r['height'])) {
            $sourceWidth = $image->getWidth();
            $sourceHeight = $image->getHeight();

            if ($sourceWidth && $sourceHeight && ((int)$r['width'] > $sourceWidth || (int)$r['height'] > $sourceHeight)) {
                $sourceRatio = $sourceHeight / $sourceWidth;
                $transformRatio = (int)$r['height'] / (int)$r['width'];

                if ($sourceRatio > $transformRatio) {
                    $r['width'] = $sourceWidth;
                    $r['height'] = (int)ceil($sourceWidth * $transformRatio);
                } else {
                    $r['height'] = $sourceHeight;
                    $r['width'] = (int)ceil($sourceHeight / $transformRatio);
                }
            }
        }

        // Add any explicitly set Gumlet params
        foreach ($transform['transformerParams'] as $key => $val) {
            $r[$key] = $val;
        }

        unset($transform['transformerParams']);

        // Assume that the rest of the values left in the transform object is Gumlet specific
        foreach ($transform as $key => $val) {
            $r[$key] = $val;
        }

        // Unset stuff that's not supported by Gumlet, or has already been dealt with
        unset(
            $r['effects'],
            $r['preEffects'],
            $r['preeffects'],
            $r['watermark'],
            $r['frames'],
            $r['allowUpscale'],
            $r['cacheEnabled'],
            $r['cacheDuration'],
            $r['interlace'],
            $r['resizeFilter'],
            $r['smartResizeEnabled'],
            $r['removeMetadata'],
            $r['hashFilename'],
            $r['hashRemoteUrl'],
            $r['customEncoderOptions'],
            $r['adapterParams'],
            $r['profile']
        );

        foreach ($r as $key => $val) {
            // Gumlet expects the strings, `http_build_query` would give it `1` and an empty string.
            if (\is_bool($val)) {
                $r[$key] = $val ? 'true' : 'false';
                continue;
            }

            // Remove any empty values, since these result in an empty query string value that
            // gives us trouble with Facebook (!).
            if ($val === '' || $val === null) {
                unset($r[$key]);
            }
        }

        return $r;
    }

    /**
     * Applies padding to the params used for the URL.
     *
     * Imager treats width and height as the final size, including any padding, while Gumlet
     * pads after resizing. Shrink the resize target so the total comes out right.
     */
    private function applyPadding(array $params, array $transform): array
    {
        if (!isset($transform['pad'])) {
            return $params;
        }

        $pad = TransformHelpers::normalizePadding($transform['pad']);

        if ($pad === null) {
            return $params;
        }

        if (isset($params['width'])) {
            $params['width'] = max(1, (int)$params['width'] - $pad[1] - $pad[3]);
        }

        if (isset($params['height'])) {
            $params['height'] = max(1, (int)$params['height'] - $pad[0] - $pad[2]);
        }

        // Gumlet's pad takes the same top,right,bottom,left order Imager normalizes to.
        $params['pad'] = implode(',', $pad);

        return $params;
    }

    /**
     * Gets the letterbox fill color.
     *
     * Gumlet's `fill-color` has no alpha channel, so the opacity in Imager's letterbox
     * definition is not supported, and is ignored.
     */
    private function getLetterboxColor(array $letterboxDef): string
    {
        return GumletHelpers::normalizeColor((string)($letterboxDef['color'] ?? '000000'));
    }

    /**
     * Gets the quality params based on the output extension.
     *
     * Gumlet's `quality` only applies to lossy formats, PNG compression has its own param.
     */
    private function getQualityParams(string $ext, ?array $transform = null): array
    {
        $config = ImagerService::getConfig();

        return match ($ext) {
            'png' => ['pngcomp' => max(1, min(9, (int)$config->getSetting('pngCompressionLevel', $transform)))],
            'webp' => ['quality' => $config->getSetting('webpQuality', $transform)],
            'avif' => ['quality' => $config->getSetting('avifQuality', $transform)],
            'jxl' => ['quality' => $config->getSetting('jxlQuality', $transform)],
            default => ['quality' => $config->getSetting('jpegQuality', $transform)],
        };
    }
}
