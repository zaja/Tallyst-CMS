<?php

namespace App\Install;

use App\Entity\Menu;
use App\Entity\MenuItem;
use App\Entity\Page;
use App\Entity\Post;
use App\Entity\Theme;
use App\Repository\MenuRepository;
use App\Repository\PageRepository;
use App\Repository\PostRepository;
use App\Repository\ThemeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Idempotent baseline content seed: registers + activates the default theme and creates the
 * home page, a sample post, and the main menu when missing. Safe to re-run — existing records
 * are left untouched. Extracted from the original app:install seeder so the install wizard can
 * run it inside a fresh-kernel subprocess (which reads the just-written DATABASE_URL).
 */
class BaselineSeeder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ThemeRepository $themes,
        private readonly PageRepository $pages,
        private readonly PostRepository $posts,
        private readonly MenuRepository $menus,
    ) {
    }

    public function seed(SymfonyStyle $io): void
    {
        $this->ensureDefaultTheme($io);
        $homePage = $this->ensureHomePage($io);
        $this->ensureSamplePost($io);
        $this->ensureMainMenu($io, $homePage);

        $this->em->flush();
    }

    private function ensureDefaultTheme(SymfonyStyle $io): void
    {
        if (null !== $this->themes->findOneBy(['name' => 'default'])) {
            $io->writeln('• Default theme already present.');

            return;
        }

        $theme = (new Theme('default', 'Default'))->setActive(true);
        $this->em->persist($theme);
        $io->writeln('• Created + activated default theme.');
    }

    /**
     * Idempotent: the install baseline home ('Dobrodošli', slug 'home', non-demo). Public so the
     * demo uninstaller (DemoSeedCommand::clearDemo) can restore it after deleting the demo home,
     * keeping install→demo→delete symmetric (a persisted, editable home like a clean install).
     */
    public function ensureHomePage(SymfonyStyle $io): Page
    {
        $existing = $this->pages->findOneBy(['slug' => 'home']);
        if (null !== $existing) {
            $io->writeln('• Home page already present.');

            return $existing;
        }

        $page = (new Page('Dobrodošli', 'home'))
            ->setStatus(Page::STATUS_PUBLISHED)
            ->setContent('<p>Dobrodošli na Tallyst CMS. Ova početna stranica je generirana komandom <code>app:install</code>.</p>')
            ->setMetaDescription('Tallyst CMS — jednostavan modularni CMS.');
        $this->em->persist($page);
        $io->writeln('• Created home page.');

        return $page;
    }

    private function ensureSamplePost(SymfonyStyle $io): void
    {
        if (null !== $this->posts->findOneBy(['slug' => 'pozdrav-svijete'])) {
            $io->writeln('• Sample post already present.');

            return;
        }

        $post = (new Post('Pozdrav, svijete', 'pozdrav-svijete'))
            ->setStatus(Post::STATUS_PUBLISHED)
            ->setPublishedAt(new \DateTimeImmutable())
            ->setExcerpt('Prva objava na Tallyst blogu.')
            ->setContent('<p>Ovo je primjer objave. Uredi ili obriši je iz admina.</p>');
        $this->em->persist($post);
        $io->writeln('• Created sample post.');
    }

    private function ensureMainMenu(SymfonyStyle $io, Page $homePage): void
    {
        if (null !== $this->menus->findOneByLocation('main')) {
            $io->writeln('• Main menu already present.');

            return;
        }

        $menu = new Menu('Glavni izbornik', 'main');

        $home = (new MenuItem('Početna'))->setPage($homePage)->setPosition(0);
        $blog = (new MenuItem('Blog'))->setUrl('/blog')->setPosition(1);
        $menu->addItem($home);
        $menu->addItem($blog);

        $this->em->persist($menu);
        $io->writeln('• Created main menu with 2 items.');
    }
}
