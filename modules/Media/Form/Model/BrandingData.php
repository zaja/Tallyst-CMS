<?php

namespace Tallyst\Media\Form\Model;

use Tallyst\Media\Entity\Media;

/**
 * Edit-time DTO for the branding form. Persisted as Settings (site_name +
 * logo_media_id) — no dedicated table.
 */
class BrandingData
{
    public string $siteName = '';
    public ?Media $logo = null;
}
