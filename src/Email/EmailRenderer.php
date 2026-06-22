<?php

namespace App\Email;

use App\Repository\EmailTemplateRepository;
use App\Settings\SettingsManager;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Turns an email type + tag VALUES into a subject + wrapped HTML body.
 *
 * SAFETY: this is SAFE placeholder replacement, NOT Twig evaluation of admin content (no SSTI).
 * Only the type's ADVERTISED {tags} are replaced — a known tag with no supplied value renders
 * EMPTY (never a literal "{tag}"), an unknown "{x}" is left as typed. In the BODY tag values are
 * htmlspecialchars-escaped (safe in text and in href); the admin's own markup passes through raw
 * (admin is trusted for markup). The SUBJECT is a header, so values are stripped of CR/LF
 * (header-injection safety) but not HTML-escaped. Only the base layout is real (trusted) Twig.
 *
 * All URLs are ABSOLUTE: built from the router CONTEXT, which is the request host in web and the
 * configured default_uri (DEFAULT_URI) in the worker — mail is sent without a request, so relative
 * URLs would break (the reset-link lesson).
 */
class EmailRenderer
{
    public function __construct(
        private readonly EmailTemplateRepository $templates,
        private readonly EmailTypeRegistry $registry,
        private readonly Environment $twig,
        private readonly SettingsManager $settings,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param array<string, scalar|null> $tagValues
     * @param string|null                $subjectOverride a per-send subject (e.g. a form's own
     *                                                     notifySubject); still tag-replaced. Wins
     *                                                     over the DB/default template subject.
     */
    public function render(string $typeKey, array $tagValues = [], ?string $subjectOverride = null): RenderedEmail
    {
        $type = $this->registry->get($typeKey);
        if (null === $type) {
            throw new \InvalidArgumentException(\sprintf('Unknown email type "%s".', $typeKey));
        }

        $override = $this->templates->findOneByIdentifier($typeKey);
        $subjectTpl = match (true) {
            null !== $subjectOverride && '' !== trim($subjectOverride) => $subjectOverride,
            $override && '' !== trim($override->getSubject()) => $override->getSubject(),
            default => $type->defaultSubject,
        };
        $bodyTpl = ($override && '' !== trim($override->getBody())) ? $override->getBody() : $type->defaultBody;

        // site_name is auto-provided (callers needn't repeat it); explicit values win.
        $values = array_merge(['site_name' => (string) ($this->settings->get('site_name') ?: 'Tallyst')], $tagValues);

        $subjectRepl = [];
        $bodyRepl = [];
        foreach (array_keys($type->tags) as $tag) {
            $raw = (string) ($values[$tag] ?? '');
            $subjectRepl['{'.$tag.'}'] = str_replace(["\r", "\n"], ' ', $raw);
            $bodyRepl['{'.$tag.'}'] = htmlspecialchars($raw, \ENT_QUOTES);
        }

        $subject = strtr($subjectTpl, $subjectRepl);
        $bodyHtml = strtr($bodyTpl, $bodyRepl);

        $html = $this->twig->render('emails/base.html.twig', [
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'base_url' => $this->baseUrl(),
        ]);

        return new RenderedEmail($subject, $html, $this->toText($bodyHtml));
    }

    /** Required tags present in the body? Used by the admin editor (reset needs {reset_url}). */
    public function bodyMissingRequiredTags(string $typeKey, string $body): array
    {
        $type = $this->registry->get($typeKey);
        if (null === $type) {
            return [];
        }

        return array_values(array_filter(
            $type->requiredTags,
            static fn (string $tag): bool => !str_contains($body, '{'.$tag.'}'),
        ));
    }

    private function baseUrl(): string
    {
        $ctx = $this->urls->getContext();
        $scheme = $ctx->getScheme();
        $host = $ctx->getHost();

        $port = '';
        if ('http' === $scheme && 0 !== $ctx->getHttpPort() && 80 !== $ctx->getHttpPort()) {
            $port = ':'.$ctx->getHttpPort();
        } elseif ('https' === $scheme && 0 !== $ctx->getHttpsPort() && 443 !== $ctx->getHttpsPort()) {
            $port = ':'.$ctx->getHttpsPort();
        }

        return $scheme.'://'.$host.$port.$ctx->getBaseUrl();
    }

    private function toText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), \ENT_QUOTES);

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }
}
