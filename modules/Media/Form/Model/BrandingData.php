<?php

namespace Tallyst\Media\Form\Model;

use Tallyst\Media\Entity\Media;

/**
 * Edit-time DTO for the branding form. Branding owns only the visual identity (the logo,
 * persisted as the logo_media_id Setting) — the site NAME lives in the Core General
 * settings (single editable home), so it is not part of this form.
 */
class BrandingData
{
    public ?Media $logo = null;
}
