<?php

namespace App\Settings;

/**
 * Aggregates every tagged SettingsSectionProviderInterface into the ordered list of
 * sections + a flat key->definition lookup. Mirrors the ShortcodeRegistry / ModuleRegistry
 * IoC pattern: Core and modules contribute sections; nothing here knows the concrete
 * providers.
 */
class SettingsRegistry
{
    /**
     * Tab grouping (A2): a tab key => the section keys (in order) it consolidates, plus its
     * own label/icon. A grouped tab renders each contained section as a labelled sub-section.
     *
     * ⚠ The fallback is the IoC contract: a section NOT listed here (email/stripe/paypal/
     * tax/orders today, and any dynamically contributed module section) is never lost — it
     * always gets its own 1:1 tab (see getTabs()). Grouping is opt-in; ungrouped is the safe
     * default. 13 sections → 8 tabs (3 grouped + 5 standalone).
     *
     * ⚠ A tab key MAY equal a contained section key (e.g. 'general', 'branding') — harmless:
     * getTab() matches tab keys, tabKeyForSection() matches section membership; the two
     * lookups never mix. 'header_footer' is a distinct key (no same-named section).
     *
     * @var array<string, array{label: string, icon: string, sections: string[]}>
     */
    private const GROUPS = [
        'general' => [
            'label' => 'admin.settings.general.title',
            'icon' => 'fa-sliders',
            'sections' => ['general', 'blog', 'localization', 'maintenance'],
        ],
        'branding' => [
            'label' => 'admin.settings.branding.title',
            'icon' => 'fa-palette',
            'sections' => ['branding', 'typography'],
        ],
        'header_footer' => [
            'label' => 'admin.settings.header_footer.title',
            'icon' => 'fa-window-maximize',
            'sections' => ['topbar', 'footer'],
        ],
    ];

    /** @var SettingsSection[]|null */
    private ?array $sections = null;

    /** @var SettingsTab[]|null */
    private ?array $tabs = null;

    /** @var array<string, SettingDefinition>|null */
    private ?array $definitions = null;

    /**
     * @param iterable<SettingsSectionProviderInterface> $providers
     */
    public function __construct(private readonly iterable $providers = [])
    {
    }

    /**
     * @return SettingsSection[]
     */
    public function getSections(): array
    {
        if (null === $this->sections) {
            $this->sections = [];
            foreach ($this->providers as $provider) {
                foreach ($provider->getSettingsSections() as $section) {
                    $this->sections[] = $section;
                }
            }
        }

        return $this->sections;
    }

    /**
     * The ordered routed tabs. Each ungrouped section is its own tab (A1 = all of them);
     * a grouped tab is emitted once, at the position of its first section, carrying every
     * present section it lists — so section order is preserved and nothing is dropped.
     *
     * @return SettingsTab[]
     */
    public function getTabs(): array
    {
        if (null !== $this->tabs) {
            return $this->tabs;
        }

        $byKey = [];
        foreach ($this->getSections() as $section) {
            $byKey[$section->key] = $section;
        }

        // section key => the tab key that claims it (only for grouped sections).
        $groupOf = [];
        foreach (self::GROUPS as $tabKey => $group) {
            foreach ($group['sections'] as $sectionKey) {
                $groupOf[$sectionKey] = $tabKey;
            }
        }

        $tabs = [];
        $emitted = [];
        foreach ($this->getSections() as $section) {
            $tabKey = $groupOf[$section->key] ?? null;

            if (null === $tabKey) {
                // FALLBACK: an ungrouped section is its own tab (A1, and the safety net for
                // any future dynamic module section not listed in GROUPS).
                $tabs[] = new SettingsTab($section->key, $section->label, $section->icon, [$section]);
                continue;
            }

            if (isset($emitted[$tabKey])) {
                continue; // the grouped tab was already emitted at its first section
            }
            $emitted[$tabKey] = true;

            $group = self::GROUPS[$tabKey];
            $grouped = [];
            foreach ($group['sections'] as $sectionKey) {
                if (isset($byKey[$sectionKey])) {
                    $grouped[] = $byKey[$sectionKey];
                }
            }
            $tabs[] = new SettingsTab($tabKey, $group['label'], $group['icon'], $grouped);
        }

        return $this->tabs = $tabs;
    }

    public function getTab(string $key): ?SettingsTab
    {
        foreach ($this->getTabs() as $tab) {
            if ($tab->key === $key) {
                return $tab;
            }
        }

        return null;
    }

    public function firstTabKey(): ?string
    {
        return $this->getTabs()[0]->key ?? null;
    }

    /**
     * The tab key that renders a given section (for links/redirects that target a section,
     * e.g. the test-mail form → the tab holding the `email` section). Null if absent.
     */
    public function tabKeyForSection(string $sectionKey): ?string
    {
        foreach ($this->getTabs() as $tab) {
            if ($tab->hasSection($sectionKey)) {
                return $tab->key;
            }
        }

        return null;
    }

    public function getDefinition(string $key): ?SettingDefinition
    {
        return $this->allDefinitions()[$key] ?? null;
    }

    /**
     * @return array<string, SettingDefinition>
     */
    public function allDefinitions(): array
    {
        if (null === $this->definitions) {
            $this->definitions = [];
            foreach ($this->getSections() as $section) {
                foreach ($section->definitions as $def) {
                    $this->definitions[$def->key] = $def;
                }
            }
        }

        return $this->definitions;
    }
}
