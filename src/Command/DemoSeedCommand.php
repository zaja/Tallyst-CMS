<?php

namespace App\Command;

use App\Entity\Category;
use App\Entity\Menu;
use App\Entity\MenuItem;
use App\Entity\Page;
use App\Entity\Post;
use App\Entity\Theme;
use App\Entity\User;
use App\Install\BaselineSeeder;
use App\Repository\CategoryRepository;
use App\Repository\MenuRepository;
use App\Repository\PageRepository;
use App\Repository\PostRepository;
use App\Repository\ThemeRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormField;
use Tallyst\FormBuilder\Entity\FormSubmission;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Repository\FormSubmissionRepository;
use Tallyst\FormBuilder\Repository\OrderRepository;
use Tallyst\FormBuilder\Repository\FormDefinitionRepository;
use App\Settings\SettingsManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tallyst\Media\Entity\Media;
use Tallyst\Media\Repository\MediaRepository;
use Tallyst\Media\Service\MediaUploader;

/**
 * Seeds the "Arca Backup" DEMO — a realistic marketing + docs + blog site for a fictional
 * one-time-purchase Windows backup app, so the whole front-end (Tema v2) can be judged as a lens:
 * 8 pages (home landing, features, buy/page-as-product, docs + install + faq, about, contact) with
 * 3 hero overlays, 9 blog posts in 4 categories, a 2-level main menu, a 3-column footer with two
 * footer menus, two forms (contact + Arca Pro buy), and 16 committed PNG illustrations in Media.
 * Every editor construct is exercised: display headings, [icon] chips, tinted/plain/pricing cards
 * with a highlighted column, pill buttons, [image], [form id=N], columns, code blocks.
 *
 * Content is the SOURCE OF TRUTH in demo_content/arca-demo-spec.md, transcribed into the content*()
 * heredoc methods below. Images are COMMITTED PNGs in demo_content/images/ (tallyst-demo-<name>.png).
 *
 * SEPARATE from app:install (which seeds the minimal baseline). Re-runnable:
 *  - default: additive — creates anything missing by its fixed slug, skips what exists,
 *    and ALWAYS (re)builds the menus (the demo owns them). No duplication on re-run.
 *    ⚠ Existing rows are NOT updated — change content? use --fresh.
 *  - --fresh: deletes the whole demo set first, then recreates it (the supported full-reset path).
 *  - --clear: deletes the demo set and stops (the uninstall path).
 *  - --unflag: clears is_demo everywhere (make it permanent); the uninstaller no longer touches it.
 *
 * Cleanup is FLAG-based (clearDemo): it removes exactly the rows carrying is_demo=true (incl.
 * runtime orders/submissions placed through demo forms) and resets the footer/favicon settings the
 * seed wrote, so real content — and any demo content whose flag was removed — is never harmed. The
 * demo author is the one exception, identified by its fixed e-mail.
 */
#[AsCommand(name: 'app:demo:seed', description: 'Seed the Arca Backup demo (pages, hero, posts, menus, 3-col footer, forms, images) to preview the front-end.')]
class DemoSeedCommand extends Command
{
    private const MEDIA_PREFIX = 'tallyst-demo-';
    private const MENU_LOCATION = 'main';
    private const FOOTER_PRODUCT_LOCATION = 'footer-product';
    private const FOOTER_RESOURCES_LOCATION = 'footer-resources';

    /** Committed PNG illustrations in demo_content/images/ (tallyst-demo-<name>.png). */
    private const MEDIA_NAMES = [
        'home-hero', 'features-flow', 'features-db',
        'features-hero', 'buy-hero', 'about-hero',
        'team-1', 'team-2', 'team-3', 'team-4',
        'blog-1', 'blog-2', 'blog-3', 'blog-4', 'blog-5', 'blog-6', 'blog-7', 'blog-8', 'blog-9',
    ];

    /** Dedicated demo author (so the real admin's nickname is never touched). */
    private const AUTHOR_EMAIL = 'demo-author@tallyst.local';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ThemeRepository $themes,
        private readonly PageRepository $pages,
        private readonly PostRepository $posts,
        private readonly CategoryRepository $categories,
        private readonly MenuRepository $menus,
        private readonly MediaRepository $mediaRepo,
        private readonly FormDefinitionRepository $forms,
        private readonly OrderRepository $orders,
        private readonly FormSubmissionRepository $submissions,
        private readonly MediaUploader $uploader,
        private readonly SettingsManager $settings,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly BaselineSeeder $baseline,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('fresh', null, InputOption::VALUE_NONE, 'Delete the existing demo set first, then recreate it (full reset).');
        $this->addOption('clear', null, InputOption::VALUE_NONE, 'Delete the demo set and stop (do NOT recreate it). The uninstall path.');
        $this->addOption('unflag', null, InputOption::VALUE_NONE, 'Clear the is_demo flag from all demo content (make it permanent); the uninstaller no longer touches it.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // --unflag = make demo permanent: clear is_demo everywhere, then stop. The uninstaller can no
        // longer remove this content (it deletes by the flag). A one-way, conscious trade-off.
        if ($input->getOption('unflag')) {
            $io->section('Removing the demo flag (content becomes permanent)');
            $n = $this->unflagDemo();
            $io->success(sprintf('Demo flag removed from %d records. The content is now permanent.', $n));

            return Command::SUCCESS;
        }

        // --clear = uninstall: delete the demo set by its is_demo flag, then stop (no reseed).
        if ($input->getOption('clear')) {
            $io->section('Deleting demo content (--clear)');
            $this->clearDemo($io);
            $io->success('Demo content deleted.');

            return Command::SUCCESS;
        }

        if ($input->getOption('fresh')) {
            $io->section('Deleting the existing demo (--fresh)');
            $this->clearDemo($io);
        }

        $this->ensureActiveTheme($io);

        $io->section('Images (loaded from demo_content/images/)');
        $media = $this->ensureMedia($io);

        $io->section('Forms');
        $forms = $this->ensureForms($io);

        $io->section('Categories');
        $categories = $this->ensureCategories($io);

        $io->section('Demo author');
        $author = $this->ensureDemoAuthor($io);

        $io->section('Pages');
        $pages = $this->ensurePages($io, $media, $forms);

        $io->section('Posts');
        $this->ensurePosts($io, $media, $categories, $author);

        $io->section('Menus (main 2-level + 2 footer menus) — the demo always rebuilds them');
        $this->rebuildMenus($io, $pages);

        $io->section('Footer + favicon settings');
        $this->ensureSiteSettings($io);

        $this->em->flush();

        $io->success('The Arca demo has been seeded. Run "app:media:thumbnails:warm" if thumbnails are missing, and view the front at "/".');

        return Command::SUCCESS;
    }

    // ----------------------------------------------------------------- cleanup ---

    private function clearDemo(SymfonyStyle $io): void
    {
        // FLAG-based: remove EXACTLY the rows carrying is_demo=true — this also catches runtime
        // orders/submissions placed through demo forms and demo content the admin renamed, while
        // SPARING any content whose demo flag was removed (made permanent → is_demo=false).
        //
        // Order = children before parents so the Doctrine UnitOfWork stays consistent. (Every demo
        // FK is CASCADE or SET NULL — nothing is RESTRICT — so deletion can't fail; the explicit
        // order just keeps the UoW clean.)
        $removed = 0;
        $removed += $n = $this->removeAll($this->orders->findBy(['isDemo' => true]));
        $io->writeln(sprintf('• Orders deleted: %d.', $n));
        $removed += $n = $this->removeAll($this->submissions->findBy(['isDemo' => true]));
        $io->writeln(sprintf('• Form submissions deleted: %d.', $n));
        // Menus cascade their MenuItems (ORM cascade + orphanRemoval).
        $removed += $n = $this->removeAll($this->menus->findBy(['isDemo' => true]));
        $io->writeln(sprintf('• Menus deleted: %d.', $n));
        // Pages: any remaining MenuItem pointing at them cascades (DB onDelete); menus already gone.
        $removed += $n = $this->removeAll($this->pages->findBy(['isDemo' => true]));
        $io->writeln(sprintf('• Pages deleted: %d.', $n));
        // Posts reference Category via SET NULL — order-independent, but kept before categories.
        $removed += $n = $this->removeAll($this->posts->findBy(['isDemo' => true]));
        $io->writeln(sprintf('• Posts deleted: %d.', $n));
        $removed += $n = $this->removeAll($this->categories->findBy(['isDemo' => true]));
        $io->writeln(sprintf('• Categories deleted: %d.', $n));
        // Forms: their orders/submissions/fields are already cleared above; cascade is the safety-net.
        $removed += $n = $this->removeAll($this->forms->findBy(['isDemo' => true]));
        $io->writeln(sprintf('• Forms deleted: %d.', $n));
        // Media last: every reference to it is SET NULL (real content survives); Vich deletes the files.
        $removed += $n = $this->removeAll($this->mediaRepo->findBy(['isDemo' => true]));
        $io->writeln(sprintf('• Images deleted: %d.', $n));

        $this->em->flush();

        // Only touch the demo author + settings when we ACTUALLY removed flagged content. After --unflag
        // (nothing is flagged → $removed === 0) a --clear must be a true no-op: the now-permanent content,
        // its byline author, and the footer/favicon settings the admin chose to keep are all left alone.
        if (0 === $removed) {
            $io->writeln('• No demo content to delete (nothing is flagged as demo).');

            return;
        }

        // The demo author is identified by its FIXED e-mail, never a flag (User is auth-sensitive).
        if (null !== $author = $this->users->findOneBy(['email' => self::AUTHOR_EMAIL])) {
            $this->em->remove($author);
            $this->em->flush();
            $io->writeln('• Demo author deleted.');
        }

        // Reset the footer/favicon settings the seed wrote back to their schema defaults. The active
        // THEME is deliberately NOT touched — the site always needs a working theme.
        $this->resetDemoSettings($io);

        // The demo home (is_demo=true, adopted from the install baseline by ensurePages) was just
        // deleted with the other demo pages → restore the install baseline home so the front keeps
        // a persisted, EDITABLE home (symmetry with a clean install; otherwise PageController::home()
        // falls back to a transient, non-editable welcome). Idempotent: BaselineSeeder skips if a
        // 'home' page still exists (a user's own, or a permanent one).
        $this->baseline->ensureHomePage($io);
        $this->em->flush();
    }

    /**
     * @param object[] $entities
     */
    private function removeAll(array $entities): int
    {
        foreach ($entities as $entity) {
            $this->em->remove($entity);
        }

        return count($entities);
    }

