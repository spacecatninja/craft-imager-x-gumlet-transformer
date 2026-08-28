<?php
/**
 * Gumlet transformer for Imager X
 *
 * @link      https://www.spacecat.ninja
 * @copyright Copyright (c) 2026 André Elvan
 */

namespace spacecatninja\gumlettransformer\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;

use spacecatninja\gumlettransformer\GumletTransformer;
use spacecatninja\gumlettransformer\helpers\GumletHelpers;
use spacecatninja\gumlettransformer\models\GumletSettings;
use spacecatninja\gumlettransformer\models\Settings;
use spacecatninja\imagerx\exceptions\ImagerException;

class GumletService extends Component
{
    /**
     * @var string
     */
    public const PURGE_ENDPOINT = 'https://api.gumlet.com/v1/image/purge/';

    /**
     * @var string Endpoint used to look a source ID up from its delivery domain
     */
    public const SOURCES_ENDPOINT = 'https://api.gumlet.com/v1/image/sources';

    /**
     * @var int How long a looked up source ID is cached for, in seconds
     */
    public const SOURCE_ID_CACHE_DURATION = 86400;

    /**
     * @var int How long a failed source ID lookup is cached for, in seconds
     */
    public const SOURCE_ID_MISS_CACHE_DURATION = 300;

    /**
     * @var string Cached in place of a source ID when the lookup came up empty
     */
    private const SOURCE_ID_NOT_FOUND = '__not_found__';

    /**
     * @var int Sources fetched per request when looking up a source ID
     */
    private const SOURCES_PAGE_SIZE = 100;

    /**
     * @var int Safety net, so a surprising API response can't loop forever
     */
    private const SOURCES_MAX_PAGES = 20;

    /**
     * @var bool|null If purging is enabled or not
     */
    protected static ?bool $canPurge = null;

    /**
     * Purging is possible if there's a `profiles` map, and at least one purgable profile has an
     * API key. The source ID is looked up from the profile's domain when it isn't configured, so
     * it isn't required here — and mustn't be, since this runs on every request and can't do I/O.
     *
     * Used for determining if the GumletPurgeElementAction element action and the related event
     * handlers should be bootstrapped or not.
     */
    public static function getCanPurge(): bool
    {
        if (self::$canPurge === null) {
            /** @var Settings $settings */
            $settings = GumletTransformer::$plugin->getSettings();

            $profilesArr = $settings->profiles;

            if (empty($profilesArr)) {
                self::$canPurge = false;

                return false;
            }

            self::$canPurge = false;

            foreach ($profilesArr as $profileConfig) {
                $gumletConfig = new GumletSettings($profileConfig);

                if ($gumletConfig->sourceIsWebProxy || $gumletConfig->excludeFromPurge) {
                    continue;
                }

                if ($gumletConfig->apiKey ?: $settings->getParsedApiKey()) {
                    self::$canPurge = true;
                    break;
                }
            }
        }

        return self::$canPurge;
    }

    /**
     * Resolves the Gumlet source ID for a profile.
     *
     * A configured `sourceId` always wins. Otherwise it's looked up from the profile's delivery
     * domain via the sources API, and cached, so that the source ID doesn't have to be dug out of
     * the API by hand just to enable purging.
     *
     * Returns null when it can't be resolved, in which case purging is skipped.
     */
    public function getSourceId(GumletSettings $profile, string $apiKey): ?string
    {
        if ($profile->sourceId !== '') {
            return $profile->sourceId;
        }

        $domain = GumletHelpers::getDomain($profile);

        if ($domain === '' || $apiKey === '') {
            return null;
        }

        $cache = Craft::$app->getCache();
        // The API key is part of the key so that rotating it re-resolves rather than serving a
        // source ID that the new key may not have access to.
        $cacheKey = 'imager-x-gumlet-source-id-' . md5($domain . '|' . $apiKey);

        $cached = $cache?->get($cacheKey);

        // Check the sentinel before the general string case, or a cached miss gets handed back
        // as if it were a source ID.
        if ($cached === self::SOURCE_ID_NOT_FOUND) {
            return null;
        }

        if (\is_string($cached) && $cached !== '') {
            return $cached;
        }

        $sourceId = $this->fetchSourceId($domain, $apiKey);

        // Cache misses too, briefly, so a profile that can never be matched — a custom domain,
        // say — doesn't hit the sources API again on every single purge.
        $cache?->set(
            $cacheKey,
            $sourceId ?? self::SOURCE_ID_NOT_FOUND,
            $sourceId !== null ? self::SOURCE_ID_CACHE_DURATION : self::SOURCE_ID_MISS_CACHE_DURATION
        );

        return $sourceId;
    }

