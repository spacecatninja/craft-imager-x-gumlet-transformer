<?php
/**
 * Gumlet transformer for Imager X
 *
 * @link      https://www.spacecat.ninja
 * @copyright Copyright (c) 2026 André Elvan
 */

namespace spacecatninja\gumlettransformer\models;

use craft\base\Model;

use spacecatninja\gumlettransformer\helpers\GumletHelpers;

class Settings extends Model
{
    public string $defaultProfile = 'default';
    public string $apiKey = '';
    public bool $autoPurge = false;
    public bool $purgeElementAction = true;

    public array $profiles = [
        'default' => [
            'domain' => '',
            'useHttps' => true,
            'signKey' => '',
            'signedUrlsExpireSeconds' => 0,
            'sourceId' => '',
            'sourceIsWebProxy' => false,
            'useCloudSourcePath' => true,
            'addPath' => '',
            'getExternalImageDimensions' => true,
            'defaultParams' => [],
            'excludeFromPurge' => false,
            'apiKey' => '',
        ],
    ];

    /**
     * The top level API key, with any environment variable resolved.
     *
     * Plugin settings are populated after the model is constructed, so this can't be resolved
     * in `init()` the way the per-profile settings are in GumletSettings.
     */
    public function getParsedApiKey(): string
    {
        return GumletHelpers::parseEnvString($this->apiKey);
    }
}