    /**
     * Restore every footer/favicon key the seed wrote to its schema default. ⚠ Every key set in
     * ensureSiteSettings() MUST be reset here (footer_col3_menu included) or Delete demo leaves a
     * stray column. Only these fixed keys — no other settings, never the theme.
     */
    private function resetDemoSettings(SymfonyStyle $io): void
    {
        $this->settings->setMany([
            'footer_columns' => '2',
            // Clear the FULL per-column state (all 4 menu + text keys) so no column lingers.
            'footer_col1_menu' => '',
            'footer_col1_text' => '',
            'footer_col2_menu' => '',
            'footer_col2_text' => '',
            'footer_col3_menu' => '',
            'footer_col3_text' => '',
            'footer_col4_menu' => '',
            'footer_col4_text' => '',
            'footer_copyright' => '',
            'footer_show_powered_by' => true,
            'favicon_media_id' => '',
            // Top bar back to OFF + cleared (symmetry with the footer reset — demo delete leaves a
            // clean top bar, not a lingering Arca announcement).
            'top_bar_enabled' => false,
            'top_bar_text' => '',
            'social_github_url' => '',
            'social_youtube_url' => '',
            'social_x_url' => '',
            'social_linkedin_url' => '',
            'footer_menu' => '',
            'footer_text' => '',
        ]);
        $io->writeln('• Reset footer + top bar + favicon settings to defaults.');
    }

    /**
     * Clear is_demo on EVERY demo row across all 8 entities, making the content permanent. Because the
     * whole set flips at once, a demo form and its demo orders/submissions become real TOGETHER — no
     * selective cascade is needed. After this, clearDemo (the uninstaller) finds nothing to delete.
     */
    private function unflagDemo(): int
    {
        $total = 0;
        foreach ([Page::class, Post::class, Category::class, Menu::class, Media::class, FormDefinition::class, Order::class, FormSubmission::class] as $class) {
            $total += (int) $this->em->createQuery(sprintf('UPDATE %s e SET e.isDemo = false WHERE e.isDemo = true', $class))->execute();
        }

        return $total;
    }

    // ------------------------------------------------------------------- theme ---

    private function ensureActiveTheme(SymfonyStyle $io): void
    {
        if (null !== $this->themes->findOneBy(['active' => true])) {
            return;
        }

        $theme = $this->themes->findOneBy(['name' => 'default']) ?? new Theme('default', 'Default');
        $theme->setActive(true);
        $this->em->persist($theme);
        $this->em->flush();
        $io->writeln('• Aktivirana default tema.');
    }

    // ------------------------------------------------------------------- media ---

    /**
     * Load the committed PNG illustrations from demo_content/images/ into Media, name-keyed.
     *
     * ⚠ VICH MOVE TRAP: MediaUploader::upload() lets Vich MOVE the uploaded file into managed
     * storage. Passing the committed original directly would DELETE it from the repo on the first
     * seed. So we upload a TEMP COPY — Vich moves the copy; the committed source survives.
     *
     * Idempotent by originalName (tallyst-demo-<name>.png); a missing file is warned + skipped
     * (the [image]/hero reference then renders nothing — null-safe — rather than crashing the seed).
     *
     * @return array<string, Media> name => Media
     */
    private function ensureMedia(SymfonyStyle $io): array
    {
        $dir = $this->projectDir.'/demo_content/images/';
        $media = [];
        foreach (self::MEDIA_NAMES as $name) {
            $fileName = self::MEDIA_PREFIX.$name.'.png';
            if (null !== $existing = $this->mediaRepo->findOneBy(['originalName' => $fileName])) {
                $media[$name] = $existing;
                continue;
            }

            $src = $dir.$fileName;
            if (!is_file($src)) {
                $io->warning('Demo image missing: '.$src.' (skipping).');
                continue;
            }

            // Copy to a temp file and upload THAT — Vich moves the temp, the committed source stays.
            $tmp = sys_get_temp_dir().'/'.self::MEDIA_PREFIX.$name.'-'.bin2hex(random_bytes(4)).'.png';
            copy($src, $tmp);
            try {
                $m = $this->uploader->upload(new UploadedFile($tmp, $fileName, 'image/png', null, true));
            } finally {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }
            $m->setTitle('Arca demo — '.$name)->setAlt($this->altFor($name))->setIsDemo(true);
            $media[$name] = $m;
            $io->writeln('• Loaded '.$fileName);
        }
        $this->em->flush();

        return $media;
    }

    private function altFor(string $name): string
    {
        return match ($name) {
            'home-hero' => 'The Arca app showing a backup in progress',
            'features-flow' => 'Files split into chunks, compressed, and uploaded to the cloud',
            'features-db' => 'Database tables being exported to a consistent snapshot',
            'features-hero', 'buy-hero', 'about-hero' => 'Abstract Arca illustration',
            'team-1', 'team-2', 'team-3', 'team-4' => 'Arca team member portrait',
            default => 'Arca blog illustration',
        };
    }

    // ------------------------------------------------------------------- forms ---

    /**
     * Two demo forms: a free Contact form and the priced Arca Pro "buy" form (page-as-product).
     * Both is_demo=true, idempotent by slug.
     *
     * @return array{kontakt: FormDefinition, pro: FormDefinition}
     */
    private function ensureForms(SymfonyStyle $io): array
    {
        $kontakt = $this->forms->findOneBy(['slug' => 'demo-kontakt']);
        if (null === $kontakt) {
            $kontakt = (new FormDefinition())
                ->setName('Contact')
                ->setSlug('demo-kontakt')
                ->setStatus(FormDefinition::STATUS_PUBLISHED)
                ->setDescription('Send us a message — we usually reply within one business day.')
                ->setIsDemo(true);
            $this->addField($kontakt, FormField::TYPE_TEXT, 'name', 'Name', true, 0);
            $this->addField($kontakt, FormField::TYPE_EMAIL, 'email', 'Email', true, 1);
            $this->addField($kontakt, FormField::TYPE_TEXTAREA, 'message', 'Message', true, 2);
            $this->em->persist($kontakt);
            $io->writeln('• Created the "Contact" form (free).');
        } else {
            $io->writeln('• The "Contact" form already exists.');
        }

        $pro = $this->forms->findOneBy(['slug' => 'demo-pro-licenca']);
        if (null === $pro) {
            $pro = (new FormDefinition())
                ->setName('Arca Pro')
                ->setSlug('demo-pro-licenca')
                ->setStatus(FormDefinition::STATUS_PUBLISHED)
                ->setPriceMinor(2900)
                ->setCurrency('EUR')
                ->setDescription('Buy an Arca Pro license — €29 once, no subscription. We\'ll email your license key.')
                ->setIsDemo(true);
            $this->addField($pro, FormField::TYPE_TEXT, 'name', 'Name', true, 0);
            $this->addField($pro, FormField::TYPE_EMAIL, 'email', 'Email (where we\'ll send your license)', true, 1);
            $this->addField($pro, FormField::TYPE_TEXT, 'company', 'Company (optional)', false, 2);
            $this->em->persist($pro);
            $io->writeln('• Created the "Arca Pro" form (€29, page-as-product).');
        } else {
            $io->writeln('• The "Arca Pro" form already exists.');
        }

        $this->em->flush();

        return ['kontakt' => $kontakt, 'pro' => $pro];
    }

    private function addField(FormDefinition $form, string $type, string $key, string $label, bool $required, int $position): void
    {
        $field = (new FormField())
            ->setType($type)
            ->setKey($key)
            ->setLabel($label)
            ->setRequired($required)
            ->setPosition($position);
        $form->addField($field);
    }

    // -------------------------------------------------------------- categories ---

    /**
     * @return array<string, Category>
     */
    private function ensureCategories(SymfonyStyle $io): array
    {
        // [slug => [name, description]]
        $defs = [
            'releases' => ['Releases', 'Product releases and what\'s new in Arca.'],
            'engineering' => ['Engineering', 'How Arca works under the hood — formats, storage, databases.'],
            'guides' => ['Guides', 'Practical, step-by-step advice for backing up well.'],
            'company' => ['Company', 'How we think about building and pricing Arca.'],
        ];

        $out = [];
        foreach ($defs as $slug => [$name, $desc]) {
            $cat = $this->categories->findOneBy(['slug' => $slug]);
            if (null === $cat) {
                $cat = (new Category($name, $slug))
                    ->setDescription($desc)
                    ->setIsDemo(true);
                $this->em->persist($cat);
                $io->writeln('• Created the "'.$name.'" category.');
            }
            $out[$slug] = $cat;
        }

        return $out;
    }

    // ------------------------------------------------------------------ author ---

    /**
     * A dedicated demo author so every demo post has a visible byline WITHOUT touching the real
     * admin's nickname. ROLE_EDITOR, and an unusable random password. Idempotent; removed by
     * --fresh/--clear (by its fixed e-mail).
     */
    private function ensureDemoAuthor(SymfonyStyle $io): User
    {
        $author = $this->users->findOneBy(['email' => self::AUTHOR_EMAIL]);
        if (null !== $author) {
            $io->writeln('• The demo author already exists.');

            return $author;
        }

        $author = (new User(self::AUTHOR_EMAIL))
            ->setNickname('The Arca team')
            ->setName('Arca')
            ->setRoles(['ROLE_EDITOR']);
        $author->setPassword($this->hasher->hashPassword($author, bin2hex(random_bytes(32))));
        $this->em->persist($author);
        $this->em->flush();
        $io->writeln('• Created the demo author (nickname "The Arca team").');

        return $author;
    }

    // ------------------------------------------------------------------- pages ---

