<?php

namespace App\Controller\Admin;

use App\Mailer\MailProviderRegistry;
use App\Mailer\SettingsMailerTransport;
use App\Settings\SettingDefinition;
use App\Settings\SettingsManager;
use App\Settings\SettingsRegistry;
use App\Settings\SettingsTab;
use App\Settings\SettingType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tallyst\Media\Form\Type\MediaIdPickerType;
use Tallyst\Media\Form\Type\TiptapType;

/**
 * The friendly, grouped Settings form (replaces the raw Setting key/value CRUD in the menu).
 * Fields are built dynamically from the SettingsRegistry schema and saved through the typed
 * SettingsManager. Lives inside the EasyAdmin shell via the dashboardControllerFqcn default.
 * Admin-only (class-level ROLE_ADMIN) — Branding (logo + favicon) and Footer tabs live here.
 */
#[Route('/admin/settings', defaults: ['dashboardControllerFqcn' => 'App\Controller\Admin\DashboardController'])]
#[IsGranted('ROLE_ADMIN')]
class SettingsController extends AbstractController
{
    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly SettingsManager $settings,
        private readonly TranslatorInterface $translator,
        private readonly MailProviderRegistry $mailProviders,
    ) {
    }

    /**
     * /admin/settings has no tab of its own — send it to the first tab. This keeps the
     * `admin_settings` route name ALIVE (dashboard sidebar + maintenance banner link it).
     */
    #[Route('', name: 'admin_settings', methods: ['GET'])]
    public function index(): Response
    {
        $first = $this->registry->firstTabKey();
        if (null === $first) {
            throw $this->createNotFoundException('No settings sections registered.');
        }

        return $this->redirectToRoute('admin_settings_tab', ['tab' => $first]);
    }

    /**
     * One routed tab. Renders ONLY this tab's sections and builds ONLY their fields (a
     * per-tab form subset), POSTs to the same route, and redirects back to the SAME tab
     * after save (so you stay put instead of jumping to the first tab).
     *
     * The subset is what makes partial saves safe: setMany() only writes the keys present
     * in $form->getData(), so saving one tab NEVER touches another tab's secrets (Stripe/
     * SMTP), and an empty write-only secret is a no-op (see SettingsManager).
     *
     * Declared AFTER the /test-email route so that path isn't captured as {tab}.
     */
    #[Route('/{tab}', name: 'admin_settings_tab', requirements: ['tab' => '[a-z0-9_-]+'], methods: ['GET', 'POST'])]
    public function tab(string $tab, Request $request, SettingsMailerTransport $transport): Response
    {
        $current = $this->registry->getTab($tab);
        if (null === $current) {
            throw $this->createNotFoundException(sprintf('Unknown settings tab "%s".', $tab));
        }

        $form = $this->buildForm($current);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newData = $form->getData();
            // Captured BEFORE setMany() persists — compares the submitted mail-delivery keys
            // against what's currently stored, so the "restart your worker" notice only fires
            // when a save actually touches the provider/its fields (a save of an unrelated
            // field on the same tab, e.g. Sender, stays silent).
            $mailChanged = $this->mailDeliveryChanged($newData);

            $this->settings->setMany($newData);
            $this->addFlash('success', $this->translator->trans('admin.flash.settings_saved', [], 'admin'));

            if ($mailChanged) {
                // Honest, not alarmist — same tone as the readiness panel's worker-helper
                // notice ("example, depends on your server"). resolveTransport() already reads
                // fresh on every send (see SettingsMailerTransport), so this is a belt-and-
                // suspenders reminder, not a claim that mail is currently broken.
                $this->addFlash('warning', $this->translator->trans('admin.flash.mail_settings_changed_restart_worker', [], 'admin'));
            }

            return $this->redirectToRoute('admin_settings_tab', ['tab' => $current->key]);
        }

        return $this->render('admin/settings.html.twig', [
            'form' => $form->createView(),
            'tabs' => $this->registry->getTabs(),
            'current' => $current,
            'test_to_default' => $this->defaultTestRecipient(),
            // Warn (and let the admin re-enter the password) when a stored SMTP password can
            // no longer be decrypted — mail silently falls back to env until it's fixed.
            'smtp_password_unreadable' => !$this->settings->isEncryptedValueReadable('smtp_password'),
            // Only meaningful on the Email tab (the status line lives next to the test-mail
            // form there) — computed elsewhere too is harmless but pointless, so gate it.
            'active_transport' => $current->hasSection('email') ? $transport->activeTransportLabel() : null,
        ]);
    }

    /**
     * Whether the submitted data touches `mail_provider` or any provider-owned field. A
     * password/encrypted field is "changed" only when submitted NON-empty (write-only: an
     * empty submit is a no-op that keeps the stored secret, so it's not a real change);
     * every other field is changed when its (stringified) value differs from what's stored.
     * A tab that doesn't carry these keys at all (any tab but Email) naturally returns false —
     * the keys are simply absent from $newData.
     *
     * @param array<string, mixed> $newData
     */
    private function mailDeliveryChanged(array $newData): bool
    {
        $keys = ['mail_provider', ...$this->mailProviders->allFieldKeys()];

        foreach ($keys as $key) {
            if (!\array_key_exists($key, $newData)) {
                continue;
            }

            $def = $this->registry->getDefinition($key);
            if ($def?->type->isSecret()) {
                if ('' !== (string) ($newData[$key] ?? '')) {
                    return true;
                }
                continue;
            }

            if ((string) ($newData[$key] ?? '') !== (string) $this->settings->get($key)) {
                return true;
            }
        }

        return false;
    }

    // priority 10 ensures this concrete path is matched BEFORE the /{tab} catch-all below
    // (otherwise `test-email` would be captured as a tab and 404).
    #[Route('/test-email', name: 'admin_settings_test_email', methods: ['POST'], priority: 10)]
    public function testEmail(Request $request, SettingsMailerTransport $transport): Response
    {
        if (!$this->isCsrfTokenValid('settings_test_email', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $to = trim((string) $request->request->get('test_to')) ?: $this->defaultTestRecipient();
        if ('' === $to) {
            $this->addFlash('warning', $this->translator->trans('admin.flash.test_mail_recipient', [], 'admin'));

            return $this->redirectToEmailTab();
        }
        if (false === filter_var($to, \FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('warning', $this->translator->trans('admin.flash.test_mail_invalid', ['%email%' => $to], 'admin'));

            return $this->redirectToEmailTab();
        }

        // Which transport will actually carry it — reported in the result so there's no
        // guessing whether DB SMTP or the env fallback was used.
        $via = $transport->activeTransportLabel();

        // Send SYNCHRONOUSLY, straight to the transport — the app routes SendEmailMessage to
        // Messenger async, so $mailer->send() would only ENQUEUE (needing a running worker,
        // and any SMTP error would surface in the worker, not here). A test must verify SMTP
        // here and now. Because we bypass the bus, the bus-only DelayedEnvelope that lets
        // DefaultFromListener fill an empty From is NOT in play, so buildTestEmail() sets
        // From/Reply-To from settings explicitly (else Envelope::create() throws on an empty
        // From, and some SMTP servers reject a missing/mismatched From — a false failure).
        try {
            $transport->send($this->buildTestEmail($to));
            $this->addFlash('success', $this->translator->trans('admin.flash.test_mail_sent', ['%to%' => $to, '%via%' => $via], 'admin'));
        } catch (TransportExceptionInterface $e) {
            // The REAL transport error (auth failed, connection refused, …), not a generic one.
            $this->addFlash('danger', $this->translator->trans('admin.flash.test_mail_failed', ['%via%' => $via, '%error%' => $e->getMessage()], 'admin'));
        } catch (\Throwable $e) {
            $this->addFlash('danger', $this->translator->trans('admin.flash.test_mail_error', ['%type%' => (new \ReflectionClass($e))->getShortName(), '%error%' => $e->getMessage()], 'admin'));
        }

        return $this->redirectToEmailTab();
    }

    /**
     * Back to the tab that renders the `email` section (where the test-mail form lives),
     * falling back to /admin/settings if the section is somehow absent.
     */
    private function redirectToEmailTab(): Response
    {
        $tab = $this->registry->tabKeyForSection('email');

        return null !== $tab
            ? $this->redirectToRoute('admin_settings_tab', ['tab' => $tab])
            : $this->redirectToRoute('admin_settings');
    }

    /**
     * Build the test message with the From/Reply-To identity from settings explicitly set
     * (see testEmail() — DefaultFromListener can't be relied on for a direct transport send).
     * From falls back to the recipient so the message always has a valid sender.
     */
    protected function buildTestEmail(string $to): Email
    {
        $fromEmail = (string) $this->settings->get('mail_from_email') ?: $to;

        $email = (new Email())
            ->from(new Address($fromEmail, (string) $this->settings->get('mail_from_name')))
            ->to($to)
            ->subject($this->translator->trans('admin.settings.test_mail.subject', [], 'admin'))
            ->text($this->translator->trans('admin.settings.test_mail.body', [], 'admin'));

        $replyTo = (string) $this->settings->get('mail_reply_to');
        if ('' !== $replyTo) {
            $email->replyTo($replyTo);
        }

        return $email;
    }

    /**
     * Default test recipient: the configured sender, else the logged-in admin's identifier.
     */
    private function defaultTestRecipient(): string
    {
        $to = (string) $this->settings->get('mail_from_email');
        if ('' !== $to) {
            return $to;
        }

        return (string) ($this->getUser()?->getUserIdentifier() ?? '');
    }

    /**
     * Build the form for ONE tab — only its sections' definitions. This subset is what keeps
     * partial saves safe (see tab()).
     */
    private function buildForm(SettingsTab $tab): FormInterface
    {
        $definitions = $tab->definitions();

        $data = [];
        foreach ($definitions as $def) {
            $data[$def->key] = $this->settings->getForForm($def);
        }

        // Every setting label/help/choice-label is an `admin`-domain translation key (the providers
        // emit keys); a custom form does NOT inherit EA's domain, so set it here.
        $builder = $this->createFormBuilder($data, ['translation_domain' => 'admin']);
        foreach ($definitions as $def) {
            $builder->add($def->key, $this->formType($def), $this->formOptions($def));
        }

        return $builder->getForm();
    }

    private function formType(SettingDefinition $def): string
    {
        return match ($def->type) {
            SettingType::TEXT => TextareaType::class,
            SettingType::BOOL => CheckboxType::class,
            SettingType::INT => IntegerType::class,
            SettingType::CHOICE => ChoiceType::class,
            SettingType::EMAIL => EmailType::class,
            SettingType::PASSWORD => PasswordType::class,
            // Media module form types (Core-admin → Media, same precedent as PageCrudController):
            // the media-library id picker and the Tiptap editor, both string-backed so they fit
            // the Setting store. Their form themes are added in settings.html.twig.
            SettingType::MEDIA => MediaIdPickerType::class,
            SettingType::RICH_TEXT => TiptapType::class,
            default => TextType::class,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(SettingDefinition $def): array
    {
        $options = [
            'label' => $def->label,
            'required' => false,
            'help' => $def->help ?: null,
        ];

        if (SettingType::CHOICE === $def->type) {
            $options['choices'] = $def->choices;
        }

        if (SettingType::PASSWORD === $def->type) {
            // Write-only: never prefilled, empty submit keeps the stored value.
            // autocomplete=new-password STOPS the browser password manager from autofilling
            // this field — an autofilled (non-empty) value would bypass the empty=keep guard and
            // overwrite the real secret on Save (it silently broke the SMTP password before).
            $options['always_empty'] = true;
            // The placeholder is an attr (not auto-translated by the form's domain) → translate it
            // explicitly. Only the displayed text changes; the write-only mask logic is untouched.
            $options['attr'] = [
                'placeholder' => $this->translator->trans('admin.settings.secret_unchanged', [], 'admin'),
                'autocomplete' => 'new-password',
            ];
        }

        // Delivery tab reveal: the provider picker gets a narrower row + drives the
        // admin--mail-provider controller; every field a provider owns (SMTP's 5 fields,
        // Resend's 1, …) is tagged with that provider's key so the SAME generic JS shows only
        // the active provider's fields — driven entirely by MailProviderRegistry data, no
        // per-provider branch here or in the controller.
        //
        // ⚠ Stimulus targets are ALWAYS `data-<full-controller-identifier>-target` — for
        // `admin--mail-provider` that's `data-admin--mail-provider-target`, NOT
        // `data-mail-provider-target` (a plain "-provider" prefix silently matches nothing,
        // so apply() runs but finds zero targets and does nothing — every field then just sits
        // in its default, fully-visible server-rendered state, whichever provider is picked).
        // Same convention as the working FormBuilder precedent
        // (`data-formbuilder--formtype-target`, form_type_controller.js).
        if ('mail_provider' === $def->key) {
            // The select TARGET (its .value is read on change) must sit on the actual <select>
            // element — `attr`, not `row_attr` (a row_attr div has no .value; that mismatch was
            // the second half of this bug). The narrow-width class stays on the row.
            $options['row_attr'] = ['class' => 'settings-field-narrow'];
            $options['attr'] = [
                'data-admin--mail-provider-target' => 'select',
                'data-action' => 'change->admin--mail-provider#apply',
            ];
        } elseif (null !== ($providerKey = $this->mailProviders->providerKeyForField($def->key))) {
            // Field targets correctly stay on row_attr — hiding the WHOLE row (label + widget +
            // help), not just the input.
            $options['row_attr'] = [
                'data-admin--mail-provider-target' => 'field',
                'data-mail-provider-field' => $providerKey,
            ];
        }

        return $options;
    }
}
