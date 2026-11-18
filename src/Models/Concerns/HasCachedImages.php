<?php

namespace Rishadblack\SyllooPosCore\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Rishadblack\SyllooPosCore\Services\ImageCache;

/**
 * A model whose ERP image URLs are kept locally by {@see ImageCache}.
 *
 * The model lists its image columns in `cachedImages()` (ERP URL column => local path column) and,
 * for galleries, `cachedImageLists()` (JSON list of URLs => JSON list of local paths).
 * `display_image` is what views show: the local copy, else the ERP URL, else null.
 */
trait HasCachedImages
{
    /**
     * @return array<string, string>
     */
    public static function cachedImages(): array
    {
        return ['image_url' => 'image_path'];
    }

    /**
     * @return array<string, string>
     */
    public static function cachedImageLists(): array
    {
        return [];
    }

    /**
     * Columns holding a local copy, single and list.
     *
     * @return list<string>
     */
    public static function cachedImageColumns(): array
    {
        return array_values([...static::cachedImages(), ...static::cachedImageLists()]);
    }

    /**
     * URL to show for an image: the local copy, then the ERP URL, then an older storage path.
     */
    public static function imageSource(?string $localPath, ?string $source): ?string
    {
        if (ImageCache::isCachedPath($localPath)) {
            return ImageCache::url($localPath);
        }

        if (blank($source)) {
            return null;
        }

        return ImageCache::isRemote($source) ? $source : asset_storage($source);
    }

    /**
     * The small copy to show on a grid tile (audit M5): the thumbnail of the local copy, else what display_image shows.
     */
    protected function displayThumb(): Attribute
    {
        return Attribute::get(function (): ?string {
            $source = array_key_first(static::cachedImages());
            $local = $this->getAttributeValue(static::cachedImages()[$source]);

            if (ImageCache::isCachedPath($local) && ($thumb = app(ImageCache::class)->thumbnail($local))) {
                return ImageCache::url($thumb);
            }

            return $this->display_image;
        });
    }

    /**
     * The image to show for this record.
     */
    protected function displayImage(): Attribute
    {
        return Attribute::get(function (): ?string {
            $source = array_key_first(static::cachedImages());

            return static::imageSource($this->getAttributeValue(static::cachedImages()[$source]), $this->getAttributeValue($source));
        });
    }
}