    /**
     * @param array<string, Media>          $media
     * @param array<string, FormDefinition> $forms
     *
     * @return array<string, Page>
     */
    private function ensurePages(SymfonyStyle $io, array $media, array $forms): array
    {
        $img = static fn (string $name): int => $media[$name]?->getId() ?? 0;
        $buyForm = $forms['pro']->getId();
        $contactForm = $forms['kontakt']->getId();

        // [slug => [title, metaDescription, contentHtml]]. Featured image is NOT set on pages (the
        // hero, or an in-body [image], carries the visual — matching the theme convention).
        $defs = [
            'home' => ['Home', 'Arca is a one-time-purchase backup app for Windows. Full and incremental backups of your files and databases — local or cloud. No subscription.', $this->contentHome($img('home-hero'), $img('features-flow'))],
            'features' => ['Features', 'Full and incremental backups, Zstandard compression, chunk-based storage, database dumps, and local or cloud targets — everything Arca does, in one place.', $this->contentFeatures($img('features-flow'), $img('features-db'))],
            'buy' => ['Buy', 'Buy an Arca Pro license — €29, one-time, no subscription. Or download the 15-day free trial first.', $this->contentBuy($buyForm)],
            'docs' => ['Documentation', 'Arca documentation — installation, configuration, backup types, storage targets, and restores. Everything you need to run Arca.', $this->contentDocs()],
            'install' => ['Installation', 'Install Arca on Windows 10 or 11 and run your first backup — download, install, add a source and target, and schedule a job.', $this->contentInstall()],
            'faq' => ['FAQ', 'Frequently asked questions about Arca — licensing, storage providers, database support, encryption, restores, and the backup format.', $this->contentFaq()],
            'about' => ['About us', 'Arca is built by a small team that thinks backup software should be simple, honest, and yours. No subscriptions, no lock-in.', $this->contentAbout($img('team-1'), $img('team-2'), $img('team-3'), $img('team-4'))],
            'contact' => ['Contact', 'Get in touch with the Arca team — questions about features, licensing, or support. We answer personally.', $this->contentContact($contactForm)],
        ];

        // Heroes (overlay, text left / image right half) on Features, Buy, About. Their contentHtml
        // deliberately OMITS the leading display-1 (the hero carries the title). heroStyle=light
        // (dark text, no scrim) — our hero illustrations are LIGHT with the subject on the right and
        // a clear left side, so dark text sits cleanly on the light left half. position=left (the
        // entity default) puts the text on that clear left side.
        // [slug => [mediaName, title, text, ctaLabel|null, ctaUrl|null]]
        $heroes = [
            'features' => ['features-hero', 'Everything Arca does', 'A backup tool should be boring in the best way — set it up once, and it quietly does its job.', 'Try free', '/buy'],
            'buy' => ['buy-hero', 'One app. One price. Yours to keep.', 'Arca Pro is €29, paid once. No subscription, free updates. Try free for 15 days first.', 'Buy Pro — €29', '#buy-form'],
            'about' => ['about-hero', 'We think backups should be simple and honest.', 'Built by a small, independent team that respects your data and your wallet.', 'Get in touch', '/contact'],
        ];

        $out = [];
        foreach ($defs as $slug => [$title, $meta, $content]) {
            $page = $this->pages->findOneBy(['slug' => $slug]);

            // 'home' is the ONE slug that collides with the app:install baseline home (a non-demo
            // Page): plain additive-skip would leave that install placeholder as the front home. So
            // for 'home' ONLY, ADOPT an existing NON-demo page — overwrite it with the demo landing
            // and flag it demo (so --clear removes it and a re-run skips the now-demo home). Every
            // OTHER slug is create-only (install never makes them → they never collide with baseline).
            $adopt = 'home' === $slug && null !== $page && !$page->isDemo();

            if (null !== $page && !$adopt) {
                $out[$slug] = $page; // exists (a demo page, or any non-home page) → additive-skip
                continue;
            }

            $page ??= new Page($title, $slug);
            $page->setTitle($title)
                ->setStatus(Page::STATUS_PUBLISHED)
                ->setContent($content)
                ->setMetaDescription($meta)
                // Every demo page starts with a display-1 in content OR carries a hero → the auto
                // page title would duplicate it, so hide it (the content/hero carries the title).
                ->setHideTitle(true)
                ->setIsDemo(true);

            if (isset($heroes[$slug])) {
                [$hName, $ht, $htext, $hcl, $hcu] = $heroes[$slug];
                $page->setHeroEnabled(true)
                    ->setHeroImage($media[$hName] ?? null)
                    ->setHeroStyle('light') // light illustrations → dark text, no scrim
                    ->setHeroTitle($ht)
                    ->setHeroText($htext)
                    ->setHeroCtaLabel($hcl)
                    ->setHeroCtaUrl($hcu);
            } else {
                // home + docs/install/faq/contact have no hero — ensure it's off (matters for the
                // adopted baseline home, which could carry a stray hero from an admin edit).
                $page->setHeroEnabled(false);
            }

            $this->em->persist($page);
            $io->writeln($adopt
                ? '• Replaced the install home page with the demo landing.'
                : '• Created the "'.$title.'" page.');
            $out[$slug] = $page;
        }

        return $out;
    }

    // ------------------------------------------------------------------- posts ---

    /**
     * @param array<string, Media>    $media
     * @param array<string, Category> $categories
     */
    private function ensurePosts(SymfonyStyle $io, array $media, array $categories, User $author): void
    {
        // [slug, title, categorySlug, mediaName, daysAgo, excerpt, contentHtml]
        $defs = [
            ['introducing-arca-2-1', 'Introducing Arca 2.1', 'releases', 'blog-1', 0,
                'Arca 2.1 brings Zstandard compression, Cloudflare R2 support, and faster incremental backups. Here\'s everything that\'s new.',
                $this->contentBlog1()],
            ['why-we-chose-zstandard-over-gzip', 'Why we chose Zstandard over gzip', 'engineering', 'blog-2', 10,
                'Compression is a trade-off between ratio, speed, and CPU. Here\'s why Zstandard won for backups, with the benchmarks that convinced us.',
                $this->contentBlog2()],
            ['chunk-based-backup-dropped-connection', 'How chunk-based backup survives a dropped connection', 'engineering', 'blog-3', 20,
                'A backup that restarts from zero every time the wifi hiccups isn\'t a backup you\'ll trust. Here\'s how chunking makes transfers resumable.',
                $this->contentBlog3()],
            ['backing-up-mysql-postgresql', 'Backing up MySQL and PostgreSQL the right way', 'engineering', 'blog-4', 32,
                'Copying the data directory while the server is running is how you get a broken backup. Here\'s how Arca takes consistent database snapshots.',
                $this->contentBlog4()],
            ['local-cloud-or-both', 'Local, cloud, or both: choosing your backup target', 'guides', 'blog-5', 45,
                'Fast local restores or off-site safety? With Arca you don\'t have to pick. Here\'s how to think about backup targets — and the 3-2-1 rule.',
                $this->contentBlog5()],
            ['no-subscriptions-one-time-purchase', 'No subscriptions: why Arca is a one-time purchase', 'company', 'blog-6', 60,
                'Almost everything is a subscription now, including backup software. We went the other way on purpose — here\'s the thinking behind pay-once.',
                $this->contentBlog6()],
            ['incremental-backups-explained', 'Incremental backups explained', 'engineering', 'blog-7', 75,
                'Full backups are simple but slow. Incremental backups are fast but sound scary. Here\'s how they actually work — and why you get both with no effort.',
                $this->contentBlog7()],
            ['encrypting-backups-before-upload', 'Encrypting your backups before they leave your machine', 'engineering', 'blog-8', 90,
                'If your backups go to the cloud, encryption isn\'t optional. Here\'s what client-side encryption means, and why the keys should never leave your machine.',
                $this->contentBlog8()],
            ['scheduling-backups-that-actually-run', 'Scheduling backups that actually run', 'guides', 'blog-9', 110,
                'The best backup is the one that runs without you remembering. Here\'s how to schedule Arca so backups happen quietly, reliably, and at the right time.',
                $this->contentBlog9()],
        ];

        $created = 0;
        foreach ($defs as [$slug, $title, $catSlug, $mediaName, $daysAgo, $excerpt, $content]) {
            if (null !== $this->posts->findOneBy(['slug' => $slug])) {
                continue;
            }

            $post = (new Post($title, $slug))
                ->setStatus(Post::STATUS_PUBLISHED)
                ->setPublishedAt(new \DateTimeImmutable('-'.$daysAgo.' days'))
                ->setExcerpt($excerpt)
                ->setCategory($categories[$catSlug] ?? null)
                ->setAuthor($author)
                ->setFeaturedImage($media[$mediaName] ?? null)
                ->setContent($content)
                ->setIsDemo(true);
            $this->em->persist($post);
            ++$created;
        }

        $io->writeln(sprintf('• Posts created: %d (existing skipped: %d).', $created, count($defs) - $created));
    }

    // ------------------------------------------------------------------- menus ---

    /**
     * Rebuild the main 2-level menu + the two footer menus (the demo OWNS them). Each is removed by
     * its location and rebuilt, is_demo=true.
     *
     * @param array<string, Page> $pages
     */
    private function rebuildMenus(SymfonyStyle $io, array $pages): void
    {
        foreach ([self::MENU_LOCATION, self::FOOTER_PRODUCT_LOCATION, self::FOOTER_RESOURCES_LOCATION] as $loc) {
            if (null !== $existing = $this->menus->findOneByLocation($loc)) {
                $this->em->remove($existing);
            }
        }
        $this->em->flush();

        // ---- Main menu (2-level) ------------------------------------------------
        $main = new Menu('Main menu', self::MENU_LOCATION);
        $main->setIsDemo(true);

        $topPage = function (Menu $menu, string $slug, string $label, int $pos) use ($pages): MenuItem {
            $item = (new MenuItem($label))->setPage($pages[$slug])->setPosition($pos);
            $menu->addItem($item);

            return $item;
        };
        $childPage = function (Menu $menu, MenuItem $parent, string $slug, string $label, int $pos) use ($pages): void {
            $item = (new MenuItem($label))->setPage($pages[$slug])->setParent($parent)->setPosition($pos);
            $menu->addItem($item);
        };
        $topUrl = function (Menu $menu, string $label, string $url, int $pos): void {
            $item = (new MenuItem($label))->setUrl($url)->setPosition($pos);
            $menu->addItem($item);
        };

        $topPage($main, 'home', 'Home', 0);
        $topPage($main, 'features', 'Features', 1);
        $topPage($main, 'buy', 'Buy', 2);
        $docs = $topPage($main, 'docs', 'Docs', 3);
        $childPage($main, $docs, 'install', 'Installation', 0);
        $childPage($main, $docs, 'faq', 'FAQ', 1);
        $topUrl($main, 'Blog', '/blog', 4);
        $topPage($main, 'about', 'About', 5);
        $topPage($main, 'contact', 'Contact', 6);

        $this->em->persist($main);
        $io->writeln('• Built the main 2-level menu.');

        // ---- Footer menu: Product ----------------------------------------------
        $product = new Menu('Product', self::FOOTER_PRODUCT_LOCATION);
        $product->setIsDemo(true);
        $topPage($product, 'features', 'Features', 0);
        $topPage($product, 'buy', 'Buy', 1);
        $topUrl($product, 'Download', '#', 2);
        $topUrl($product, 'Changelog', '#', 3);
        $this->em->persist($product);

        // ---- Footer menu: Resources --------------------------------------------
        $resources = new Menu('Resources', self::FOOTER_RESOURCES_LOCATION);
        $resources->setIsDemo(true);
        $topPage($resources, 'docs', 'Documentation', 0);
        $topUrl($resources, 'Blog', '/blog', 1);
        $topPage($resources, 'faq', 'FAQ', 2);
        $topPage($resources, 'contact', 'Contact', 3);
        $this->em->persist($resources);

        $io->writeln('• Built two footer menus (Product, Resources).');
    }

    // ------------------------------------------------------------------ footer ---

