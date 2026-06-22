<?php

namespace App\Tests\Email;

use App\Email\EmailRenderer;
use App\Email\EmailType;
use App\Email\EmailTypeProviderInterface;
use App\Email\EmailTypeRegistry;
use App\Repository\EmailTemplateRepository;
use App\Settings\SettingsManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Locks the renderer's two security-critical behaviours: tag VALUES are HTML-escaped (no
 * injection via a tag value) and a known tag with no value renders empty (never a literal
 * "{tag}"). Twig is mocked to return the wrapped body_html so we can assert on it.
 */
class EmailRendererTest extends TestCase
{
    private function renderer(): EmailRenderer
    {
        $provider = new class implements EmailTypeProviderInterface {
            public function getEmailTypes(): iterable
            {
                yield new EmailType(
                    key: 'test',
                    label: 'Test',
                    tags: ['name' => 'Name', 'reset_url' => 'URL'],
                    requiredTags: ['reset_url'],
                    canDisable: true,
                    defaultSubject: 'Hi {name}',
                    defaultBody: '<p>Hello {name} — {reset_url}</p>',
                );
            }
        };

        $templates = $this->createStub(EmailTemplateRepository::class);
        $templates->method('findOneByIdentifier')->willReturn(null); // use defaults

        $settings = $this->createStub(SettingsManager::class);
        $settings->method('get')->willReturn('Site');

        // Real Twig with a stand-in base layout that just emits the (already-escaped) body, so
        // assertions can inspect it without mocking Environment.
        $twig = new Environment(new ArrayLoader(['emails/base.html.twig' => '{{ body_html|raw }}']));

        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('getContext')->willReturn((new RequestContext())->setScheme('https')->setHost('example.test'));

        return new EmailRenderer($templates, new EmailTypeRegistry([$provider]), $twig, $settings, $urls);
    }

    public function testTagValuesAreHtmlEscapedInBody(): void
    {
        $out = $this->renderer()->render('test', ['name' => '<script>alert(1)</script>', 'reset_url' => 'https://x/y']);

        self::assertStringNotContainsString('<script>', $out->html);
        self::assertStringContainsString('&lt;script&gt;', $out->html);
    }

    public function testKnownTagWithoutValueRendersEmptyNotLiteral(): void
    {
        $out = $this->renderer()->render('test', ['reset_url' => 'https://x/y']);

        self::assertStringNotContainsString('{name}', $out->html);
        self::assertStringContainsString('Hello  —', $out->html); // name → empty
    }

    public function testSubjectStripsNewlinesFromValues(): void
    {
        $out = $this->renderer()->render('test', ['name' => "Evil\r\nBcc: x@y", 'reset_url' => 'u']);

        self::assertStringNotContainsString("\n", $out->subject);
        self::assertStringNotContainsString("\r", $out->subject);
    }

    public function testBodyMissingRequiredTagsDetectsResetUrl(): void
    {
        $r = $this->renderer();
        self::assertSame(['reset_url'], $r->bodyMissingRequiredTags('test', '<p>no token here</p>'));
        self::assertSame([], $r->bodyMissingRequiredTags('test', '<p>{reset_url}</p>'));
    }
}
