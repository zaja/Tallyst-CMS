<?php

namespace App\Command;

use App\Entity\Category;
use App\Entity\Menu;
use App\Entity\MenuItem;
use App\Entity\Page;
use App\Entity\Post;
use App\Entity\Theme;
use App\Entity\User;
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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormField;
use Tallyst\FormBuilder\Repository\FormDefinitionRepository;
use App\Settings\SettingsManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tallyst\Media\Entity\Media;
use Tallyst\Media\Repository\MediaRepository;
use Tallyst\Media\Service\MediaUploader;

/**
 * Seeds CLEARLY-DEMO content so the whole front-end can be judged as a lens:
 * ~16 pages + 15 posts in a 2-level menu, exercising every editor output (headings,
 * lists, blockquote, code, columns, [image], [form id=N] incl. a page-as-product),
 * plus GD-generated neutral demo images in Media so featured + [image] render for real.
 *
 * SEPARATE from app:install (which seeds the minimal baseline). Re-runnable:
 *  - default: additive — creates anything missing by its fixed slug, skips what exists,
 *    and ALWAYS (re)builds the `main` menu (the demo owns it). No duplication on re-run.
 *  - --fresh: deletes the whole demo set first (by the fixed slugs / menu location /
 *    media originalName prefix), then recreates it. The supported full-reset path —
 *    e.g. the only way to reset the home page, which app:install also creates.
 *
 * Cleanup is deterministic: it only touches the fixed demo slugs and media whose
 * originalName starts with the demo prefix, so real content is never harmed.
 */
#[AsCommand(name: 'app:demo:seed', description: 'Seed clearly-demo content (pages, posts, 2-level menu, forms, images) to preview the front-end.')]
class DemoSeedCommand extends Command
{
    private const MEDIA_PREFIX = 'tallyst-demo-';
    private const MEDIA_COUNT = 6;
    private const MENU_LOCATION = 'main';

    /** Fixed demo page slugs — also the cleanup target for --fresh. */
    private const PAGE_SLUGS = [
        'home', 'o-nama', 'tim', 'kontakt', 'usluge', 'web-razvoj', 'konzalting',
        'dizajn', 'proizvodi', 'pro-licenca', 'cjenik', 'faq', 'galerija',
        'znacajke', 'privatnost', 'uvjeti',
    ];

    private const CATEGORY_SLUGS = ['novosti', 'vodici', 'razvoj'];

    private const FORM_SLUGS = ['demo-kontakt', 'demo-pro-licenca'];

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
        private readonly MediaUploader $uploader,
        private readonly SettingsManager $settings,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('fresh', null, InputOption::VALUE_NONE, 'Delete the existing demo set first, then recreate it (full reset).');
        $this->addOption('clear', null, InputOption::VALUE_NONE, 'Delete the demo set and stop (do NOT recreate it). The uninstall path.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // --clear = uninstall: delete the demo set by its fixed handles, then stop (no reseed).
        if ($input->getOption('clear')) {
            $io->section('Brišem demo sadržaj (--clear)');
            $this->clearDemo($io);
            $io->success('Demo sadržaj je obrisan.');

            return Command::SUCCESS;
        }

        if ($input->getOption('fresh')) {
            $io->section('Brišem postojeći demo (--fresh)');
            $this->clearDemo($io);
        }

        $this->ensureActiveTheme($io);

        $io->section('Slike (GD-generirane)');
        $media = $this->ensureMedia($io);

        $io->section('Forme');
        $forms = $this->ensureForms($io);

        $io->section('Kategorije');
        $categories = $this->ensureCategories($io, $media);

        $io->section('Demo autor');
        $author = $this->ensureDemoAuthor($io);

        $io->section('Stranice');
        $pages = $this->ensurePages($io, $media, $forms);

        $io->section('Objave');
        $this->ensurePosts($io, $media, $categories, $author);

        $io->section('Izbornik (2-razinski) — demo ga uvijek iznova gradi');
        $this->rebuildMenu($io, $pages);

        $io->section('Footer + favicon postavke');
        $this->ensureSiteSettings($io, $media);

        $this->em->flush();

        $io->success('Demo sadržaj je posijan. Pokreni "app:media:thumbnails:warm" ako sličice nedostaju, te pogledaj front na "/".');

        return Command::SUCCESS;
    }

    // ----------------------------------------------------------------- cleanup ---