    /**
     * 3-column footer: brand text (col 1) + the two footer menus (cols 2, 3). ⚠ The footer settings
     * are NOT flag-based — the seed must be AUTHORITATIVE over the FULL per-column state so a stale
     * key from an earlier state can't shadow it. In particular each column renders `col_menu ?:
     * col_text` (menu WINS), so col 1 must clear `footer_col1_menu` or a leftover menu hides the
     * brand text. Every key set here MUST also be reset in resetDemoSettings().
     */
    private function ensureSiteSettings(SymfonyStyle $io): void
    {
        $this->settings->setMany([
            'footer_columns' => '3',
            // Column 1 = brand text (clear its menu so text wins).
            'footer_col1_menu' => '',
            'footer_col1_text' => <<<'HTML'
                <strong>Arca</strong>
                <p>Backup for Windows that respects your data and your wallet. One-time license, no subscription.</p>
                <p><a href="#">[icon name=github] GitHub</a></p>
                HTML,
            // Columns 2 & 3 = the footer menus (clear their text; menu wins anyway).
            'footer_col2_menu' => self::FOOTER_PRODUCT_LOCATION,
            'footer_col2_text' => '',
            'footer_col3_menu' => self::FOOTER_RESOURCES_LOCATION,
            'footer_col3_text' => '',
            // Column 4 unused at 3 columns — clear it so nothing lingers.
            'footer_col4_menu' => '',
            'footer_col4_text' => '',
            'footer_copyright' => '',
            'footer_show_powered_by' => true,
            'favicon_media_id' => '',
            // Top bar — AUTHORITATIVE like the footer (overwrite any stray top bar an admin left, so a
            // demo install always shows a clean Arca front). An announcement linking to a demo post +
            // three placeholder social icons. ⚠ Point them at the platform HOME (not a real profile —
            // Arca is fictional); a bare '#' is NOT rendered because TopBarExtension::isSafeUrl requires
            // an http(s):// or '/' URL, so the icons need a real scheme to show at all.
            'top_bar_enabled' => true,
            'top_bar_text' => '<p><strong>Arca 2.1 is here</strong> — faster incremental backups, lower memory use. <a href="/blog/introducing-arca-2-1">Read more →</a></p>',
            'social_github_url' => 'https://github.com',
            'social_youtube_url' => 'https://youtube.com',
            'social_x_url' => 'https://x.com',
            'social_linkedin_url' => '',
            // Clear the legacy footer keys (superseded by footer_col*; the current layout ignores them)
            // so no stale value lingers.
            'footer_menu' => '',
            'footer_text' => '',
        ]);
        $io->writeln('• Set the demo footer (3 columns) + top bar (Arca announcement + social icons).');
    }

    // ------------------------------------------------------- page content (HTML) ---
    // Transcribed verbatim from demo_content/arca-demo-spec.md. Stored HTML + shortcodes.

    private function contentHome(int $homeHero, int $featuresFlow): string
    {
        return <<<HTML
            <h1 class="display-1">Backups that work for you. Not the other way around.</h1>
            <p>Arca keeps your files and databases safe — full or incremental, local or in the cloud. Set it once and it runs itself. No subscription, no middleman: pay once and everything stays yours.</p>
            <p style="text-align:center"><a class="tallyst-btn tallyst-btn--primary" href="/buy">Download — 15-day free trial</a> <a class="tallyst-btn tallyst-btn--secondary" href="#pricing">See pricing</a></p>
            <p style="text-align:center">Windows 10 &amp; 11 · Backblaze B2 · Amazon S3 · Wasabi · Cloudflare R2 · local disk</p>

            <div class="tallyst-spacer tallyst-spacer--sm"></div>

            [image id={$homeHero} width=full alt="The Arca app showing a backup in progress"]

            <h6 style="text-align:center">Features</h6>
            <h2 class="display-2" style="text-align:center">Everything a serious backup needs</h2>
            <p style="text-align:center">No ten-step wizard. Set it up once, and it keeps running.</p>

            <div class="tallyst-columns tallyst-columns--cards-tint" data-columns="3">
            <div class="tallyst-column"><p>[icon name=layers]</p><h3>Full &amp; incremental</h3><p>The first backup is complete; after that only changes are stored — fast and space-efficient.</p></div>
            <div class="tallyst-column"><p>[icon name=cloud-upload]</p><h3>Local or cloud</h3><p>Your own disk or B2, S3, Wasabi, R2 — you choose where your data lives.</p></div>
            <div class="tallyst-column"><p>[icon name=database]</p><h3>Database backups</h3><p>MySQL and PostgreSQL with consistent snapshots — no downtime required.</p></div>
            </div>
            <div class="tallyst-columns tallyst-columns--cards-tint" data-columns="3">
            <div class="tallyst-column"><p>[icon name=bolt]</p><h3>Zstandard compression</h3><p>Smaller archives and faster writes than classic zip.</p></div>
            <div class="tallyst-column"><p>[icon name=boxes]</p><h3>Chunk-based format</h3><p>Progressive, chunked storage — it resumes exactly where it left off.</p></div>
            <div class="tallyst-column"><p>[icon name=euro]</p><h3>No subscription</h3><p>Pay once, use it forever. No monthly bills.</p></div>
            </div>

            <div class="tallyst-spacer tallyst-spacer--sm"></div>

            <div class="tallyst-columns" data-columns="2">
            <div class="tallyst-column">[image id={$featuresFlow} size=medium align=center alt="Files split into chunks and sent to the cloud"]</div>
            <div class="tallyst-column"><h3>Why Arca?</h3><p>Most backup tools want a subscription and your data on their servers. Arca is the opposite: the app is yours, the keys are yours, and storage is wherever you decide. The chunk format means an interrupted transfer picks up exactly where it stopped — even on a slow connection.</p><p><a class="tallyst-btn tallyst-btn--ghost" href="/docs">How it works [icon name=arrow-right]</a></p></div>
            </div>

            <h6 id="pricing" style="text-align:center">Pricing</h6>
            <h2 class="display-2" style="text-align:center">Try everything — pay once</h2>
            <p style="text-align:center">A full 15 days, no card required. If you like it, €29 and it's yours forever.</p>

            <div class="tallyst-columns tallyst-columns--cards" data-columns="2">
            <div class="tallyst-column"><h3>Trial</h3><p><strong style="font-size:1.8em">€0</strong></p><p>15 days, every feature</p><ul><li>[icon name=check] Full functionality</li><li>[icon name=check] Local and cloud backup</li><li>[icon name=check] No card to try</li></ul><p><a class="tallyst-btn tallyst-btn--secondary" href="/buy">Download free</a></p></div>
            <div class="tallyst-column tallyst-column--highlight"><h6>Most popular</h6><h3>Pro</h3><p><strong style="font-size:1.8em">€29</strong> once</p><p>Lifetime license, no subscription</p><ul><li>[icon name=check] Everything in Trial, forever</li><li>[icon name=check] Database backups (MySQL, PostgreSQL)</li><li>[icon name=check] Free updates</li></ul><p><a class="tallyst-btn tallyst-btn--primary" href="/buy">Buy Pro — €29</a></p></div>
            </div>

            <div class="tallyst-spacer tallyst-spacer--md"></div>

            <h2 class="display-2" style="text-align:center">Ready for your first backup?</h2>
            <p style="text-align:center">Download Arca and you'll have your first safe snapshot in three minutes.</p>
            <p style="text-align:center"><a class="tallyst-btn tallyst-btn--primary" href="/buy">Download — 15-day free trial</a></p>
            HTML;
    }

    private function contentFeatures(int $featuresFlow, int $featuresDb): string
    {
        // Hero page: no leading display-1 (the hero carries "Everything Arca does").
        return <<<HTML
            <h6>Backup types</h6>
            <h2 class="display-2">Full when you need it, incremental the rest of the time</h2>
            <div class="tallyst-columns tallyst-columns--cards-tint" data-columns="2">
            <div class="tallyst-column"><p>[icon name=layers]</p><h3>Full backups</h3><p>A complete, self-contained snapshot of everything you selected. Your baseline — restore from it directly, with nothing else required.</p></div>
            <div class="tallyst-column"><p>[icon name=boxes]</p><h3>Incremental backups</h3><p>After the first full backup, Arca stores only what changed. Backups finish in seconds instead of hours, and use a fraction of the space.</p></div>
            </div>

            <h6>Compression &amp; storage</h6>
            <h2 class="display-2">Smaller, faster, resumable</h2>
            <div class="tallyst-columns" data-columns="2">
            <div class="tallyst-column"><h3>Zstandard compression</h3><p>Arca compresses with Zstandard, which beats classic gzip on both ratio and speed. Smaller archives mean lower cloud bills and faster uploads — you don't trade one for the other.</p><h3>Chunk-based format</h3><p>Backups are split into content-addressed chunks. A dropped connection doesn't restart the whole transfer; Arca resumes from the last completed chunk. The same chunking deduplicates data you've already stored.</p></div>
            <div class="tallyst-column">[image id={$featuresFlow} size=medium align=center alt="A file split into chunks, compressed, and uploaded"]</div>
            </div>

            <h6>Databases</h6>
            <h2 class="display-2">Consistent database backups, no downtime</h2>
            <div class="tallyst-columns" data-columns="2">
            <div class="tallyst-column">[image id={$featuresDb} size=medium align=center alt="Database tables being exported"]</div>
            <div class="tallyst-column"><p>Arca backs up MySQL and PostgreSQL using each engine's native dump path, wrapped in a standard TAR/GZIP archive so you can restore anywhere — even without Arca. Snapshots are consistent: you get the database as it was at a single point in time, without stopping the server.</p><p><a class="tallyst-btn tallyst-btn--ghost" href="/docs">Read the docs [icon name=arrow-right]</a></p></div>
            </div>

            <h6>Where your backups go</h6>
            <h2 class="display-2">Local, cloud, or both</h2>
            <div class="tallyst-columns tallyst-columns--cards-tint" data-columns="3">
            <div class="tallyst-column"><p>[icon name=cloud-upload]</p><h3>Object storage</h3><p>Backblaze B2, Amazon S3, Wasabi, and Cloudflare R2 — bring your own bucket and keys.</p></div>
            <div class="tallyst-column"><p>[icon name=database]</p><h3>Local disk</h3><p>An external drive or NAS on your own network. No account, no upload, no third party.</p></div>
            <div class="tallyst-column"><p>[icon name=bolt]</p><h3>Both at once</h3><p>Keep a fast local copy and an off-site cloud copy from a single backup job.</p></div>
            </div>

            <h2 class="display-2" style="text-align:center">See it for yourself</h2>
            <p style="text-align:center">Every feature is in the trial. No account, no card.</p>
            <p style="text-align:center"><a class="tallyst-btn tallyst-btn--primary" href="/buy">Download — 15-day free trial</a> <a class="tallyst-btn tallyst-btn--secondary" href="/buy">See pricing</a></p>
            HTML;
    }

    private function contentBuy(int $buyForm): string
    {
        // Hero page: no leading display-1 (the hero carries "One app. One price. Yours to keep.").
        return <<<HTML
            <h6 id="buy-form">Get your license</h6>
            <h2 class="display-2">Buy Arca Pro</h2>
            <p>Enter your details and we'll email your license key. If you're still evaluating, grab the free trial below — no card needed.</p>

            <div class="tallyst-columns tallyst-columns--cards" data-columns="2">
            <div class="tallyst-column"><h6>What you get</h6><h3>Arca Pro — €29 once</h3><p>A lifetime license, no subscription, and every future update included. One payment and Arca is yours.</p><ul><li>[icon name=check] Full and incremental backups</li><li>[icon name=check] Local and cloud targets (B2, S3, Wasabi, R2)</li><li>[icon name=check] Database backups (MySQL, PostgreSQL)</li><li>[icon name=check] Client-side encryption</li><li>[icon name=check] Priority email support</li><li>[icon name=check] Free updates for life</li><li>[icon name=check] Use on all your own machines</li></ul><p><span class="tallyst-color--muted"><em>Not ready to buy? Grab the free 15-day trial below — no card, no account.</em></span></p></div>
            <div class="tallyst-column tallyst-column--highlight"><h6>Checkout</h6><h3>Your details</h3>[form id={$buyForm}]</div>
            </div>

            <h6 id="download">Free trial</h6>
            <h2 class="display-2">Prefer to try first?</h2>
            <p>Download the full app and use every feature for 15 days. Nothing is disabled, and you don't need an account or a card. If it's not for you, just uninstall — no strings.</p>
            <p><a class="tallyst-btn tallyst-btn--primary" href="#">Download Arca for Windows [icon name=arrow-right]</a></p>

            <div class="tallyst-spacer tallyst-spacer--md"></div>

            <div class="tallyst-columns" data-columns="3">
            <div class="tallyst-column"><h3>Is this really one-time?</h3><p>Yes. One payment, one license, no recurring charges — ever.</p></div>
            <div class="tallyst-column"><h3>What about updates?</h3><p>Included. Every update to Arca is free for licensed users, for the life of the product.</p></div>
            <div class="tallyst-column"><h3>Refunds?</h3><p>That's what the 15-day trial is for. Try everything before you pay a cent.</p></div>
            </div>
            HTML;
    }

