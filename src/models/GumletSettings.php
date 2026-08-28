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

class GumletSettings extends Model
{
    /**
     * The delivery domain for the Gumlet source, ie `mysource.gumlet.io`, or a custom CNAME.
     */
    public string $domain = '';

    public bool $useHttps = true;

    /**
     * The Gumlet source's secure token, used to sign URLs. Only needed if "Secure URLs"
     * is enabled for the source.
     */
    public string $signKey = '';

    /**
     * Number of seconds a signed URL should be valid for. Zero means no expiry.
     * Only has an effect when `signKey` is set.
     */
    public int $signedUrlsExpireSeconds = 0;

    /**
     * The Gumlet image source's ID, used when purging. A 24 character hex string, and not the same
     * thing as the source's subdomain.
     *
     * Usually best left empty — GumletService resolves it by matching `domain` against the sources
     * API, and caches it. Set it to skip that lookup.
     */
    public string $sourceId = '';

    /**
     * Gumlet API key for this specific profile. Takes precedence over the top-level `apiKey`.
     */
    public string $apiKey = '';

    public bool $sourceIsWebProxy = false;

    public bool $useCloudSourcePath = true;

    public string|array $addPath = '';

    public bool $getExternalImageDimensions = true;

    public array $defaultParams = [];

    public bool $excludeFromPurge = false;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        // Resolve any settings that might be set to an environment variable in the config file
        $this->domain = GumletHelpers::parseEnvString($this->domain);
        $this->signKey = GumletHelpers::parseEnvString($this->signKey);
        $this->sourceId = GumletHelpers::parseEnvString($this->sourceId);
        $this->apiKey = GumletHelpers::parseEnvString($this->apiKey);

        $this->addPath = \is_array($this->addPath)
            ? array_map(static fn(mixed $path): string => GumletHelpers::parseEnvString($path), $this->addPath)
            : GumletHelpers::parseEnvString($this->addPath);
    }
}
