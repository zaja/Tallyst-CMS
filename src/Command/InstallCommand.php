<?php

namespace App\Command;

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
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Idempotent first-run seed: registers + activates the default theme and creates
 * baseline content (home page, sample post, main menu) when missing. Safe to
 * re-run — existing records are left untouched.
 */
#[AsCommand(name: 'app:install', description: 'Seed baseline CMS data (default theme, home page, menu).')]
class InstallCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ThemeRepository $themes,
        private readonly PageRepository $pages,
        private readonly PostRepository $posts,
        private readonly MenuRepository $menus,
    ) {
        parent::__construct();
    }

    protected function execute(\Symfony\Component\Console\Input\InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->ensureDefaultTheme($io);
        $homePage = $this->ensureHomePage($io);
        $this->ensureSamplePost($io);
        $this->ensureMainMenu($io, $homePage);

        $this->em->flush();

        $io->success('Tallyst install complete.');

        return Command::SUCCESS;
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

    private function ensureHomePage(SymfonyStyle $io): Page
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