    private function contentDocs(): string
    {
        return <<<HTML
            <h1 class="display-1">Documentation</h1>
            <p>Everything you need to install Arca, point it at your data, and get a reliable backup running. Start with installation, then pick the topic you need.</p>

            <div class="tallyst-columns tallyst-columns--cards-tint" data-columns="2">
            <div class="tallyst-column"><p>[icon name=bolt]</p><h3>Installation</h3><p>Download, install, and run your first backup in a few minutes.</p><p><a class="tallyst-btn tallyst-btn--ghost" href="/install">Installation guide [icon name=arrow-right]</a></p></div>
            <div class="tallyst-column"><p>[icon name=database]</p><h3>FAQ</h3><p>Common questions about formats, storage, licensing, and restores.</p><p><a class="tallyst-btn tallyst-btn--ghost" href="/faq">Read the FAQ [icon name=arrow-right]</a></p></div>
            </div>

            <h6>Core concepts</h6>
            <h2 class="display-2">How Arca thinks about backups</h2>
            <h3>Sources</h3>
            <p>A <em>source</em> is what you want to protect: a folder, a whole drive, or a database. You can add as many sources to a job as you like, and mix files and databases freely.</p>
            <h3>Targets</h3>
            <p>A <em>target</em> is where the backup goes: a local disk, or an object-storage bucket (Backblaze B2, Amazon S3, Wasabi, Cloudflare R2). A single job can write to more than one target at once — for example, a fast local copy plus an off-site cloud copy.</p>
            <h3>Jobs &amp; schedules</h3>
            <p>A <em>job</em> ties sources and targets together and runs on a schedule you choose — hourly, daily, or on your own cron-style expression. The first run is a full backup; every run after that is incremental.</p>

            <h6>Restoring</h6>
            <h2 class="display-2">Getting your data back</h2>
            <p>Every backup is browsable. Open any snapshot, navigate to the file or folder you need, and restore it in place or to a new location. Because the archive format is standard TAR/GZIP for databases and a documented chunk format for files, you're never locked in — you can restore even without Arca if you have to.</p>
            <pre><code># Restore the latest snapshot of a job to a folder
            arca restore --job "Documents" --target ./restored --latest

            # Restore a single path from a specific snapshot
            arca restore --job "Documents" --snapshot 2026-07-01T02:00 --path "reports/q2.xlsx"</code></pre>

            <h2 class="display-2" style="text-align:center">Can't find something?</h2>
            <p style="text-align:center">The FAQ covers the common cases, and support is an email away.</p>
            <p style="text-align:center"><a class="tallyst-btn tallyst-btn--secondary" href="/faq">Read the FAQ</a> <a class="tallyst-btn tallyst-btn--primary" href="/contact">Contact support</a></p>
            HTML;
    }

    private function contentInstall(): string
    {
        return <<<HTML
            <h1 class="display-1">Installation</h1>
            <p>Arca runs on Windows 10 and 11. Installation takes a couple of minutes, and you'll have your first backup running by the end of this page.</p>

            <h6>Step 1</h6>
            <h2 class="display-2">Download and install</h2>
            <p>Download the installer from the <a href="/buy">download page</a> and run it. Arca installs like any other Windows app — no dependencies to chase, no runtime to configure. When it's done, launch Arca from the Start menu.</p>

            <h6>Step 2</h6>
            <h2 class="display-2">Add a source</h2>
            <p>Click <strong>New job</strong>, then <strong>Add source</strong>. Pick a folder, a drive, or a database connection. For databases, enter the host, port, and credentials — Arca supports MySQL and PostgreSQL out of the box.</p>

            <h6>Step 3</h6>
            <h2 class="display-2">Choose a target</h2>
            <p>Add where the backup should go. For a local target, pick a folder or external drive. For cloud, choose your provider and paste your bucket name and keys:</p>
            <pre><code>Provider:   Backblaze B2
            Bucket:     my-arca-backups
            Key ID:     •••••••••••••
            App Key:    •••••••••••••</code></pre>
            <p>Arca never sees your data on anyone else's server — the keys stay on your machine, and the backup goes straight to your bucket.</p>

            <h6>Step 4</h6>
            <h2 class="display-2">Schedule and run</h2>
            <p>Set a schedule — daily at 2 AM is a sensible default — and click <strong>Run now</strong> to kick off the first backup. That first run is a full backup; every run after it is incremental, so it finishes fast.</p>

            <div class="tallyst-columns tallyst-columns--cards-tint" data-columns="3">
            <div class="tallyst-column"><p>[icon name=check]</p><h3>Done</h3><p>Your data is now backed up on a schedule.</p></div>
            <div class="tallyst-column"><p>[icon name=cloud-upload]</p><h3>Add a second target</h3><p>Keep a local and a cloud copy from the same job.</p></div>
            <div class="tallyst-column"><p>[icon name=database]</p><h3>Add your databases</h3><p>Protect MySQL and PostgreSQL alongside your files.</p></div>
            </div>

            <p style="text-align:center"><a class="tallyst-btn tallyst-btn--ghost" href="/faq">Have a question? Read the FAQ [icon name=arrow-right]</a></p>
            HTML;
    }

    private function contentFaq(): string
    {
        return <<<HTML
            <h1 class="display-1">Frequently asked questions</h1>
            <p>The short answers to the questions we hear most. If yours isn't here, <a href="/contact">get in touch</a>.</p>

            <h6>Licensing</h6>
            <h3>Is Arca really a one-time purchase?</h3>
            <p>Yes. Arca Pro is €29, paid once. There's no subscription and no recurring charge. Updates are free for the life of the product.</p>
            <h3>Can I use my license on more than one computer?</h3>
            <p>Yes — your license covers all the machines you personally use. It's meant for a person, not a single install.</p>

            <h6>Storage &amp; formats</h6>
            <h3>Which cloud providers are supported?</h3>
            <p>Any S3-compatible object storage: Backblaze B2, Amazon S3, Wasabi, and Cloudflare R2 are supported and tested. You bring your own bucket and keys.</p>
            <h3>What format are backups stored in?</h3>
            <p>Files use a documented, chunk-based format compressed with Zstandard. Databases are stored as standard TAR/GZIP dumps. Both are restorable without Arca if you ever need to — you're never locked in.</p>
            <h3>What is chunk-based backup?</h3>
            <p>Arca splits your data into content-addressed chunks. This means interrupted transfers resume from the last completed chunk instead of restarting, and data you've already backed up isn't stored twice.</p>

            <h6>Databases</h6>
            <h3>Which databases can Arca back up?</h3>
            <p>MySQL and PostgreSQL, using each engine's native dump path for a consistent point-in-time snapshot — no need to stop the server.</p>

            <h6>Security</h6>
            <h3>Is my data encrypted?</h3>
            <p>Backups can be encrypted on your machine before they're uploaded, so your provider only ever stores ciphertext. The keys stay with you.</p>
            <h3>Does Arca see my data?</h3>
            <p>No. Backups go straight from your machine to your target. There's no Arca server in the middle, and no account holding your files.</p>

            <h6>Restores</h6>
            <h3>How do I restore a file?</h3>
            <p>Open any snapshot, browse to the file or folder, and restore it in place or to a new location. You can restore a single file or an entire job.</p>

            <h2 class="display-2" style="text-align:center">Still stuck?</h2>
            <p style="text-align:center">We answer support email personally.</p>
            <p style="text-align:center"><a class="tallyst-btn tallyst-btn--primary" href="/contact">Contact us</a></p>
            HTML;
    }

    private function contentAbout(int $team1, int $team2, int $team3, int $team4): string
    {
        // Hero page: no leading display-1 (the hero carries the title). Demonstrates THREE new
        // editor tools: 1-column cards (team members), text-colour (roles), spacer (section gaps).
        // Team avatars use size=thumb (the real "small" Liip filter) + align=left inside the card.
        return <<<HTML
            <h6>What we believe</h6>
            <h2 class="display-2">A few simple principles</h2>
            <div class="tallyst-columns tallyst-columns--cards-tint" data-columns="3">
            <div class="tallyst-column"><p>[icon name=euro]</p><h3>Pay once</h3><p>Software you buy should be yours. No subscriptions, no rent on your own data.</p></div>
            <div class="tallyst-column"><p>[icon name=cloud-upload]</p><h3>Your data, your keys</h3><p>Backups go from your machine to your storage. We never hold your files or your keys.</p></div>
            <div class="tallyst-column"><p>[icon name=boxes]</p><h3>No lock-in</h3><p>Open, documented formats. You can restore without us if you ever need to.</p></div>
            </div>

            <h6>Our team</h6>
            <h2 class="display-2">Small on purpose</h2>
            <p>Arca is built and supported by four people. That's not a limitation — it's the point. A small team answers its own support email, ships what it believes in, and knows every line of the product.</p>

            <div class="tallyst-spacer tallyst-spacer--sm"></div>

            <div class="tallyst-columns tallyst-columns--cards" data-columns="1">
            <div class="tallyst-column">[image id={$team1} size=thumb align=left alt="James Carter"]<h3>James Carter</h3><p><span class="tallyst-color--brand">Founder &amp; Lead Developer</span></p><p>Started Arca after losing a drive full of work to a backup tool that had "quietly" stopped running. Believes software you buy should be yours, keys and all.</p><p><em>"The best backup is the one you never have to think about."</em></p></div>
            </div>

            <div class="tallyst-columns tallyst-columns--cards" data-columns="1">
            <div class="tallyst-column">[image id={$team2} size=thumb align=left alt="Sarah Lin"]<h3>Sarah Lin</h3><p><span class="tallyst-color--blue">Backend Engineer</span></p><p>Owns the chunk format and the compression pipeline. Spends her days making backups smaller and restores faster, and her weekends rock climbing.</p><p><em>"Every byte you don't upload is a byte you never have to worry about."</em></p></div>
            </div>

            <div class="tallyst-columns tallyst-columns--cards" data-columns="1">
            <div class="tallyst-column">[image id={$team3} size=thumb align=left alt="Tom Fisher"]<h3>Tom Fisher</h3><p><span class="tallyst-color--green">Support &amp; Documentation</span></p><p>The person who answers when you email support. Writes the docs, hunts down edge cases, and turns confused tickets into clear guides.</p><p><em>"A feature nobody understands might as well not exist."</em></p></div>
            </div>

            <div class="tallyst-columns tallyst-columns--cards" data-columns="1">
            <div class="tallyst-column">[image id={$team4} size=thumb align=left alt="Laura Bennett"]<h3>Laura Bennett</h3><p><span class="tallyst-color--brand-strong">Design &amp; UX</span></p><p>Shapes how Arca looks and feels — from the first-run setup to the progress bar you glance at once a day. Fights for fewer clicks and clearer words.</p><p><em>"Good backup software gets out of your way."</em></p></div>
            </div>

            <h6>How we work</h6>
            <h2 class="display-2">A person reads your email</h2>
            <p>We're not chasing scale for its own sake. A small team means we can keep Arca focused, answer support email ourselves, and make decisions based on what's right for the people who use it — not what maximizes a subscription number. When you email us, one of the four people above reads it.</p>

            <div class="tallyst-spacer tallyst-spacer--md"></div>

            <h2 class="display-2" style="text-align:center">Come say hello</h2>
            <p style="text-align:center">Questions, feedback, or just curious? We'd love to hear from you.</p>
            <p style="text-align:center"><a class="tallyst-btn tallyst-btn--primary" href="/contact">Get in touch</a> <a class="tallyst-btn tallyst-btn--secondary" href="/buy">Try Arca free</a></p>
            HTML;
    }

