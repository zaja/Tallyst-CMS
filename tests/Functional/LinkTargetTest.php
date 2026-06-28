<?php

namespace App\Tests\Functional;

use App\Entity\Page;
use App\Entity\Post;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * /admin/link-targets feeds the editor's internal-link picker: published Pages + Posts only,
 * each with a relative URL resolved through the real router (home slug → "/"), as JSON.
 */
class LinkTargetTest extends WebTestCase
{
    public function testListsPublishedPagesAndPostsWithResolvedUrls(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $rnd = bin2hex(random_bytes(4));
        $pubSlug = 'link-page-pub-'.$rnd;
        $draftSlug = 'link-page-draft-'.$rnd;
        $postSlug = 'link-post-'.$rnd;

        $pub = (new Page('Objavljena '.$rnd, $pubSlug))->setStatus(Page::STATUS_PUBLISHED);
        $draft = (new Page('Skica '.$rnd, $draftSlug))->setStatus(Page::STATUS_DRAFT);
        $em->persist($pub);
        $em->persist($draft);

        $post = (new Post('Objava '.$rnd, $postSlug))
            ->setStatus(Post::STATUS_PUBLISHED)
            ->setPublishedAt(new \DateTimeImmutable('2026-05-20'));
        $draftPost = (new Post('Skica objave '.$rnd, 'link-post-draft-'.$rnd))->setStatus(Post::STATUS_DRAFT);
        $em->persist($post);
        $em->persist($draftPost);

        if (null === $em->getRepository(Page::class)->findOneBy(['slug' => 'home'])) {
            $em->persist((new Page('Naslovnica', 'home'))->setStatus(Page::STATUS_PUBLISHED));
        }
        $em->flush();

        $client->loginUser($this->makeEditor($em, $container->get(UserPasswordHasherInterface::class)));
        $client->request('GET', '/admin/link-targets');

        self::assertResponseIsSuccessful();
        self::assertJson((string) $client->getResponse()->getContent());
        $items = json_decode((string) $client->getResponse()->getContent(), true)['items'] ?? [];

        $byTitle = [];
        foreach ($items as $i) {
            $byTitle[$i['title']] = $i;
        }

        // Published page present, with the page_show relative URL.
        self::assertArrayHasKey('Objavljena '.$rnd, $byTitle);
        self::assertSame('page', $byTitle['Objavljena '.$rnd]['type']);
        self::assertSame('/'.$pubSlug, $byTitle['Objavljena '.$rnd]['url']);

        // Published post present, with the blog_post relative URL.
        self::assertArrayHasKey('Objava '.$rnd, $byTitle);
        self::assertSame('post', $byTitle['Objava '.$rnd]['type']);
        self::assertSame('/blog/'.$postSlug, $byTitle['Objava '.$rnd]['url']);

        // Drafts excluded (no public URL).
        self::assertArrayNotHasKey('Skica '.$rnd, $byTitle);
        self::assertArrayNotHasKey('Skica objave '.$rnd, $byTitle);

        // The home page resolves to "/" (the home route), never "/home".
        $home = array_filter($items, static fn (array $i): bool => '/' === $i['url']);
        self::assertNotEmpty($home, 'home page resolves to "/"');
        foreach ($items as $i) {
            self::assertNotSame('/home', $i['url'], 'home slug must never be linked as /home');
        }
    }

    public function testRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/link-targets');
        // Anonymous → redirected to the login (firewall), never a 200 JSON feed.
        self::assertResponseStatusCodeSame(302);
    }

    private function makeEditor(EntityManagerInterface $em, UserPasswordHasherInterface $hasher): User
    {
        $user = (new User('link_target_'.bin2hex(random_bytes(6)).'@test.local'))->setRoles(['ROLE_EDITOR']);
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