    private function clearDemo(SymfonyStyle $io): void
    {
        // Menu first (cascades its items), then content, then media (featured FKs are
        // SET NULL so media removal is always safe). Posts before categories (FK).
        if (null !== $menu = $this->menus->findOneByLocation(self::MENU_LOCATION)) {
            $this->em->remove($menu);
            $io->writeln('• Obrisan glavni izbornik.');
        }

        $n = 0;
        foreach ($this->posts->findBy([]) as $post) {
            if (str_starts_with($post->getSlug(), 'demo-')) {
                $this->em->remove($post);
                ++$n;
            }
        }
        $io->writeln(sprintf('• Obrisano objava: %d.', $n));

        $n = 0;
        foreach (self::PAGE_SLUGS as $slug) {
            if (null !== $page = $this->pages->findOneBy(['slug' => $slug])) {
                $this->em->remove($page);
                ++$n;
            }
        }
        $io->writeln(sprintf('• Obrisano stranica: %d.', $n));

        $n = 0;
        foreach (self::CATEGORY_SLUGS as $slug) {
            if (null !== $cat = $this->categories->findOneBy(['slug' => $slug])) {
                $this->em->remove($cat);
                ++$n;
            }
        }
        $io->writeln(sprintf('• Obrisano kategorija: %d.', $n));

        $n = 0;
        foreach (self::FORM_SLUGS as $slug) {
            if (null !== $form = $this->forms->findOneBy(['slug' => $slug])) {
                $this->em->remove($form);
                ++$n;
            }
        }
        $io->writeln(sprintf('• Obrisano formi: %d.', $n));

        // Demo media (originalName starts with the prefix); Vich deletes the files.
        $n = 0;
        foreach ($this->mediaRepo->findBy([]) as $m) {
            if (null !== $m->getOriginalName() && str_starts_with($m->getOriginalName(), self::MEDIA_PREFIX)) {
                $this->em->remove($m);
                ++$n;
            }
        }
        $io->writeln(sprintf('• Obrisano slika: %d.', $n));

        // Demo author (posts are already gone above; SET NULL would protect anyway).
        if (null !== $author = $this->users->findOneBy(['email' => self::AUTHOR_EMAIL])) {
            $this->em->remove($author);
            $io->writeln('• Obrisan demo autor.');
        }

        $this->em->flush();
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
     * @return array<int, Media> keyed 1..MEDIA_COUNT
     */
    private function ensureMedia(SymfonyStyle $io): array
    {
        $alts = [
            1 => 'Apstraktna naslovna slika u plavim tonovima',
            2 => 'Apstraktna naslovna slika u zelenim tonovima',
            3 => 'Apstraktna naslovna slika u toplim tonovima',
            4 => 'Apstraktna naslovna slika u ljubičastim tonovima',
            5 => 'Apstraktna naslovna slika u sivo-plavim tonovima',
            6 => 'Apstraktna naslovna slika u tirkiznim tonovima',
        ];

        $media = [];
        for ($i = 1; $i <= self::MEDIA_COUNT; ++$i) {
            $name = self::MEDIA_PREFIX.$i.'.jpg';
            $existing = $this->mediaRepo->findOneBy(['originalName' => $name]);
            if (null !== $existing) {
                $media[$i] = $existing;
                continue;
            }

            $path = $this->generateImage($i);
            try {
                $m = $this->uploader->upload(new UploadedFile($path, $name, 'image/jpeg', null, true));
            } finally {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            $m->setTitle('Demo slika '.$i)->setAlt($alts[$i]);
            $media[$i] = $m;
            $io->writeln('• Generirana + učitana '.$name);
        }
        $this->em->flush();

        return $media;
    }

    /**
     * Build a neutral, modern abstract cover: a vertical gradient with two soft
     * translucent accents. Distinct per index. Returns a temp file path (JPEG).
     */
    private function generateImage(int $i): string
    {
        $palettes = [
            1 => [[37, 99, 235], [29, 78, 216], [96, 165, 250]],   // blue
            2 => [[16, 122, 87], [6, 95, 70], [52, 211, 153]],      // green
            3 => [[202, 138, 4], [180, 83, 9], [251, 191, 36]],     // amber
            4 => [[124, 58, 237], [109, 40, 217], [196, 181, 253]], // violet
            5 => [[51, 65, 85], [30, 41, 59], [148, 163, 184]],     // slate
            6 => [[13, 148, 136], [15, 118, 110], [94, 234, 212]],  // teal
        ];
        [$top, $bottom, $accent] = $palettes[(($i - 1) % 6) + 1];

        $w = 1200;
        $h = 800;
        $img = imagecreatetruecolor($w, $h);

        for ($y = 0; $y < $h; ++$y) {
            $t = $y / ($h - 1);
            $col = imagecolorallocate(
                $img,
                (int) round($top[0] + ($bottom[0] - $top[0]) * $t),
                (int) round($top[1] + ($bottom[1] - $top[1]) * $t),
                (int) round($top[2] + ($bottom[2] - $top[2]) * $t),
            );
            imageline($img, 0, $y, $w, $y, $col);
        }

        imagealphablending($img, true);
        $a1 = imagecolorallocatealpha($img, $accent[0], $accent[1], $accent[2], 95);
        imagefilledellipse($img, (int) ($w * 0.78), (int) ($h * 0.26), 560, 560, $a1);
        $a2 = imagecolorallocatealpha($img, 255, 255, 255, 112);
        imagefilledellipse($img, (int) ($w * 0.18), (int) ($h * 0.84), 380, 380, $a2);

        $path = sys_get_temp_dir().'/'.self::MEDIA_PREFIX.$i.'-'.bin2hex(random_bytes(4)).'.jpg';
        imagejpeg($img, $path, 84);
        imagedestroy($img);

        return $path;
    }

    // ------------------------------------------------------------------- forms ---

    /**
     * @return array{kontakt: FormDefinition, pro: FormDefinition}
     */
    private function ensureForms(SymfonyStyle $io): array
    {
        $kontakt = $this->forms->findOneBy(['slug' => 'demo-kontakt']);
        if (null === $kontakt) {
            $kontakt = (new FormDefinition())
                ->setName('Kontakt')
                ->setSlug('demo-kontakt')
                ->setStatus(FormDefinition::STATUS_PUBLISHED)
                ->setDescription('Javite nam se — odgovaramo u roku jednog radnog dana.');
            $this->addField($kontakt, FormField::TYPE_TEXT, 'ime', 'Ime i prezime', true, 0);
            $this->addField($kontakt, FormField::TYPE_EMAIL, 'email', 'E-mail', true, 1);
            $this->addField($kontakt, FormField::TYPE_TEXTAREA, 'poruka', 'Poruka', true, 2);
            $this->em->persist($kontakt);
            $io->writeln('• Kreirana forma "Kontakt" (besplatna).');
        } else {
            $io->writeln('• Forma "Kontakt" već postoji.');
        }

        $pro = $this->forms->findOneBy(['slug' => 'demo-pro-licenca']);
        if (null === $pro) {
            $pro = (new FormDefinition())
                ->setName('Pro licenca')
                ->setSlug('demo-pro-licenca')
                ->setStatus(FormDefinition::STATUS_PUBLISHED)
                ->setPriceMinor(4900)
                ->setCurrency('EUR')
                ->setDescription('Jednokratna doživotna licenca za Pro izdanje.');
            $this->addField($pro, FormField::TYPE_TEXT, 'ime', 'Ime i prezime', true, 0);
            $this->addField($pro, FormField::TYPE_EMAIL, 'email', 'E-mail za dostavu licence', true, 1);
            $this->addField($pro, FormField::TYPE_TEXT, 'tvrtka', 'Tvrtka (opcionalno)', false, 2);
            $this->em->persist($pro);
            $io->writeln('• Kreirana forma "Pro licenca" (plaćena, page-as-product).');
        } else {
            $io->writeln('• Forma "Pro licenca" već postoji.');
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
     * @param array<int, Media> $media
     *
     * @return array<string, Category>
     */
    private function ensureCategories(SymfonyStyle $io, array $media): array
    {
        $defs = [
            'novosti' => ['Novosti', 'Najave, izdanja i objave iz našeg tima.', 2],
            'vodici' => ['Vodiči', 'Praktični vodiči i upute korak po korak.', 6],
            'razvoj' => ['Razvoj', 'Tehničke bilješke i razmišljanja o razvoju.', 1],
        ];

        $out = [];
        foreach ($defs as $slug => [$name, $desc, $mi]) {
            $cat = $this->categories->findOneBy(['slug' => $slug]);
            if (null === $cat) {
                $cat = (new Category($name, $slug))
                    ->setDescription($desc)
                    ->setFeaturedImage($media[$mi] ?? null);
                $this->em->persist($cat);
                $io->writeln('• Kreirana kategorija "'.$name.'".');
            }
            $out[$slug] = $cat;
        }

        return $out;
    }

    // ------------------------------------------------------------------ author ---

    /**
     * A dedicated demo author so every demo post has a visible byline WITHOUT touching the real
     * admin's nickname. ROLE_EDITOR, and an unusable random password (nobody knows it → no login
     * vector). Idempotent: reused if it already exists; removed by --fresh (clearDemo).
     */
    private function ensureDemoAuthor(SymfonyStyle $io): User
    {
        $author = $this->users->findOneBy(['email' => self::AUTHOR_EMAIL]);
        if (null !== $author) {
            $io->writeln('• Demo autor već postoji.');

            return $author;
        }

        $author = (new User(self::AUTHOR_EMAIL))
            ->setNickname('Tallyst tim')
            ->setName('Demo Autor')
            ->setRoles(['ROLE_EDITOR']);
        $author->setPassword($this->hasher->hashPassword($author, bin2hex(random_bytes(32))));
        $this->em->persist($author);
        $this->em->flush();
        $io->writeln('• Kreiran demo autor (nadimak "Tallyst tim").');

        return $author;
    }

    // ------------------------------------------------------------------- pages ---

    /**
     * @param array<int, Media>      $media
     * @param array<string, FormDefinition> $forms
     *
     * @return array<string, Page>
     */
    private function ensurePages(SymfonyStyle $io, array $media, array $forms): array
    {
        $kontaktId = $forms['kontakt']->getId();
        $proId = $forms['pro']->getId();
        $img = static fn (int $i): int => $media[$i]?->getId() ?? 0;

        // [slug => [title, featuredMediaIndex|null, metaDescription, contentHtml]]
        $defs = [
            // home + usluge use a hero instead of a featured image (mi=null) — see $heroes below.
            'home' => ['Dobrodošli', null, 'Tallyst — gradimo i prodajemo vlastite aplikacije i usluge.', $this->contentHome($img(3))],
            'o-nama' => ['O nama', 5, 'Mali tim posvećen jednostavnim, kvalitetnim proizvodima.', $this->contentONama()],
            'tim' => ['Naš tim', 2, 'Ljudi iza Tallysta.', $this->contentTim($img(4))],
            'kontakt' => ['Kontakt', null, 'Javite nam se.', $this->contentKontakt($kontaktId)],
            'usluge' => ['Usluge', null, 'Što radimo za klijente.', $this->contentUsluge()],
            'web-razvoj' => ['Web razvoj', 1, 'Izrada modernih web aplikacija.', $this->contentWebRazvoj()],
            'konzalting' => ['Konzalting', 3, 'Savjetovanje o arhitekturi i proizvodu.', $this->contentKonzalting()],
            'dizajn' => ['Dizajn', 4, 'Dizajn sučelja i korisničkog iskustva.', $this->contentDizajn()],
            'proizvodi' => ['Proizvodi', 6, 'Naši gotovi proizvodi.', $this->contentProizvodi()],
            'pro-licenca' => ['Pro licenca', 2, 'Kupi Pro licencu — primjer stranice-kao-proizvoda.', $this->contentProLicenca($proId)],
            'cjenik' => ['Cjenik', null, 'Jednostavni, transparentni paketi.', $this->contentCjenik()],
            'faq' => ['Često postavljana pitanja', null, 'Odgovori na uobičajena pitanja.', $this->contentFaq()],
            'galerija' => ['Galerija', null, 'Nekoliko slika u sadržaju.', $this->contentGalerija($img(1), $img(4), $img(6))],
            'znacajke' => ['Značajke', 3, 'Pregled ključnih značajki.', $this->contentZnacajke($img(5))],
            'privatnost' => ['Pravila privatnosti', null, 'Kako postupamo s podacima.', $this->contentTextPage('Pravila privatnosti', 'Ovo je demo tekst pravila privatnosti. Zamijenite ga vlastitim.')],
            'uvjeti' => ['Uvjeti korištenja', null, 'Uvjeti korištenja usluge.', $this->contentTextPage('Uvjeti korištenja', 'Ovo je demo tekst uvjeta korištenja. Zamijenite ga vlastitim.')],
        ];

        // Demo heroes (opt-in, overlay) on a couple of pages so the feature is visible.
        // [slug => [mediaIndex, title, text, ctaLabel, ctaUrl]]
        $heroes = [
            'home' => [1, 'Predstavi i prodaj — sve na svojoj domeni', 'Tallyst je jednostavan, self-hosted CMS za samostalne autore aplikacija i usluga. Bez provizija, bez mjesečne pretplate.', 'Pogledaj usluge', '/usluge'],
            'usluge' => [6, 'Usluge po mjeri, bez nepotrebne težine', 'Od ideje do objavljenog proizvoda — web razvoj, konzalting i dizajn.', 'Zatraži ponudu', '/kontakt'],
        ];

        $out = [];
        foreach ($defs as $slug => [$title, $mi, $meta, $content]) {
            $page = $this->pages->findOneBy(['slug' => $slug]);
            if (null === $page) {
                $page = (new Page($title, $slug))
                    ->setStatus(Page::STATUS_PUBLISHED)
                    ->setContent($content)
                    ->setMetaDescription($meta)
                    ->setFeaturedImage(null === $mi ? null : ($media[$mi] ?? null));

                if (isset($heroes[$slug])) {
                    [$hi, $ht, $htext, $hcl, $hcu] = $heroes[$slug];
                    $page->setHeroEnabled(true)
                        ->setHeroImage($media[$hi] ?? null)
                        ->setHeroTitle($ht)
                        ->setHeroText($htext)
                        ->setHeroCtaLabel($hcl)
                        ->setHeroCtaUrl($hcu);
                }

                $this->em->persist($page);
                $io->writeln('• Kreirana stranica "'.$title.'".');
            }
            $out[$slug] = $page;
        }

        return $out;
    }

    // ------------------------------------------------------------------- posts ---

    /**
     * @param array<int, Media>     $media
     * @param array<string, Category> $categories
     */
    private function ensurePosts(SymfonyStyle $io, array $media, array $categories, User $author): void
    {
        $catKeys = array_keys($categories);
        $titles = [
            'Predstavljamo Tallyst',
            'Zašto biramo jednostavnost',
            'Kako smo posložili temu',
            'Pet savjeta za bržu naslovnicu',
            'Što znači "self-hosted"',
            'Naša priča o izradi proizvoda',
            'Vodič: postavljanje u pet minuta',
            'Dizajn koji se ne mota pod nogama',
            'Sigurnost bez kompliciranja',
            'Plaćanja: od forme do narudžbe',
            'Mali tim, velika fokusiranost',
            'Pisanje sadržaja koji se čita',
            'Mobilni prikaz na prvom mjestu',
            'Iza kulisa: naš tjedan',
            'Plan za sljedeće izdanje',
        ];

        $created = 0;
        foreach ($titles as $idx => $title) {
            $slug = 'demo-'.$this->slugify($title);
            if (null !== $this->posts->findOneBy(['slug' => $slug])) {
                continue;
            }

            $cat = $categories[$catKeys[$idx % count($catKeys)]];
            $mediaForPost = (0 === $idx % 2) ? ($media[(($idx) % self::MEDIA_COUNT) + 1] ?? null) : null;
            $rich = $idx < 3; // first few get the full kitchen-sink body

            $post = (new Post($title, $slug))
                ->setStatus(Post::STATUS_PUBLISHED)
                ->setPublishedAt(new \DateTimeImmutable('-'.($idx * 4 + 1).' days'))
                ->setExcerpt($this->excerptFor($title))
                ->setCategory($cat)
                ->setAuthor($author)
                ->setFeaturedImage($mediaForPost)
                ->setContent($rich ? $this->contentRichPost($title) : $this->contentSimplePost($title));
            $this->em->persist($post);
            ++$created;
        }

        $io->writeln(sprintf('• Kreirano objava: %d (preskočeno postojećih: %d).', $created, count($titles) - $created));
    }

    // ------------------------------------------------------------------- menu ---

    /**
     * @param array<string, Page> $pages
     */
    private function rebuildMenu(SymfonyStyle $io, array $pages): void
    {
        if (null !== $existing = $this->menus->findOneByLocation(self::MENU_LOCATION)) {
            $this->em->remove($existing);
            $this->em->flush();
        }

        $menu = new Menu('Glavni izbornik', self::MENU_LOCATION);

        // top-level item linked to a page
        $top = function (string $slug, int $pos) use ($menu, $pages): MenuItem {
            $item = (new MenuItem())->setPage($pages[$slug])->setPosition($pos);
            $menu->addItem($item);

            return $item;
        };
        // top-level item pointing at a raw URL (e.g. /blog)
        $topUrl = function (string $label, string $url, int $pos) use ($menu): MenuItem {
            $item = (new MenuItem($label))->setUrl($url)->setPosition($pos);
            $menu->addItem($item);

            return $item;
        };
        $child = function (MenuItem $parent, string $slug, int $pos) use ($menu, $pages): void {
            $item = (new MenuItem())->setPage($pages[$slug])->setParent($parent)->setPosition($pos);
            $menu->addItem($item);
        };

        // Labels come from the linked page title unless we override. MenuItem with a page
        // but empty label still needs a label for display, so set it explicitly.
        $pocetna = $top('home', 0)->setLabel('Početna');
        $onama = $top('o-nama', 1)->setLabel('O nama');
        $child($onama, 'tim', 0);
        $child($onama, 'kontakt', 1);

        $usluge = $top('usluge', 2)->setLabel('Usluge');
        $child($usluge, 'web-razvoj', 0);
        $child($usluge, 'konzalting', 1);
        $child($usluge, 'dizajn', 2);

        $proizvodi = $top('proizvodi', 3)->setLabel('Proizvodi');
        $child($proizvodi, 'pro-licenca', 0);

        $top('cjenik', 4)->setLabel('Cjenik');
        $topUrl('Blog', '/blog', 5);
        $top('faq', 6)->setLabel('FAQ');

        // ensure child labels are set (they were created with empty labels)
        foreach ($menu->getItems() as $item) {
            if ('' === $item->getLabel() && null !== $item->getPage()) {
                $item->setLabel($item->getPage()->getTitle());
            }
        }

        unset($pocetna); // silence unused-var; kept for readability above

        $this->em->persist($menu);
        $io->writeln('• Izgrađen 2-razinski glavni izbornik.');
    }

    // ------------------------------------------------------------------ footer ---

    /**
     * Configure a populated demo footer (2 columns: short text + the main menu) and a demo
     * favicon so both features are visible. The demo owns this presentation, like the menu.
     * (Logo is left unset — the site-name text header reads cleaner than a gradient blob.)
     *
     * @param array<int, Media> $media
     */
    private function ensureSiteSettings(SymfonyStyle $io, array $media): void
    {
        $this->settings->setMany([
            'footer_columns' => '2',
            'footer_text' => '<p><strong>Tallyst</strong> — jednostavan, self-hosted CMS za samostalne autore aplikacija i usluga. Predstavi i prodaj na vlastitoj domeni.</p>',
            'footer_menu' => self::MENU_LOCATION,
            'footer_copyright' => '',
            'footer_show_powered_by' => true,
            'favicon_media_id' => null !== ($media[2] ?? null) ? (string) $media[2]->getId() : '',
        ]);
        $io->writeln('• Postavljen demo footer (2 kolone: tekst + izbornik) + favicon.');
    }

    // -------------------------------------------------------------- content ---

    private function contentHome(int $imgId): string
    {
        return <<<HTML
            <p>Tallyst je mjesto gdje <strong>predstavljamo i prodajemo</strong> vlastite aplikacije i usluge — bez posrednika i mjesečnih naknada. Sve ostaje vaše: sadržaj, podaci i odnos s klijentom. Ova stranica je dio demo sadržaja koji prikazuje kako tema izgleda u stvarnoj upotrebi.</p>
            <p>Umjesto da plaćate proviziju vanjskim platformama, objavu i naplatu držite na vlastitoj domeni. Krenete malo, a sustav raste s vama.</p>
            <div class="tallyst-columns" data-columns="2">
                <div class="tallyst-column">
                    <h3>Za pružatelje usluga</h3>
                    <p>Predstavite svoj rad, prikupite upite i naplatite — sve na vlastitoj domeni, bez mjesečne pretplate.</p>
                </div>
                <div class="tallyst-column">
                    <h3>Za autore aplikacija</h3>
                    <p>Pretvorite bilo koju stranicu u proizvod jednim ugrađenim obrascem s plaćanjem.</p>
                </div>
            </div>
            <p>[image id={$imgId} size=medium align=center alt="Apstraktna naslovna ilustracija"]</p>
            <h2>Zašto Tallyst</h2>
            <p>Većina alata pokušava raditi sve i zato je teška za korištenje. Mi idemo suprotnim putem: nekoliko stvari, ali napravljenih kako treba. Manje opcija znači manje odluka i brži put do objave.</p>
            <ul>
                <li><strong>Jednostavno po dizajnu</strong> — čisto sučelje koje ne treba priručnik.</li>
                <li><strong>Sve vaše</strong> — self-hosted, bez zaključavanja u tuđu platformu.</li>
                <li><strong>Stranica = proizvod</strong> — naplata ugrađena tamo gdje je sadržaj.</li>
            </ul>
            <h2>Kako počinjete</h2>
            <ol>
                <li>Postavite Tallyst na vlastitu domenu.</li>
                <li>Napišite stranice i objave u čistom editoru.</li>
                <li>Ugradite obrazac s plaćanjem gdje želite prodavati.</li>
            </ol>
            <h2>Krenite odavde</h2>
            <ul>
                <li>Pogledajte <a href="/usluge">što radimo</a>.</li>
                <li>Provjerite <a href="/cjenik">cjenik</a> i <a href="/pro-licenca">Pro licencu</a>.</li>
                <li>Imate pitanje? <a href="/kontakt">Javite nam se</a>.</li>
            </ul>
            HTML;
    }

    private function contentONama(): string
    {
        return <<<HTML
            <p>Mi smo mali tim koji vjeruje da <strong>jednostavnost nije ograničenje, nego vrijednost</strong>. Gradimo proizvode koje i sami želimo koristiti — i upravo zato pažljivo biramo što <em>ne</em> ćemo dodati.</p>
            <p>Počeli smo kao osobni projekt: trebao nam je čist način da objavimo i prodamo vlastiti rad bez teških platformi i provizija. Ono što je riješilo naš problem pokazalo se korisnim i drugima, pa smo to izgladili u proizvod.</p>
            <h2>Naše vrijednosti</h2>
            <ul>
                <li><strong>Manje, ali bolje</strong> — svaka značajka mora zaraditi svoje mjesto.</li>
                <li><strong>Vlasništvo</strong> — vi držite podatke i odnos s klijentom.</li>
                <li><strong>Poštenje</strong> — transparentne cijene i jasna komunikacija.</li>
            </ul>
            <blockquote><p>„Najbolji alat je onaj koji se ne primjećuje dok radi svoj posao.”</p></blockquote>
            <h2>Kako radimo</h2>
            <p>Radimo u malim, jasno definiranim koracima i rano tražimo povratne informacije. Tako se smjer ispravlja dok je još jeftino, a vi vidite napredak svakog tjedna.</p>
            <p>Više o ljudima pročitajte na stranici <a href="/tim">Naš tim</a>, a ako želite suradnju, javite se preko <a href="/kontakt">kontakta</a>.</p>
            HTML;
    }

    private function contentTim(int $imgId): string
    {
        return <<<HTML
            <p>Iza Tallysta stoji nekoliko ljudi različitih vještina, ali istog cilja: napraviti alat koji je ugodan za svakodnevni rad.</p>
            <p>[image id={$imgId} size=medium align=right alt="Ilustracija tima"]</p>
            <p>Mali smo namjerno. Tako svatko vidi cijelu sliku, odluke su brze, a komunikacija s klijentima izravna — bez slojeva koji usporavaju.</p>
            <div class="tallyst-columns" data-columns="3">
                <div class="tallyst-column"><h3>Ana</h3><p>Proizvod i dizajn. Brine da sučelje ostane jednostavno dok značajke rastu.</p></div>
                <div class="tallyst-column"><h3>Marko</h3><p>Razvoj i infrastruktura. Drži sustav brzim, sigurnim i lakim za održavanje.</p></div>
                <div class="tallyst-column"><h3>Iva</h3><p>Podrška i sadržaj. Prva linija prema korisnicima i autorica vodiča.</p></div>
            </div>
            <h2>Kako surađujemo</h2>
            <p>Volimo raditi blisko s klijentima i isporučivati male, korisne promjene umjesto velikih, rizičnih skokova. Svaki tjedan donosi nešto opipljivo.</p>
            HTML;
    }

    private function contentKontakt(?int $formId): string
    {
        return <<<HTML
            <p>Imate pitanje, ideju za projekt ili vam treba pomoć? Najbrže ćemo odgovoriti putem obrasca ispod. Trudimo se javiti u roku jednog radnog dana.</p>
            <p>Recite nam ukratko što vam treba i, ako je relevantno, do kada — tako možemo odmah predložiti sljedeći korak.</p>
            [form id={$formId}]
            <h2>Drugi načini</h2>
            <p>Ako više volite e-mail, pišite na <a href="mailto:hello@example.com">hello@example.com</a>. Na hitne upite postojećih klijenata odgovaramo prioritetno.</p>
            HTML;
    }

    private function contentUsluge(): string
    {
        return <<<HTML
            <p>Nudimo nekoliko usluga koje se često kombiniraju u jednom projektu — od prve ideje do objavljenog, održivog proizvoda.</p>
            <div class="tallyst-columns" data-columns="3">
                <div class="tallyst-column"><h3><a href="/web-razvoj">Web razvoj</a></h3><p>Aplikacije po mjeri, bez nepotrebne težine.</p></div>
                <div class="tallyst-column"><h3><a href="/konzalting">Konzalting</a></h3><p>Prave odluke o arhitekturi i proizvodu.</p></div>
                <div class="tallyst-column"><h3><a href="/dizajn">Dizajn</a></h3><p>Sučelje koje korisnici odmah razumiju.</p></div>
            </div>
            <h2>Kako radimo</h2>
            <p>Ne počinjemo velikim ugovorom i mjesecima tišine. Počinjemo malim, jasnim korakom koji brzo daje rezultat — pa nastavljamo na temelju onoga što naučimo.</p>
            <ol>
                <li>Kratak razgovor o cilju i ograničenjima.</li>
                <li>Mali, jasno definiran prvi korak s vidljivim rezultatom.</li>
                <li>Redovite isporuke i povratne informacije svakog tjedna.</li>
            </ol>
            <h2>Za koga radimo</h2>
            <p>Najbolje surađujemo sa samostalnim autorima i malim timovima koji žele brzo objaviti nešto kvalitetno, bez nepotrebne složenosti. Niste sigurni spadate li tu? <a href="/kontakt">Pitajte nas</a>.</p>
            HTML;
    }

    private function contentProizvodi(): string
    {
        return <<<HTML
            <p>Osim usluga, nudimo i gotove proizvode koje možete kupiti i koristiti odmah — bez čekanja i bez pretplate.</p>
            <h2>Dostupno sada</h2>
            <ul>
                <li><a href="/pro-licenca">Pro licenca</a> — doživotni pristup naprednim značajkama uz jednokratno plaćanje.</li>
            </ul>
            <p>Svaka stranica u Tallystu može postati proizvod: dovoljno je ugraditi obrazac s cijenom i ona počinje naplaćivati. Pogledajte živi primjer na stranici <a href="/pro-licenca">Pro licenca</a>.</p>
            <blockquote><p>Prodajete uslugu umjesto proizvoda? Isti mehanizam radi — naplatite konzultaciju, radionicu ili pretplatu na isti način.</p></blockquote>
            HTML;
    }

    private function contentProLicenca(?int $formId): string
    {
        return <<<HTML
            <p>Pro izdanje otključava napredne značajke uz <strong>jednokratnu</strong> doživotnu licencu — bez pretplate i bez skrivenih troškova. Platite jednom, koristite zauvijek.</p>
            <h2>Što dobivate</h2>
            <ul>
                <li>Sve značajke besplatnog izdanja.</li>
                <li>Napredne značajke rezervirane za Pro korisnike.</li>
                <li>Prioritetnu podršku s bržim odgovorima.</li>
                <li>Buduća ažuriranja bez dodatnih troškova.</li>
            </ul>
            <h2>Kako kupnja teče</h2>
            <p>Ispunite obrazac, obavite sigurnu naplatu i licenca stiže na vašu e-mail adresu. Ovo je primjer <em>stranice-kao-proizvoda</em>: isti obrazac koji vidite ispod bilježi narudžbu i pokreće plaćanje.</p>
            [form id={$formId}]
            <p>Imate pitanje prije kupnje? Pogledajte <a href="/faq">česta pitanja</a> ili nam se <a href="/kontakt">javite</a>.</p>
            HTML;
    }

    private function contentWebRazvoj(): string
    {
        return <<<HTML
            <p>Gradimo brze, održive web aplikacije po mjeri — onoliko velike koliko problem traži, ni red koda više. Cilj je rješenje koje i za godinu dana razumijete i možete mijenjati.</p>
            <h2>Što radimo</h2>
            <ul>
                <li>Aplikacije i alati po mjeri vašeg procesa.</li>
                <li>Integracije s plaćanjima, e-poštom i vanjskim servisima.</li>
                <li>Migracije sa starih, teških sustava na nešto lakše.</li>
            </ul>
            <h2>Kako pristupamo</h2>
            <p>Prvo razumijemo problem, pa biramo najjednostavniju tehnologiju koja ga rješava. Izbjegavamo modu radi mode — manje pokretnih dijelova znači manje kvarova i niže troškove održavanja.</p>
            <blockquote><p>„Najbolji kod je onaj koji ne morate napisati.”</p></blockquote>
            <p>Spremni za razgovor? <a href="/kontakt">Javite nam se</a> i predložit ćemo mali prvi korak.</p>
            HTML;
    }

    private function contentKonzalting(): string
    {
        return <<<HTML
            <p>Pomažemo timovima donijeti prave tehničke i proizvodne odluke — prije nego što postanu skupe. Ponekad je najvrjednije ono što vam odgovorimo da <em>ne</em> radite.</p>
            <h2>Kada smo korisni</h2>
            <ul>
                <li>Birate arhitekturu ili tehnologiju za novi proizvod.</li>
                <li>Postojeći sustav je postao spor ili težak za promjene.</li>
                <li>Trebate nepristran pogled prije veće investicije.</li>
            </ul>
            <h2>Kako izgleda suradnja</h2>
            <p>Krećemo s kratkim pregledom stanja i ciljeva, a završavamo s konkretnim, prioritiziranim preporukama koje vaš tim može odmah primijeniti — bez vezivanja uz nas.</p>
            <p>Više o načinu rada pročitajte na <a href="/o-nama">O nama</a> ili <a href="/kontakt">dogovorite razgovor</a>.</p>
            HTML;
    }

    private function contentDizajn(): string
    {
        return <<<HTML
            <p>Dizajniramo čisto, pristupačno sučelje koje korisnici razumiju bez upute. Dobar dizajn ovdje ne znači ukras, nego da prava stvar bude očita.</p>
            <h2>Čime se bavimo</h2>
            <ul>
                <li>Dizajn sučelja i korisničkog iskustva (UI/UX).</li>
                <li>Pojednostavljivanje postojećih, prenatrpanih ekrana.</li>
                <li>Pristupačnost i čitljivost na svim veličinama zaslona.</li>
            </ul>
            <h2>Naša načela</h2>
            <p>Tipografija, razmak i jasna hijerarhija nose najveći dio posla. Mobilni prikaz tretiramo ravnopravno s desktopom jer sve više korisnika dolazi upravo s telefona.</p>
            <blockquote><p>„Jednostavnost je krajnja sofisticiranost.”</p></blockquote>
            <p>Želite svjež pogled na svoj proizvod? <a href="/kontakt">Pišite nam</a>.</p>
            HTML;
    }

    private function contentCjenik(): string
    {
        return <<<HTML
            <p>Jednostavni paketi bez skrivenih troškova. Bez pretplate na koju zaboravite — platite ono što vam treba i kad vam treba.</p>
            <p>Niste sigurni što odabrati? Krenite s besplatnim izdanjem; na Pro prelazite kad osjetite da vam treba više.</p>
            <div class="tallyst-columns" data-columns="3">
                <div class="tallyst-column">
                    <h3>Početni</h3>
                    <p><strong>0 €</strong></p>
                    <ul><li>Osnovne značajke</li><li>Zajednička podrška</li></ul>
                </div>
                <div class="tallyst-column">
                    <h3>Pro</h3>
                    <p><strong>49 €</strong> jednokratno</p>
                    <ul><li>Sve značajke</li><li>Prioritetna podrška</li></ul>
                    <p><a href="/pro-licenca">Kupi Pro</a></p>
                </div>
                <div class="tallyst-column">
                    <h3>Tim</h3>
                    <p><strong>Po dogovoru</strong></p>
                    <ul><li>Više korisnika</li><li>Prilagodbe</li></ul>
                    <p><a href="/kontakt">Kontakt</a></p>
                </div>
            </div>
            <p>Sve cijene su informativne i dio su demo sadržaja. Trebate li nešto izvan ovih paketa, <a href="/kontakt">dogovorit ćemo</a> rješenje po mjeri.</p>
            HTML;
    }

    private function contentFaq(): string
    {
        return <<<HTML
            <p>Najčešća pitanja i kratki odgovori. Ne nalazite svoje? <a href="/kontakt">Javite nam se</a>.</p>
            <h2>Je li Tallyst stvarno jednostavan?</h2>
            <p>Da — jednostavnost je namjerna. Radimo manje stvari, ali ih radimo dobro, pa nema priručnika od sto stranica ni izbornika u kojima se gubite.</p>
            <h2>Mogu li sam hostati?</h2>
            <p>Možete, i to je poanta. Tallyst je self-hosted: podaci, sadržaj i odnos s klijentima ostaju vaši, na vašoj domeni.</p>
            <h2>Kako funkcionira plaćanje?</h2>
            <p>Ugradite obrazac s cijenom u bilo koju stranicu i ona postaje proizvod. Naplata ide kroz provjereni tok plaćanja, a narudžba se zabilježi automatski.</p>
            <h2>Trebam li biti programer?</h2>
            <p>Za svakodnevno uređivanje sadržaja ne. Za prvo postavljanje na server pomaže tehničko predznanje — ili nam se javite pa pomognemo.</p>
            <h2>Mogu li kasnije promijeniti temu?</h2>
            <p>Da. Tema je odvojena od sadržaja, pa izgled mijenjate bez diranja stranica i objava.</p>
            <blockquote><p>Ne nalazite odgovor? <a href="/kontakt">Pitajte nas.</a></p></blockquote>
            HTML;
    }

    private function contentGalerija(int $a, int $b, int $c): string
    {
        return <<<HTML
            <p>Galerija pokazuje kako se slike ugrađuju u sadržaj pomoću <code>[image id=N]</code> oznake — pojedinačno, poravnato ili unutar stupaca. Sve slike u ovom demu su neutralni, generirani prikazi.</p>
            <p>[image id={$a} size=medium align=center alt="Demo slika 1"]</p>
            <h2>U dva stupca</h2>
            <p>Slike se mogu složiti i jednu pored druge; na užem zaslonu stupci se sami slažu jedan ispod drugog.</p>
            <div class="tallyst-columns" data-columns="2">
                <div class="tallyst-column"><p>[image id={$b} size=medium alt="Demo slika 2"]</p></div>
                <div class="tallyst-column"><p>[image id={$c} size=medium alt="Demo slika 3"]</p></div>
            </div>
            <p>Iste slike koristimo i kao naslovne (featured) na stranicama i objavama, pa galerija dobro pokazuje kako tema postupa s vizualnim sadržajem.</p>
            HTML;
    }

    private function contentZnacajke(int $imgId): string
    {
        return <<<HTML
            <p>Pregled onoga što čini Tallyst ugodnim za svakodnevni rad. Fokus je na malom broju značajki koje stvarno koristite, a ne na dugačkom popisu kvačica.</p>
            <div class="tallyst-columns" data-columns="2">
                <div class="tallyst-column">
                    <h3>Čist editor</h3>
                    <p>Naslovi, liste, citati, kod i slike — sve što treba, ništa suvišno. Sadržaj se sprema kao uredan HTML.</p>
                </div>
                <div class="tallyst-column">
                    <h3>Stranica = proizvod</h3>
                    <p>Ugradi obrazac s plaćanjem i prodaja kreće, bez zasebne trgovine.</p>
                </div>
            </div>
            <p>[image id={$imgId} size=medium align=center alt="Ilustracija značajki"]</p>
            <h2>Ugrađene oznake</h2>
            <p>Posebne oznake u sadržaju ubacuju dinamičke elemente — obrazac ili sliku — bez pisanja koda:</p>
            <pre><code>[form id=1]   // ugradi obrazac
            [image id=2]  // ugradi sliku</code></pre>
            <h2>Višestupčani raspored</h2>
            <p>Sadržaj se može složiti u dva ili tri stupca koji se na mobitelu automatski slažu jedan ispod drugog — kao u primjeru iznad.</p>
            HTML;
    }

    private function contentTextPage(string $heading, string $lead): string
    {
        return <<<HTML
            <p>{$lead}</p>
            <p>Ovo je demo sadržaj za stranicu „{$heading}”. Zamijenite ga vlastitim tekstom iz administracije — niže su primjeri sekcija koje takve stranice obično sadrže.</p>
            <h2>Opseg</h2>
            <p>Ovdje opišite na što se dokument odnosi i koga obvezuje. Jasan, kratak uvod pomaže čitatelju da odmah shvati je li tekst relevantan za njega.</p>
            <h2>Detalji</h2>
            <ul>
                <li>Jasno definiran opseg i pojmovi.</li>
                <li>Prava i obveze obiju strana.</li>
                <li>Kontakt za pitanja i izmjene.</li>
            </ul>
            <h2>Izmjene</h2>
            <p>Navedite kako i kada se dokument može mijenjati te kako o tome obavještavate korisnike. Za stvarni tekst posavjetujte se s pravnom osobom.</p>
            HTML;
    }

    private function contentRichPost(string $title): string
    {
        return <<<HTML
            <p>Ovo je demo objava „{$title}” koja prikazuje kako tema oblikuje <strong>bogat</strong> sadržaj: naslove, liste, citate, kod i slike.</p>
            <h2>Glavna ideja</h2>
            <p>Pišemo kratko i jasno. Evo nekoliko natuknica:</p>
            <ul>
                <li>Prva natuknica.</li>
                <li>Druga natuknica s <a href="/usluge">poveznicom</a>.</li>
                <li>Treća natuknica.</li>
            </ul>
            <blockquote><p>„Dobar tekst poštuje vrijeme čitatelja.”</p></blockquote>
            <h3>Primjer koda</h3>
            <pre><code>php8.5 bin/console app:demo:seed --fresh</code></pre>
            <p>I na kraju, mali zaključak koji povezuje sve gore navedeno.</p>
            HTML;
    }

    private function contentSimplePost(string $title): string
    {
        return <<<HTML
            <p>Demo objava „{$title}”. Ovaj tekst postoji da se vidi kako tema oblikuje uobičajenu objavu — odlomke, podnaslove i liste u čitljivom ritmu.</p>
            <p>Prvi odlomak obično postavlja temu i govori čitatelju zašto bi mu bila zanimljiva. Drži se kratkim i konkretnim; ostatak teksta razrađuje detalje.</p>
            <h2>Glavni dio</h2>
            <p>Ovdje ide srž objave. Možete se pozvati i na druge stranice, primjerice na <a href="/usluge">usluge</a> ili <a href="/kontakt">kontakt</a>, kako bi čitatelj imao jasan sljedeći korak.</p>
            <ul>
                <li>Prva ključna misao.</li>
                <li>Druga ključna misao.</li>
                <li>Treća ključna misao.</li>
            </ul>
            <p>Zaključite kratkim sažetkom ili pozivom na akciju. Zamijenite ovaj tekst stvarnim sadržajem iz administracije.</p>
            HTML;
    }

    private function excerptFor(string $title): string
    {
        return 'Kratak demo sažetak za objavu „'.$title.'” — dovoljno da se vidi kako izgleda popis bloga.';
    }

    private function slugify(string $text): string
    {
        $map = ['č' => 'c', 'ć' => 'c', 'đ' => 'd', 'š' => 's', 'ž' => 'z'];
        $text = strtr(mb_strtolower($text), $map);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';

        return trim($text, '-');
    }
}
