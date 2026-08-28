# Gumlet transformer for Imager X

A plugin for using [Gumlet](https://www.gumlet.com/) as a transformer in Imager X.
Also, an example of [how to make a custom transformer for Imager X](https://imager-x.spacecat.ninja/extending.html#transformers).

## Requirements

This plugin requires Craft CMS 5.0.0 or later, [Imager X 6.0.0](https://github.com/spacecatninja/craft-imager-x/) or later,
and an account at [Gumlet](https://www.gumlet.com/).

## Usage

Install and configure this transformer as described below. Then, in your [Imager X config](https://imager-x.spacecat.ninja/configuration.html),
set the transformer to `gumlet`, ie:

```
'transformer' => 'gumlet',
```

Transforms are now by default transformed with Gumlet, test your configuration with a
simple transform like this:

```
{% set transform = craft.imagerx.transformImage(asset, { width: 600 }) %}
<img src="{{ transform.url }}" width="600">
<p>URL is: {{ transform.url }}</p>
```

If this doesn't work, make sure you've configured a `defaultProfile`, have a profile with the correct name, and
that the Gumlet source points to the location of your asset volume.

### Cave-ats, shortcomings, and tips

This transformer only supports a subset of what Imager X can do when using the default `craft` transformer.
All the basic transform parameters are supported, with the following exceptions:

- The `croponly` resize mode is not supported, and falls back to `fit`.
- Opacity for colors on the `letterbox` resize mode is not supported, Gumlet's `fill-color` has no alpha channel.
- No `effects` are converted automatically. Gumlet supports a good number of them (`blur`, `sharp`,
  `greyscale`, `gamma`, `contrast`, `brightness`, `saturation`, `hue`, `invert`, `tint`), you just have
  to pass them yourself through `transformerParams`.
- Watermarks are not translated automatically from Imager syntax to Gumlet's, but you can still add
  them by passing Gumlet's `overlay` params through `transformerParams`.
- `preEffects`, `frames`, `resizeFilter` and `customEncoderOptions` are not supported.

`pad` and `trim` *are* supported, with one caveat each:

- **`pad`**: Imager treats `width` and `height` as the final size including any padding, so the
  transformer shrinks the resize target to compensate. The output is the size you asked for.
- **`trim`**: Imager's `trim` is a `0.0`–`1.0` fuzz factor applied *before* resizing. Gumlet's is a
  `1`–`99` percentage similarity to the top left pixel, applied *after* resizing. The value is
  converted for you, but the result will not be identical to the `craft` transformer. Use Gumlet's
  `trim-color` through `transformerParams` to trim a specific color instead of the top left pixel.

To pass additional options directly to Gumlet, use the `transformerParams` transform parameter. Example:

```
{% set transforms = craft.imagerx.transformImage(asset,
    [{width: 400}, {width: 600}, {width: 800}],
    { ratio: 2/1, transformerParams: { sharp: 10, saturation: 20 } }
) %}
```

Note that Gumlet's own resize param is also called `mode`, so passing `mode` through
`transformerParams` will override the mode the transformer worked out from Imager's `mode`. This
is how you reach Gumlet's `min`, `max` and `fillmax` modes.

For more information, check out the [Gumlet documentation](https://docs.gumlet.com/image/introduction).

### Color palettes

Gumlet has a palette API, exposed on the transformed image model:

```
{% set transform = craft.imagerx.transformImage(asset, { width: 600 }) %}
{% set palette = transform.getPalette('json', 6) %}
```

Pass `'css'` as the first parameter, and a class prefix as the third, to get a string of CSS rules
instead. Results are cached.


## Installation

To install the plugin, follow these instructions:

1. Install with composer via `composer require spacecatninja/imager-x-gumlet-transformer` from your project directory.
2. Install the plugin in the Craft Control Panel under Settings > Plugins, or from the command line via `./craft plugin/install imager-x-gumlet-transformer`.


## Configuration

You can configure the transformer by creating a file in your config folder called
`imager-x-gumlet-transformer.php`, and override settings as needed.

The settings that hold secrets or environment specific values — `apiKey`, and the per-profile
`domain`, `signKey`, `sourceId`, `apiKey` and `addPath` — can be set to an environment variable
using Craft's `'$VARIABLE_NAME'` syntax, which is resolved for you:

```php
'apiKey' => '$GUMLET_API_KEY',
```

Note that a variable that isn't set resolves to an empty string, rather than being left as the
literal `'$GUMLET_API_KEY'`. Purging is skipped for a profile with no API key, so a missing
variable quietly disables purging rather than firing bad requests at Gumlet.

### profiles [array]
Default: `[]`
Profiles are usually a one-to-one mapping to the [sources](https://docs.gumlet.com/image/manage-sources/original-image-storage)
you've created in Gumlet. You set the default profile to use using the `defaultProfile` config setting,
and can override it at the template level by setting `profile` in your `transformerConfig`.

Example profile:

```
'profiles' => [
    'default' => [
        'domain' => 'mysource.gumlet.io',
        'useCloudSourcePath' => true,
    ],
    'proxy' => [
        'domain' => 'myproxysource.gumlet.io',
        'sourceIsWebProxy' => true,
    ]
],
```

Each profile has the following settings:

#### domain [string]
Default: `''`
The delivery domain for your Gumlet source, ie `mysource.gumlet.io`. If you've set up a
[custom domain](https://docs.gumlet.com/image/manage-sources/image-custom-domain), use that instead.

#### useHttps [bool]
Default: `true`

#### signKey [string]
Default: `''`
Your Gumlet source's secure token. Only needed if you've enabled "Secure URLs" for the source, in
which case it's required — Gumlet returns a `403` for every unsigned URL on that source.

#### signedUrlsExpireSeconds [int]
Default: `0`
Number of seconds a signed URL should be valid for. `0`, the default, means the URLs never expire.
Only has an effect when `signKey` is set. Note that expiring URLs change on every request, which
means they don't cache well — leave this at `0` unless you actually need it.

#### sourceId [string]
Default: `''`
Your Gumlet image source's ID — a 24 character hex string like `646df1c9173a4a2fcac180b4`, used
when purging. **You normally don't need to set this.** Gumlet doesn't show the ID anywhere in the
control panel, so when it's left empty the plugin looks it up by matching your profile's `domain`
against your sources, and caches the result for 24 hours. All that needs is an `apiKey`.

Set it explicitly if you'd rather the plugin didn't call the sources API, or if the lookup can't
work out which source a profile belongs to — which can happen with a custom domain. You can get
the value from the [sources API](https://docs.gumlet.com/reference/tag/image-sources/get/image/sources):

```
curl -s https://api.gumlet.com/v1/image/sources \
  -H "Authorization: Bearer YOUR_API_KEY"
```

Every entry in the returned `all_sources` array has an `id`, a `namespace` (the subdomain) and a
`subdomain` (the full delivery domain) to match it up by.

#### apiKey [string]
Default: `''`
A Gumlet API key for this specific profile, used for cache purging. If set, it takes precedence over
the top-level `apiKey` setting.

#### sourceIsWebProxy [bool]
Default: `false`
Indicates if the Gumlet source is a web proxy or not. If enabled, full URLs are passed to Gumlet
behind a `fetch/` path segment, instead of the relative path to the asset. Web proxy profiles are
never purged.

#### useCloudSourcePath [bool]
Default: `true`
If enabled, Imager will prepend the Craft file system path to the asset path, before adding it to the
Gumlet URL. This makes it possible to have one Gumlet source pulling images from many Craft file systems
when they are for instance on the same S3 bucket, but in different subfolders. This only works on file
systems that implement a path setting (AWS S3 and GCS does, local volumes does not).

#### addPath [string|array]
Default: `''`
Prepends a path to the asset's path. Can be useful if you have several volumes that you want to serve
with one Gumlet source. If this setting is an array, the key should be the volume handle, and the value
the path to add.

#### getExternalImageDimensions [bool]
Default: `true`
When transforming external URLs and paths, controls whether a local copy is downloaded to measure the
source image. With this off, dimensions for external images report `0 × 0`. Irrelevant for assets,
where the dimensions always come from Craft.

#### defaultParams [array]
Default: `[]`
Default params to be passed to every transform made with this profile.

#### excludeFromPurge [bool]
Default: `false`
Set to `true` to exclude this profile from cache purging.

### defaultProfile [string]
Default: `'default'`
Sets the default profile to use (see `profiles`). You can override the profile at the transform level
by setting it through `transformerConfig`. Example:

```
{% set transforms = craft.imagerx.transformImage(asset,
    [{width: 800}, {width: 2000}],
    { transformerConfig: { profile: 'myotherprofile' } }
) %}
```

### apiKey [string]
Default: `''`
A global [Gumlet API key](https://docs.gumlet.com/developers/api-keys) used for cache purging across
all profiles. Can be overridden per-profile using the `apiKey` profile setting.

### autoPurge [bool]
Default: `false`
When enabled, assets are automatically purged from Gumlet when they are replaced, deleted, or moved in
Craft (the latter requires [`removeTransformsOnAssetFileops`](https://imager-x.spacecat.ninja/configuration.html#removetransformsonassetfileops)
to be enabled in your Imager X config). Requires at least one purgable profile to have an API key.

### purgeElementAction [bool]
Default: `true`
When enabled, a "Purge from Gumlet" action is added to the asset index element actions menu, allowing
editors to manually purge selected images. Requires at least one purgable profile to have an API key.


## Purging

Gumlet caches your *original* images for up to three months. If you replace a file at the same path,
Gumlet keeps serving the old one until it's purged — so purging matters more here than with some other
services.

Gumlet purges by source path, and that takes every derivative of that path with it, so transforms don't
need to be purged individually.

All purging needs is a [Gumlet API key](https://docs.gumlet.com/developers/api-keys). Set it globally
via the top-level `apiKey` config setting, or per-profile using the `apiKey` profile setting.

The purge endpoint is addressed by source ID rather than by domain, but you don't have to go and find
that yourself — the plugin resolves it from the profile's `domain` the first time it purges, and caches
it for 24 hours. Set [`sourceId`](#sourceid-string) explicitly to skip that lookup.

💡 Gumlet's own purge documentation still points at an older `POST /v1/purge/{subdomain}` endpoint,
which their API reference marks as deprecated. This plugin uses the current
`POST /v1/image/purge/{source_id}` endpoint, which is why it asks for a source ID and not a subdomain.

A minimal purge-enabled config looks like this:

```php
<?php

return [
    'defaultProfile' => 'default',
    'apiKey' => 'your-gumlet-api-key',
    'autoPurge' => true,
    'profiles' => [
        'default' => [
            'domain' => 'mysource.gumlet.io',
        ],
    ],
];
```

Note that purging an entire source in one go is a Business/Enterprise feature at Gumlet. This plugin
only ever purges individual paths, which is available on all plans.


Price, license and support
---
The plugin is released under the MIT license. It requires Imager X, which is a commercial
plugin [available in the Craft plugin store](https://plugins.craftcms.com/imager-x). If you
need help, or found a bug, please post an issue in this repo, or in Imager X' repo (preferably).