    private function contentContact(int $contactForm): string
    {
        return <<<HTML
            <h1 class="display-1">Get in touch</h1>
            <p>Questions about features, licensing, or a backup that's misbehaving? Send us a message and a real person — someone who works on Arca — will get back to you.</p>

            <div class="tallyst-spacer tallyst-spacer--md"></div>

            <div class="tallyst-columns" data-columns="2">
            <div class="tallyst-column">
            <h3>Send a message</h3>
            [form id={$contactForm}]
            </div>
            <div class="tallyst-column">
            <h3>Other ways to reach us</h3>
            <p>Prefer email or want to follow along? Here's where to find us.</p>
            <ul>
            <li>[icon name=database] <strong>Support:</strong> support@arca.example</li>
            <li>[icon name=github] <strong>GitHub:</strong> report issues and follow releases</li>
            <li>[icon name=bolt] <strong>Response time:</strong> usually within one business day</li>
            </ul>
            <p>Evaluating Arca for a team or just getting started? Mention it in your message and we'll point you in the right direction.</p>
            </div>
            </div>
            HTML;
    }

    // ------------------------------------------------------- blog content (HTML) ---

    private function contentBlog1(): string
    {
        return <<<HTML
            <p>Arca 2.1 is here, and it's the biggest release since we launched. This one is about doing more with less: smaller backups, faster runs, and one more place to put them. If you're already a Pro user, it's a free update — open Arca and it'll offer to update itself, jobs and schedules intact. Here's the full tour.</p>

            <h2>Zstandard compression by default</h2>
            <p>New backups now compress with Zstandard instead of the old gzip path. Across the mixed file sets we tested — source code, documents, a few gigabytes of photos — Zstandard produced smaller archives <span class="tallyst-color--brand">and</span> finished faster. That's rare: usually you trade ratio for speed or the other way round. Zstandard's design lets you have both at the level we ship.</p>
            <p>Existing backups keep working exactly as they are. The change applies to new runs, so you don't have to migrate anything. If you want the details on why we switched, we wrote a whole post on it: <a href="/blog/why-we-chose-zstandard-over-gzip">Why we chose Zstandard over gzip</a>.</p>

            <h2>Cloudflare R2 support</h2>
            <p>R2 joins Backblaze B2, Amazon S3, and Wasabi as a first-class target. Because R2 is S3-compatible, setup is the same as any other bucket — pick the provider, paste your bucket name and keys, and you're done:</p>
            <pre><code>Provider:   Cloudflare R2
            Bucket:     arca-offsite
            Endpoint:   https://&lt;account-id&gt;.r2.cloudflarestorage.com
            Key ID:     ••••••••••••
            App Key:    ••••••••••••</code></pre>
            <p>R2's zero-egress pricing makes it a strong choice for backups specifically. You pay to store, but not to <em>download</em> — and with backups, downloading is the thing you hope you rarely do. When you do need a restore, it doesn't come with a surprise bandwidth bill.</p>

            <h2>Faster incremental backups</h2>
            <p>We reworked how Arca detects what changed between runs. The old approach walked the entire source tree and compared timestamps; the new one is smarter about which parts of the tree it even needs to look at. On large source trees — think a projects folder with tens of thousands of files — incremental backups are noticeably quicker:</p>
            <pre><code>Nightly incremental, ~80k file source tree

              2.0    change detection   41s
              2.1    change detection    9s</code></pre>
            <p>That's time your machine spends doing nothing you care about, so getting it back is pure win. The backup itself moves the same data as before; it just spends far less time figuring out <em>what</em> to move.</p>

            <h2>Smaller quality-of-life fixes</h2>
            <div class="tallyst-columns tallyst-columns--cards-tint" data-columns="3">
            <div class="tallyst-column"><p>[icon name=bolt]</p><h3>Resumable restores</h3><p>Restores now resume the same way backups do — a dropped connection mid-restore no longer starts over.</p></div>
            <div class="tallyst-column"><p>[icon name=database]</p><h3>Per-job logs</h3><p>Every job keeps its own readable log, so you can see exactly what ran and when.</p></div>
            <div class="tallyst-column"><p>[icon name=layers]</p><h3>Clearer schedules</h3><p>The schedule editor now shows the next three run times so there's no guessing.</p></div>
            </div>

            <div class="tallyst-spacer tallyst-spacer--md"></div>

            <h2>Upgrading</h2>
            <p>2.1 is a free update for all Pro users. Open Arca and it'll offer to update; your jobs, schedules, and targets carry over untouched. If you're on the trial, you'll get 2.1 automatically — nothing to do.</p>
            <p><a class="tallyst-btn tallyst-btn--primary" href="/buy">Get Arca 2.1 [icon name=arrow-right]</a></p>
            HTML;
    }

    private function contentBlog2(): string
    {
        return <<<HTML
            <p>For years, gzip was the safe default for compression: it's everywhere, it's well understood, and it's good enough. When we sat down to choose the compression for Arca's backup format, "good enough" wasn't the bar we wanted to clear. A backup tool runs on a schedule, moves gigabytes, and does it over and over — small inefficiencies compound into hours. Here's how we ended up on Zstandard, and why it wasn't a close call.</p>

            <h2>The trade-off nobody escapes</h2>
            <p>Every compressor balances three things: how small the output is (ratio), how fast it runs (speed), and how much CPU it burns getting there. Push one and you usually pay in another. gzip sits at a reasonable middle, which is exactly why it became the default everywhere — but "reasonable middle" leaves a lot on the table when compression is something you do every single night.</p>
            <p>The question for a backup tool isn't "what's the best ratio" or "what's the fastest" in isolation. It's: <span class="tallyst-color--brand">what gives the best ratio within a time budget that fits a nightly run?</span> That framing is what led us away from gzip.</p>

            <h2>What Zstandard changes</h2>
            <p>Zstandard (zstd) was designed by Facebook specifically to shift that trade-off. At comparable ratios it's dramatically faster than gzip, and it exposes a wide range of levels — from very fast and light to slow and thorough — so you can dial in exactly where you want to sit. For backups, the sweet spot is a mid-level setting that matches gzip's <em>best</em> ratios while finishing in a fraction of the time.</p>
            <p>Here's the rough shape of what we measured on a mixed 10 GB source. Your numbers will vary with data and hardware, but the relationship holds:</p>
            <pre><code># Mixed 10 GB source (code, docs, images)

            gzip  -6     ratio 2.9x     write 100%   (baseline)
            zstd  -3     ratio 2.9x     write  38%
            zstd  -10    ratio 3.3x     write  71%
            zstd  -19    ratio 3.6x     write 320%</code></pre>
            <p>Look at <code>zstd -3</code>: the same ratio as gzip, in well under half the time. Or <code>zstd -10</code>: a better ratio <em>and</em> still faster. Only at the extreme high levels (<code>-19</code>) do you pay a real time penalty, and for backups that's rarely worth it. We ship a mid-level default and let power users tune it.</p>

            <h2>Why it matters more for cloud backups</h2>
            <p>Smaller archives aren't just about disk space. If your target is object storage, every byte you don't produce is a byte you don't upload — and upload is usually the slowest part of a cloud backup. Faster compression that also produces smaller output attacks the bottleneck from both sides:</p>
            <div class="tallyst-columns tallyst-columns--cards-tint" data-columns="2">
            <div class="tallyst-column"><p>[icon name=bolt]</p><h3>Less to upload</h3><p>A better ratio means fewer bytes over the wire, so the slow part of a cloud backup gets shorter.</p></div>
            <div class="tallyst-column"><p>[icon name=layers]</p><h3>Shorter window</h3><p>Faster compression means the whole job finishes sooner — a smaller window where a backup is mid-flight.</p></div>
            </div>

            <div class="tallyst-spacer tallyst-spacer--md"></div>

            <h2>The catch, and why it didn't stop us</h2>
            <p>The honest downside of any newer format is ubiquity. gzip is readable literally everywhere — every OS, every language, every rescue disk. zstd is close, but not universal. We handled that deliberately: Arca keeps <strong>database dumps in standard TAR/GZIP</strong>, and the file chunk format is documented, so you're never stuck if you don't have Arca in front of you. For the file data itself, the speed and ratio were worth the small ubiquity cost — and honestly, zstd is everywhere that matters now.</p>

            <p>If you want to see how this fits into the bigger picture of how Arca stores data, the chunk format post is the natural next read: <a href="/blog/chunk-based-backup-dropped-connection">How chunk-based backup survives a dropped connection</a>.</p>
            <p><a class="tallyst-btn tallyst-btn--ghost" href="/features">See how Arca stores backups [icon name=arrow-right]</a></p>
            HTML;
    }