    /**
     * Looks a source ID up from its delivery domain, by paging the sources API.
     *
     * A source carries both `namespace` (the subdomain you picked, ie `mysource`) and `subdomain`
     * (the full delivery domain, ie `mysource.gumlet.io`). Custom domains are matched against
     * whatever `cname` the source reports.
     *
     * @see https://docs.gumlet.com/reference/tag/image-sources/get/image/sources
     */
    private function fetchSourceId(string $domain, string $apiKey): ?string
    {
        $domain = strtolower($domain);

        // The bare namespace is only a safe thing to match on for Gumlet's own domains. On a
        // custom domain the first label is arbitrary, and could collide with the namespace of a
        // completely unrelated source — which would then be the one we purge.
        $namespace = str_ends_with($domain, '.gumlet.io') ? explode('.', $domain)[0] : null;

        for ($page = 0; $page < self::SOURCES_MAX_PAGES; $page++) {
            $offset = $page * self::SOURCES_PAGE_SIZE;
            $url = self::SOURCES_ENDPOINT . '?offset=' . $offset . '&size=' . self::SOURCES_PAGE_SIZE;

            $response = $this->getJson($url, $apiKey);

            if ($response === null) {
                return null;
            }

            $sources = $this->extractSources($response);

            if (empty($sources)) {
                break;
            }

            foreach ($sources as $source) {
                if (!\is_array($source) || !isset($source['id']) || !\is_string($source['id'])) {
                    continue;
                }

                if ($this->sourceMatchesDomain($source, $domain, $namespace)) {
                    return $source['id'];
                }
            }

            if (\count($sources) < self::SOURCES_PAGE_SIZE) {
                break;
            }
        }

        Craft::warning(Craft::t('imager-x-gumlet-transformer', 'Could not find a Gumlet source matching the domain "{domain}". Set the profile\'s `sourceId` config setting explicitly to enable purging.', ['domain' => $domain]), __METHOD__);

        return null;
    }

