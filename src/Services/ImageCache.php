<?php

namespace Rishadblack\SyllooPosCore\Services;

use Rishadblack\SyllooAppBase\Images\ImageCache as BaseImageCache;

/**
 * Keeps a local copy of the images the ERP sends (absolute URLs), so the terminal shows them offline. The rules are
 * the shared base's ({@see BaseImageCache}): https from the ERP's hosts only, a copy named after the SHA-1 of the full
 * URL (the ERP's `?v=<hash>` makes a changed image a new file), at most MAX_BYTES, known image types only. Copies are
 * served by the till's sessionless `erp_image` route, since the packaged app has no storage link.
 */
class ImageCache extends BaseImageCache {}
