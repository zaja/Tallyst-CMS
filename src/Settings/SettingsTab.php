<?php

namespace App\Settings;

/**
 * One routed Settings tab (`/admin/settings/{key}`). A tab wraps one or more
 * {@see SettingsSection}s. In A1 the mapping is 1:1 (each section is its own tab); the
 * grouping seam (SettingsRegistry::GROUPS) lets A2 consolidate several sections under one
 * tab WITHOUT losing any — an ungrouped section always falls back to its own tab.
 */
final readonly class SettingsTab
{
    /**
     * @param SettingsSection[] $sections
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public array $sections,
    ) {
    }

    /**
     * Every setting definition across this tab's sections (the per-tab form subset).
     *
     * @return SettingDefinition[]
     */
    public function definitions(): array
    {
        $defs = [];
        foreach ($this->sections as $section) {
            foreach ($section->definitions as $def) {
                $defs[] = $def;
            }
        }

        return $defs;
    }

    public function hasSection(string $sectionKey): bool
    {
        foreach ($this->sections as $section) {
            if ($section->key === $sectionKey) {
                return true;
            }
        }

        return false;
    }
}