    private function contentBlog3(): string
    {
        return <<<HTML
            <p>Picture a 40 GB backup, three-quarters uploaded, when your connection drops for ten seconds. With a naive backup tool, that's back to zero — the whole transfer restarts. With Arca, it's back to where it left off, and you probably don't even notice. The difference is a design decision called chunking, and it quietly shapes almost everything good about how Arca stores data.</p>

            <h2>One big blob vs. many small chunks</h2>
            <p>The simplest way to store a backup is as one large archive: tar everything up, compress it, upload the result. It's easy to reason about — and brutal when a transfer fails, because there's no natural place to resume. You either restart from the beginning or you build fragile bookkeeping to track how many bytes made it and hope the server agrees.</p>
            <p>Arca takes the other path. Before anything is uploaded, your data is split into <strong>chunks</strong> — small pieces, each identified by a hash of its own contents. The backup becomes two things: a manifest (a list of which chunks make up which files) and the chunks themselves.</p>
            <pre><code># A snapshot is a manifest of content-addressed chunks
            snapshot 2026-07-01T02:00
              reports/q2.xlsx   -> [a1f3c9, 90bce2, 55de07]
              reports/q1.xlsx   -> [a1f3c9, 7712bb]        # a1f3c9 shared!
              notes/todo.md     -> [3f0a11]</code></pre>

            <h2>Content-addressing helps twice</h2>
            <p>Because each chunk is named by a hash of its content, two identical chunks get the same name — automatically. Notice <code>a1f3c9</code> in the manifest above: it appears in two files, but Arca stores it <span class="tallyst-color--brand">once</span>. That's deduplication, and it falls out of the naming scheme for free. The second backup of a mostly-unchanged folder only needs to store the handful of chunks that actually changed.</p>
            <div class="tallyst-columns tallyst-columns--cards-tint" data-columns="2">
            <div class="tallyst-column"><p>[icon name=boxes]</p><h3>Resumable by design</h3><p>Reconnect, and Arca sends only the chunks that aren't already at the target.</p></div>
            <div class="tallyst-column"><p>[icon name=layers]</p><h3>Deduplicated for free</h3><p>Identical chunks share a name, so they're stored exactly once.</p></div>
            </div>

            <h2>Resuming is just "which chunks are missing"</h2>
            <p>Now the dropped connection is a non-event. When Arca reconnects, it asks the target a simple question: which chunks from this snapshot do you already have? Everything present is skipped; only the missing chunks are sent. There's no special resume mode, no fragile byte-offset tracking, no "are we really at 73%?" — resumability is just the natural consequence of naming chunks by content.</p>
            <pre><code># On reconnect, Arca reconciles against the target
            have:    a1f3c9, 90bce2, 7712bb, 3f0a11   (already uploaded)
            missing: 55de07                            (send just this)
            => resume by uploading 1 chunk, not 40 GB</code></pre>

            <div class="tallyst-spacer tallyst-spacer--md"></div>

            <h2>The same idea powers restores</h2>
            <p>Chunking isn't only an upload trick. A restore is the manifest in reverse: fetch the chunks a file needs, in order, and reassemble. Since chunks are shared across files and snapshots, restoring a single file pulls only the chunks that file actually uses — you don't download a monolithic archive to recover one spreadsheet. And just like uploads, restores resume if the connection drops.</p>

            <p>It's the kind of design you never think about — until the one night your connection drops at 90%, and the backup just carries on. If you're curious how this interacts with nightly incremental backups, that's the next post: <a href="/blog/incremental-backups-explained">Incremental backups explained</a>.</p>
            <p><a class="tallyst-btn tallyst-btn--primary" href="/buy">Try Arca free [icon name=arrow-right]</a></p>
            HTML;
    }

    private function contentBlog4(): string
    {
        return <<<HTML
            <p>There's a tempting shortcut with database backups: just copy the data directory. It's fast, it's simple, and it works right up until the moment it doesn't — because a running database is writing to those files while you copy them. What you get is a snapshot that's internally inconsistent: a backup that might restore cleanly, might not, and won't tell you which until the day you actually need it. Here's how Arca does database backups properly.</p>

            <h2>Why copying files is a trap</h2>
            <p>A live database holds its state in three places at once: in memory, in a write-ahead log, and on disk. Those are constantly being reconciled. Copy the on-disk files in the middle of that and you capture a half-applied transaction — index pointing at a row that isn't written yet, or a row written without its index entry. The copy looks fine. It restores. And then queries return corruption, or the engine refuses to start.</p>
            <p><span class="tallyst-color--brand">A backup you can't trust is worse than no backup</span>, because it lulls you into thinking you're covered. So Arca doesn't copy database files.</p>

            <h2>Use the engine's own consistent dump</h2>
            <p>The reliable way to back up a database is to ask the database itself for a consistent snapshot. Every serious engine has a mechanism for this, and Arca uses it. Under the hood, that's the native dump path for each engine:</p>
            <pre><code># MySQL / MariaDB — one consistent transaction
            mysqldump --single-transaction --routines --triggers \
                      --databases appdb > appdb.sql

            # PostgreSQL — consistent by design, custom format
            pg_dump --format=custom --file=appdb.dump appdb</code></pre>
            <p>The <code>--single-transaction</code> flag is the important part for MySQL with InnoDB: it takes the entire dump inside one transaction, so writes that happen <em>during</em> the dump don't leak into it. You get the database exactly as it existed at the instant the dump began — no torn transactions, no server downtime. PostgreSQL's <code>pg_dump</code> gives you the same point-in-time consistency by design.</p>

            <h2>Wrapped in a standard, boring format</h2>
            <p>Arca stores those dumps inside a standard TAR/GZIP archive, and that's a deliberate choice. Even if you never open Arca again, a database backup is just a dump file you can restore with the tools you already know. There's no proprietary database format to reverse-engineer at the worst possible moment.</p>
            <div class="tallyst-columns tallyst-columns--cards-tint" data-columns="2">
            <div class="tallyst-column"><p>[icon name=database]</p><h3>Consistent</h3><p>A single point-in-time snapshot, taken without stopping the server.</p></div>
            <div class="tallyst-column"><p>[icon name=boxes]</p><h3>Portable</h3><p>Standard dump in a standard archive — restore anywhere, with or without Arca.</p></div>
            </div>

            <div class="tallyst-spacer tallyst-spacer--md"></div>

            <h2>Restoring is the dump in reverse</h2>
            <p>Because the format is standard, restoring is exactly what you'd expect — load the dump back into a fresh database. You can do it through Arca's restore UI, or straight from the command line if you're recovering onto a machine that doesn't have Arca installed:</p>
            <pre><code># MySQL
            mysql appdb &lt; appdb.sql

            # PostgreSQL
            pg_restore --dbname=appdb appdb.dump</code></pre>
            <p>That "with or without Arca" property is the whole point. Your backup tool should make you safer, not lock you into itself. If it vanished tomorrow, your database backups would still be plain, restorable dumps.</p>

            <p><a class="tallyst-btn tallyst-btn--ghost" href="/docs">Read the database docs [icon name=arrow-right]</a></p>
            HTML;
    }

    private function contentBlog5(): string
    {
        return <<<HTML
            <p>Where should your backups live? It's the first real decision after you install a backup tool, and the honest answer is "probably both." Local and cloud each solve a problem the other can't, and the good news is you don't have to choose. Let's break down the options, the rule of thumb that ties them together, and how to set up a job that covers all of it.</p>

            <h2>Local: fast, private, and right there</h2>
            <p>A local target — an external drive, a NAS on your network — is the fastest way to back up and the fastest way to restore. There's no upload to wait on, no account, no third party involved. When you need to pull back a file you deleted an hour ago, it's instant.</p>
            <p>The catch is obvious once you say it out loud: a local copy shares the same roof as the original. Fire, theft, flood, a spilled coffee that takes out the desk — any of those takes both the original and the backup sitting next to it. Local is necessary. It just isn't sufficient.</p>

            <h2>Cloud: off-site by default</h2>
            <p>An object-storage bucket (Backblaze B2, Amazon S3, Wasabi, Cloudflare R2) puts a copy somewhere the coffee can't reach. It's slower than local and it costs a little, but it's the copy that survives a genuinely bad day. And with Arca, the cloud copy is <span class="tallyst-color--brand">yours</span> in a way that matters: your data goes straight to your bucket, encrypted with your key. The provider stores bytes it can't read.</p>

            <h2>The 3-2-1 rule</h2>
            <p>The backup world has one durable piece of advice, and it's worth committing to memory:</p>
            <div class="tallyst-columns tallyst-columns--cards-tint" data-columns="3">
            <div class="tallyst-column"><p>[icon name=layers]</p><h3>3 copies</h3><p>Your live data, plus two backups. One backup is a single point of failure.</p></div>
            <div class="tallyst-column"><p>[icon name=database]</p><h3>2 kinds of media</h3><p>Don't keep both backups on the same type of storage.</p></div>
            <div class="tallyst-column"><p>[icon name=cloud-upload]</p><h3>1 off-site</h3><p>At least one copy somewhere else entirely.</p></div>
            </div>
            <p>A local drive plus a cloud bucket satisfies 3-2-1 on its own: your live data is copy one, the local drive is copy two on one kind of media, and the cloud bucket is copy three, off-site, on another. That's the whole rule, met with two targets.</p>

            <h2>Both, from a single job</h2>
            <p>Here's the part people miss: you don't have to run two separate backups to get two copies. Add a local target and a cloud target to the same job, and Arca writes to both in one run — a fast local copy you can restore from instantly, and an off-site cloud copy for the day you really need it.</p>
            <pre><code>Job: "Documents"
              Source:   C:\Users\me\Documents
              Target:   D:\backups          # local — fast restores
              Target:   Backblaze B2        # cloud — off-site copy
              Schedule: daily 02:00
              Encrypt:  on</code></pre>
            <p>One job, one schedule, both copies, encrypted. You set it up once and 3-2-1 just happens every night while you're asleep.</p>

            <div class="tallyst-spacer tallyst-spacer--md"></div>

            <p>Once your targets are sorted, the next question is how often to run — which is really a question about how much you'd hate to lose. That's covered here: <a href="/blog/scheduling-backups-that-actually-run">Scheduling backups that actually run</a>.</p>
            <p><a class="tallyst-btn tallyst-btn--primary" href="/buy">Set up your first job [icon name=arrow-right]</a></p>
            HTML;
    }

    private function contentBlog6(): string
    {
        return <<<HTML
            <p>Open your bank statement and count the monthly software charges. For most of us that list has grown quietly for years — a few euros here, ten there, each one individually reasonable and collectively a small fortune. When we built Arca, we decided it wouldn't be on that list. Arca is a one-time purchase: <span class="tallyst-color--brand">€29, paid once, yours forever</span>. Here's the thinking behind that, because it wasn't a marketing decision — it was a values one.</p>

            <h2>A backup tool is infrastructure, not a service</h2>
            <p>Some products genuinely earn a subscription. They run servers on your behalf, store your data, deliver something new and ongoing every month. A streaming service, a hosted database, a monitoring platform — those have real recurring costs, so a recurring price makes sense.</p>
            <p>A backup tool that runs on your own machine and writes to your own storage is not that. Once you've downloaded Arca, it doesn't cost us anything for you to keep using it. Charging rent every month for software that lives on your computer and talks to your bucket never sat right with us. You bought the software. It should be yours.</p>

            <h2>The incentive problem with subscriptions</h2>
            <p>Here's the part that's less obvious. A subscription quietly changes what a company optimizes for. Once your revenue depends on a recurring charge, the pressure shifts from "make something worth buying" to "make something hard to cancel." Those aren't the same goal, and sometimes they actively conflict.</p>
            <div class="tallyst-columns tallyst-columns--cards-tint" data-columns="3">
            <div class="tallyst-column"><p>[icon name=euro]</p><h3>Pay once</h3><p>€29, one time. No monthly line item to forget about.</p></div>
            <div class="tallyst-column"><p>[icon name=bolt]</p><h3>Updates included</h3><p>Free updates for the life of the product, not a new tier.</p></div>
            <div class="tallyst-column"><p>[icon name=boxes]</p><h3>No lock-in</h3><p>Open formats — you can leave whenever you like.</p></div>
            </div>
            <p>We'd rather earn your recommendation than depend on your inertia. Pay-once keeps our incentives pointed at the only thing that works long-term: building software people are genuinely glad they bought.</p>

            <h2>How we keep the lights on</h2>
            <p>The honest question is whether pay-once is sustainable, and it's a fair one — plenty of one-time-purchase tools have quietly died. It works for us for two reasons. First, we're small on purpose, so our costs are small. Second, and this is the important one: <strong>we don't store anyone's data</strong>. You bring your own storage, so the single biggest recurring cost in this category — running storage infrastructure for every customer, forever — simply isn't ours to carry.</p>
            <p>New licenses from people who like Arca and tell their friends fund the ongoing work. That's a slower growth curve than a subscription, and we're completely fine with that. Slower and honest beats faster and extractive.</p>

            <div class="tallyst-spacer tallyst-spacer--md"></div>

            <h2>What "forever" actually means</h2>
            <p>When you buy Arca, three things are true and stay true: the version you bought keeps working, every update we ship is free to you, and no feature you paid for gets quietly moved behind a new "Pro Plus" tier later. That last one is a promise we've watched other companies break, and we won't. The deal you get today is the deal.</p>

            <p><a class="tallyst-btn tallyst-btn--primary" href="/buy">Buy Arca — €29 once [icon name=arrow-right]</a></p>
            HTML;
    }

