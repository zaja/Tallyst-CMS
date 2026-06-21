<?php

namespace Tallyst\Media\Service;

/**
 * Thrown by MediaUploader when an uploaded file fails validation (not a raster image,
 * too large, …). Carries a human message safe to surface to the uploader.
 */
class MediaUploadException extends \RuntimeException
{
}
