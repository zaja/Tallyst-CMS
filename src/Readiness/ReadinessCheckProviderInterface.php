<?php

namespace App\Readiness;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Contributes readiness checks to the panel. Auto-tagged (like SettingsSectionProviderInterface /
 * DashboardWidgetInterface) so Core AND modules can add checks without Core depending on them —
 * Core ships config/infra checks; a module (FormBuilder) can add its own.
 */
#[AutoconfigureTag('app.readiness_check')]
interface ReadinessCheckProviderInterface
{
    /**
     * @return iterable<Check>
     */
    public function getChecks(): iterable;
}