    /**
     * Whether a source from the API is the one serving a given domain.
     */
    private function sourceMatchesDomain(array $source, string $domain, ?string $namespace): bool
    {
        if (isset($source['subdomain']) && \is_string($source['subdomain']) && strtolower($source['subdomain']) === $domain) {
            return true;
        }

        if ($namespace !== null && isset($source['namespace']) && \is_string($source['namespace']) && strtolower($source['namespace']) === $namespace) {
            return true;
        }

        // Custom domains. Gumlet has reported this as both a string and a list over time.
        $cname = $source['cname'] ?? null;

        foreach (\is_array($cname) ? $cname : [$cname] as $value) {
            if (\is_string($value) && $value !== '' && strtolower($value) === $domain) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pulls the list of sources out of a response.
     *
     * Gumlet's docs show the list wrapped in `all_sources`, but the documented example is for
     * video sources, so accept the other shapes it could reasonably take rather than silently
     * finding nothing.
     */
    private function extractSources(array $response): array
    {
        foreach (['all_sources', 'sources', 'data'] as $key) {
            if (isset($response[$key]) && \is_array($response[$key])) {
                return $response[$key];
            }
        }

        // A bare list of sources
        return array_is_list($response) ? $response : [];
    }

    /**
     * GETs a URL and decodes the JSON, or null if anything goes wrong.
     */
    private function getJson(string $url, string $apiKey): ?array
    {
        try {
            $curl = curl_init($url);

            curl_setopt_array($curl, [
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_RETURNTRANSFER => true,
            ]);

            $response = curl_exec($curl);
            $curlErrorNo = curl_errno($curl);
            $curlError = curl_error($curl);
            $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            if ($curlErrorNo !== 0) {
                Craft::error(Craft::t('imager-x-gumlet-transformer', 'A cURL error "{curlErrorNo}" occured when requesting "{url}". The error was: "{curlError}"', ['url' => $url, 'curlErrorNo' => $curlErrorNo, 'curlError' => $curlError]), __METHOD__);

                return null;
            }

            if ($httpStatus !== 200) {
                Craft::error(Craft::t('imager-x-gumlet-transformer', 'An error occured when requesting "{url}", status was "{httpStatus}" and response was "{response}"', ['url' => $url, 'httpStatus' => $httpStatus, 'response' => $response]), __METHOD__);

                return null;
            }

            $decoded = json_decode((string)$response, true, 512, JSON_THROW_ON_ERROR);

            return \is_array($decoded) ? $decoded : null;
        } catch (\Throwable $throwable) {
            Craft::error($throwable->getMessage(), __METHOD__);

            return null;
        }
    }

    /**
     * Purges paths from a Gumlet source.
     *
     * Gumlet purges by source path, which takes every derivative of that path with it, so
     * there's no need to purge transforms individually.
     *
     * @param string[] $paths Source relative paths, ie `folder/image.jpg`
     * @param string $sourceId The Gumlet source ID
     * @param string $apiKey Gumlet API key
     *
     * @see https://docs.gumlet.com/image/purge-cache
     */
    public function purgePathsFromGumlet(array $paths, string $sourceId, string $apiKey): void
    {
        if (empty($paths)) {
            return;
        }

        try {
            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ];

            $payload = json_encode(['paths' => array_values($paths)], JSON_THROW_ON_ERROR);

            $curl = curl_init(self::PURGE_ENDPOINT . $sourceId);

            curl_setopt_array($curl, [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_POST => 1,
                CURLOPT_RETURNTRANSFER => true,
            ]);

            $response = curl_exec($curl);
            $curlErrorNo = curl_errno($curl);
            $curlError = curl_error($curl);
            $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            $pathsString = implode(', ', $paths);

            if ($curlErrorNo !== 0) {
                $msg = Craft::t('imager-x-gumlet-transformer', 'A cURL error "{curlErrorNo}" encountered while attempting to purge "{paths}". The error was: "{curlError}"', ['paths' => $pathsString, 'curlErrorNo' => $curlErrorNo, 'curlError' => $curlError]);
                Craft::error($msg, __METHOD__);
            }

            if ($httpStatus !== 200) {
                $msg = Craft::t('imager-x-gumlet-transformer', 'An error occured when trying to purge "{paths}", status was "{httpStatus}" and response was "{response}"', ['paths' => $pathsString, 'httpStatus' => $httpStatus, 'response' => $response]);
                Craft::error($msg, __METHOD__);
            }
        } catch (\Throwable $throwable) {
            Craft::error($throwable->getMessage(), __METHOD__);
            // We don't continue to throw this error, since it could be caused by a duplicated request.
        }
    }

    /**
     * Purges an asset from every purgable Gumlet profile.
     *
     * @throws ImagerException
     */
    public function purgeAssetFromGumlet(Asset $asset): void
    {
        /** @var Settings $settings */
        $settings = GumletTransformer::$plugin->getSettings();

        $globalApiKey = $settings->getParsedApiKey();
        $profilesArr = $settings->profiles;

        if (empty($profilesArr)) {
            $msg = Craft::t('imager-x-gumlet-transformer', 'The `profiles` config setting is missing, or is not correctly set up.');
            Craft::error($msg, __METHOD__);
            throw new ImagerException($msg);
        }

        foreach ($profilesArr as $profileName => $profileConfig) {
            $gumletConfig = new GumletSettings($profileConfig);

            if ($gumletConfig->sourceIsWebProxy || $gumletConfig->excludeFromPurge) {
                continue;
            }

            $apiKey = $gumletConfig->apiKey ?: $globalApiKey;

            if (!$apiKey) {
                continue;
            }

            $sourceId = $this->getSourceId($gumletConfig, $apiKey);

            if ($sourceId === null || $sourceId === '') {
                Craft::warning(Craft::t('imager-x-gumlet-transformer', 'Could not purge from Gumlet profile "{profile}", its source ID could not be resolved.', ['profile' => $profileName]), __METHOD__);
                continue;
            }

            try {
                // The purge API takes raw, unencoded source paths
                $path = GumletHelpers::getSourcePath($asset, $gumletConfig);

                $this->purgePathsFromGumlet([$path], $sourceId, $apiKey);
            } catch (\Throwable $throwable) {
                Craft::error($throwable->getMessage(), __METHOD__);
                throw new ImagerException($throwable->getMessage(), $throwable->getCode(), $throwable);
            }
        }
    }
}