    private function contentBlog7(): string
    {
        return <<<HTML
            <p>"Incremental backup" sounds like something that could go wrong. It conjures an image of a fragile chain — each backup depending on the one before it, and if a single link breaks, everything after it is lost. That mental model scares people into running slow, wasteful full backups every night. The reality is much friendlier, especially with how Arca does it. Let's clear it up.</p>

            <h2>Full backups: simple, complete, wasteful</h2>
            <p>A full backup copies everything you selected, every single time. It's the easiest thing to reason about — each backup stands entirely on its own — but it's enormously wasteful. If a 50 GB folder changes by a few megabytes a day, a nightly full backup re-copies all 50 GB to capture those few megabytes. That's slow, it burns bandwidth if you're going to the cloud, and it fills your target with near-identical copies.</p>

            <h2>Incremental backups: only what changed</h2>
            <p>An incremental backup stores only what changed since the last run. You take one full baseline, and after that each night's backup is tiny — just the day's actual edits. A backup that took an hour now takes seconds, and uses a sliver of the space:</p>
            <pre><code>Mon   full          50 GB     (baseline)
            Tue   incremental   40 MB     (Tuesday's changes only)
            Wed   incremental   12 MB
            Thu   incremental   85 MB
            Fri   incremental   31 MB</code></pre>
            <p>Over a week that's roughly 50 GB plus a couple hundred megabytes, versus 250 GB for nightly fulls. <span class="tallyst-color--brand">The savings are enormous</span>, and they compound over months.</p>

            <h2>"But what if an early backup is damaged?"</h2>
            <p>This is the real fear, and it's where Arca's design matters. In an old-fashioned incremental scheme, Tuesday's backup really was a diff against Monday's, so a corrupt Monday could poison everything after it. That's the fragile chain people imagine.</p>
            <p>Arca doesn't work that way. Because it stores data as content-addressed chunks — the same mechanism that makes transfers resumable — a snapshot isn't a diff against yesterday. It's a <strong>complete list of the chunks that make up your data at that moment</strong>. Unchanged chunks are simply reused (not re-uploaded), but the snapshot references all of them.</p>
            <div class="tallyst-columns tallyst-columns--cards-tint" data-columns="2">
            <div class="tallyst-column"><p>[icon name=layers]</p><h3>Fast every night</h3><p>Only the day's changed chunks move, so backups finish in seconds.</p></div>
            <div class="tallyst-column"><p>[icon name=boxes]</p><h3>Every snapshot stands alone</h3><p>Each one references all its chunks — restore any point in time directly.</p></div>
            </div>
            <p>The practical upshot: every snapshot is independently restorable. You browse to any point in time and restore it directly, with no chain to replay and no single earlier backup that everything else depends on. You get the speed of incrementals with the safety of fulls.</p>

            <div class="tallyst-spacer tallyst-spacer--md"></div>

            <h2>You don't have to choose</h2>
            <p>With Arca there's no agonizing toggle. The first run of a job is a full baseline; every run after is incremental, automatically. You don't schedule "a full on Sundays" or manage retention chains by hand. It just does the fast, safe thing every night — and if you want to understand the chunking that makes it possible, that's here: <a href="/blog/chunk-based-backup-dropped-connection">How chunk-based backup survives a dropped connection</a>.</p>

            <p><a class="tallyst-btn tallyst-btn--ghost" href="/features">See how Arca stores backups [icon name=arrow-right]</a></p>
            HTML;
    }

    private function contentBlog8(): string
    {
        return <<<HTML
            <p>The moment a backup leaves your machine for someone else's storage, a question follows it: who can read this? For a backup — which by definition contains a copy of everything you care about — the only comfortable answer is "only me." That's what client-side encryption gives you, and the difference between it and the encryption your cloud provider advertises is worth understanding, because they are not the same thing.</p>

            <h2>"Encrypted at rest" isn't the same as private</h2>
            <p>Almost every cloud storage provider advertises encryption at rest: they encrypt your data on their disks. That's real and worth having — it protects against someone physically stealing a drive from their datacenter. But notice who holds the keys: <em>the provider does</em>. Which means the provider can read your data, and so can anyone who compels the provider to — a subpoena, a rogue employee, a breach of their key management. For a backup of your entire digital life, "the storage company can read it" may not be the bar you want.</p>

            <h2>Client-side encryption: locked before it leaves</h2>
            <p>Client-side encryption flips the order of operations. Arca encrypts the backup <strong>on your machine, before anything is uploaded</strong>. What lands in your bucket is ciphertext — meaningless without your key. The provider stores bytes it fundamentally cannot interpret.</p>
            <pre><code>your files
               │
               ▼  compress (zstd)
               ▼  encrypt  ← happens HERE, on your machine
               ▼  upload
               ▼
            [ cloud bucket ]   stores ciphertext only
            </code></pre>
            <p>The key never makes the trip. It stays on your machine, and <span class="tallyst-color--brand">we never see it</span> — there's no Arca server it passes through, because there's no Arca server at all.</p>

            <h2>The rule that makes it real: keys stay with you</h2>
            <p>Client-side encryption only means something if the key genuinely never leaves your control. Arca keeps your encryption key on your machine, full stop. That has a serious implication we won't hide from you, because hiding it would be dishonest:</p>
            <div class="tallyst-columns tallyst-columns--cards-tint" data-columns="2">
            <div class="tallyst-column"><p>[icon name=bolt]</p><h3>Encrypted before upload</h3><p>Your provider only ever stores ciphertext it cannot read.</p></div>
            <div class="tallyst-column"><p>[icon name=database]</p><h3>Your key, your responsibility</h3><p>It never leaves your machine — which means if you lose it, we can't recover your backups.</p></div>
            </div>
            <p>If you lose the key, we cannot get your data back. There is no reset link, no support ticket that recovers it, no master key we hold in reserve. That's not a gap in the product — it's the entire point. A backup only you can decrypt is also a backup only you are responsible for. We think that's the right trade for something this sensitive, and we'd rather be straight with you about it than pretend there's a safety net.</p>

            <div class="tallyst-spacer tallyst-spacer--md"></div>

            <h2>A practical note on key safety</h2>
            <p>Since the key is the one thing that can't be replaced, treat it like the important secret it is. Store a copy somewhere safe and — this is the key part — <em>separate from the machine you're backing up</em>. A password manager, a printed copy in a drawer, an encrypted note on your phone: any of those beats "only on the laptop I'm protecting," because if that laptop is what fails, you'll want the key to be somewhere else.</p>

            <p><a class="tallyst-btn tallyst-btn--primary" href="/buy">Back up privately with Arca [icon name=arrow-right]</a></p>
            HTML;
    }

    private function contentBlog9(): string
    {
        return <<<HTML
            <p>Every survey of data loss finds the same quiet truth: the backup that would have saved the day was the one nobody ran. Not a corrupted archive, not a failed restore — just a backup that was supposed to happen and didn't, because a human forgot. Manual backups fail for the most human reason there is. The fix is boring and completely effective: put it on a schedule and stop relying on memory.</p>

            <h2>Pick a time you're not using the machine</h2>
            <p>The classic choice is the middle of the night, and it's classic for good reasons: the machine is idle, you're not competing for disk or CPU, and a slow upload doesn't get in your way. Daily at 2 AM is a sensible default for most people:</p>
            <pre><code>Job: "Documents"
              Schedule: daily 02:00</code></pre>
            <p>One caveat: if your machine sleeps or shuts down at night, a 2 AM job never fires. In that case pick a time the machine is reliably awake — right after you usually sit down in the morning works well, and Arca will run the backup quietly in the background while you get coffee.</p>

            <h2>Match frequency to how much you'd hate to lose</h2>
            <p>How often to back up comes down to a single question: how much work are you willing to redo? The gap between backups is your <span class="tallyst-color--brand">recovery point</span> — the maximum you could lose if something fails right before the next run.</p>
            <div class="tallyst-columns tallyst-columns--cards-tint" data-columns="3">
            <div class="tallyst-column"><p>[icon name=layers]</p><h3>Daily</h3><p>Fine for documents and photos that change gradually. Worst case, you lose a day.</p></div>
            <div class="tallyst-column"><p>[icon name=bolt]</p><h3>Hourly</h3><p>For active work — code, writing — you'd hate to lose an afternoon of.</p></div>
            <div class="tallyst-column"><p>[icon name=database]</p><h3>Custom</h3><p>A cron-style expression for anything in between, or specific days.</p></div>
            </div>
            <p>Because Arca's backups are incremental after the first run, backing up more often is cheap. An hourly schedule doesn't move your whole source every hour — it moves only the last hour's changed chunks, which is usually tiny. Frequent backups don't cost what people assume they do.</p>

            <h2>Don't schedule and forget — verify</h2>
            <p>A schedule you never check is a guess dressed up as a plan. Arca shows you the status of every job: when it last ran, whether it succeeded, and how much it moved. Glance at that now and then. And every so often, do the one thing almost nobody does — actually restore a file:</p>
            <pre><code># The five-minute habit that turns a backup into a real one
            arca restore --job "Documents" \
                         --path "any-old-file.txt" \
                         --target ./verify</code></pre>
            <p>A backup you've never restored from is a backup you're only <em>hoping</em> works. Restoring one file, once a month, is the cheapest insurance there is — it proves the whole pipeline end to end, from snapshot to disk.</p>

            <div class="tallyst-spacer tallyst-spacer--md"></div>

            <h2>Set it once</h2>
            <p>That's the whole philosophy behind Arca, and behind good backup habits generally: set up the job, pick a schedule, verify occasionally, and otherwise let it run itself. The best backup routine is the one you don't have to think about — because it's already running, quietly and on time, while you're busy doing something else.</p>

            <p><a class="tallyst-btn tallyst-btn--primary" href="/buy">Set up automatic backups [icon name=arrow-right]</a></p>
            HTML;
    }
}
