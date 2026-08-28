<?php
/**
 * Gumlet transformer for Imager X
 *
 * @link      https://www.spacecat.ninja
 * @copyright Copyright (c) 2026 André Elvan
 */

namespace spacecatninja\gumlettransformer\helpers;

use Craft;
use craft\elements\Asset;
use craft\fs\Local;
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\helpers\UrlHelper;

use spacecatninja\gumlettransformer\models\GumletSettings;
use spacecatninja\imagerx\exceptions\ImagerException;

use yii\base\InvalidConfigException;

class GumletHelpers
{
    /**
     * Returns the URL encoded path to use in a Gumlet URL.
     *
     * Web proxy sources take the full, URL encoded source URL after a literal `fetch/` segment.
     * Everything else gets the asset's path, relative to the Gumlet source.
     *
     * @throws ImagerException|InvalidConfigException
     */
    public static function getGumletFilePath(Asset|string $image, GumletSettings $config): string
    {
        if (\is_string($image)) {
            if ($config->sourceIsWebProxy) {
                return self::getWebProxyPath($image);
            }

            if (UrlHelper::isAbsoluteUrl($image) || UrlHelper::isProtocolRelativeUrl($image)) {
                Craft::warning('A full URL was passed to Gumlet, but the profile it was transformed with is not a web proxy source. Set the profile\'s `sourceIsWebProxy` setting to transform external images. The URL was: ' . $image, __METHOD__);
            }

            // Just pass the string along, we have to assume the user knows what they're doing.
            return ltrim($image, '/');
        }

        if ($config->sourceIsWebProxy) {
            return self::getWebProxyPath($image->getUrl() ?? '');
        }

        return self::encodePath(self::getSourcePath($image, $config));
    }

    /**
     * Returns the raw, unencoded path to the asset, relative to the Gumlet source.
     *
     * This is what the purge API expects, and what `getGumletFilePath()` encodes.
     *
     * @throws ImagerException|InvalidConfigException
     */
    public static function getSourcePath(Asset $image, GumletSettings $config): string
    {
        try {
            $volume = $image->getVolume();
            $fs = $volume->getFs();
        } catch (InvalidConfigException $invalidConfigException) {
            Craft::error($invalidConfigException->getMessage(), __METHOD__);
            throw new ImagerException($invalidConfigException->getMessage(), $invalidConfigException->getCode(), $invalidConfigException);
        }

        if ($config->useCloudSourcePath && property_exists($fs, 'subfolder') && $fs::class !== Local::class) {
            $path = implode('/', array_filter([
                App::parseEnv($fs->subfolder),
                App::parseEnv($volume->getSubpath()),
                $image->getPath(),
            ]));
        } else {
            $path = $image->getPath();
        }

        if (!empty($config->addPath)) {
            if (\is_string($config->addPath) && $config->addPath !== '') {
                $path = implode('/', [$config->addPath, $path]);
            } elseif (\is_array($config->addPath) && isset($config->addPath[$volume->handle])) {
                $path = implode('/', [$config->addPath[$volume->handle], $path]);
            }
        }

        $path = FileHelper::normalizePath($path);

        // Always use forward slashes for Gumlet
        return ltrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * Builds a Gumlet URL, and signs it if the profile has a sign key.
     *
     * Gumlet signs the MD5 of `<token>/<path>?<query>`. Note that the domain is not part
     * of the signed string, and that the path and query need to be byte identical to what
     * ends up in the URL.
     *
     * @see https://docs.gumlet.com/image/manage-sources/signed-urls-image
     */
    public static function buildUrl(string $path, array $params, GumletSettings $config): string
    {
        $path = ltrim($path, '/');

        if ($config->signKey !== '' && $config->signedUrlsExpireSeconds > 0) {
            $params['expires'] = time() + $config->signedUrlsExpireSeconds;
        }

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        // Commas are legal in a query value, and Gumlet documents its list params (`pad`,
        // `extract`) with literal ones. Leaving them encoded risks the signature being checked
        // against a decoded query on their end.
        $query = str_replace('%2C', ',', $query);

        $url = ($config->useHttps ? 'https' : 'http') . '://' . self::getDomain($config) . '/' . $path;

        if ($query !== '') {
            $url .= '?' . $query;
        }

        if ($config->signKey !== '') {
            $signature = md5($config->signKey . '/' . $path . '?' . $query);
            $url .= ($query !== '' ? '&' : '?') . 's=' . $signature;
        }

        return $url;
    }

    /**
     * Normalizes the profile domain, tolerating a scheme and/or a trailing slash.
     */
    public static function getDomain(GumletSettings $config): string
    {
        $domain = $config->domain;

        if (str_contains($domain, '//')) {
            $domain = substr($domain, strpos($domain, '//') + 2);
        }

        return rtrim($domain, '/');
    }

    /**
     * Resolves an environment variable, always to a string.
     *
     * Craft doesn't expand `$SOME_VAR` in plugin config files by itself, so any setting that
     * might hold a secret or an environment specific value goes through this. A plain string
     * passes through untouched. `App::parseEnv()` can hand back a bool, or null for an env var
     * that isn't set; neither is meaningful here, so both become an empty string.
     */
    public static function parseEnvString(mixed $value): string
    {
        $parsed = App::parseEnv(\is_string($value) ? $value : null);

        return \is_string($parsed) ? $parsed : '';
    }

    /**
     * URL encodes each path segment, leaving the slashes alone.
     */
    public static function encodePath(string $path): string
    {
        $segments = explode('/', ltrim($path, '/'));

        return implode('/', array_map(static fn(string $segment): string => rawurlencode($segment), $segments));
    }

    /**
     * Normalizes a color for Gumlet, which wants hex without a leading `#`, or a CSS color name.
     *
     * Gumlet has no alpha channel on any of its color params, so an alpha component is dropped.
     * Alpha is taken to be last, as in CSS `#rgba` and `#rrggbbaa` — note that this is the
     * opposite of imgix, which puts it first.
     */
    public static function normalizeColor(string $color): string
    {
        $color = ltrim(trim($color), '#');

        if ($color === '' || !ctype_xdigit($color)) {
            // Assume a CSS color name, and leave it alone
            return $color;
        }

        return match (\strlen($color)) {
            3 => $color[0] . $color[0] . $color[1] . $color[1] . $color[2] . $color[2],
            4 => $color[0] . $color[0] . $color[1] . $color[1] . $color[2] . $color[2],
            8 => substr($color, 0, 6),
            default => $color,
        };
    }

    /**
     * GETs a URL and returns the body, or null if the request didn't succeed.
     *
     * Uses cURL rather than `file_get_contents()` so this keeps working on hosts that have
     * `allow_url_fopen` turned off.
     */
    public static function fetchUrl(string $url, int $timeout = 30): ?string
    {
        if (!\function_exists('curl_init')) {
            $body = @file_get_contents($url);

            return \is_string($body) ? $body : null;
        }

        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $body = curl_exec($curl);
        $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if (!\is_string($body) || $httpStatus !== 200) {
            return null;
        }

        return $body;
    }

    /**
     * Gumlet web proxy sources take the full source URL, URL encoded, after a `fetch/` segment.
     * It has to be absolute — a root relative asset URL is no use to Gumlet.
     */
    private static function getWebProxyPath(string $url): string
    {
        if ($url !== '' && !UrlHelper::isAbsoluteUrl($url) && !UrlHelper::isProtocolRelativeUrl($url)) {
            $url = UrlHelper::siteUrl($url);
        }

        return 'fetch/' . rawurlencode($url);
    }
}
