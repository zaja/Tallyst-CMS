<?php

namespace App\Controller\Admin;

use App\Mailer\SettingsMailerTransport;
use App\Settings\SettingDefinition;
use App\Settings\SettingsManager;
use App\Settings\SettingsRegistry;
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
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The friendly, grouped Settings form (replaces the raw Setting key/value CRUD in the menu).
 * Fields are built dynamically from the SettingsRegistry schema and saved through the typed
 * SettingsManager. Lives inside the EasyAdmin shell via the dashboardControllerFqcn default,
 * same as BrandingController. Admin-only (covered by the ^/admin firewall).
 */
#[Route('/admin/settings', defaults: ['dashboardControllerFqcn' => 'App\Controller\Admin\DashboardController'])]
class SettingsController extends AbstractController
{
    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly SettingsManager $settings,
    ) {
    }

    #[Route('', name: 'admin_settings', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $form = $this->buildForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->settings->setMany($form->getData());
            $this->addFlash('success', 'Postavke su spremljene.');

            return $this->redirectToRoute('admin_settings');
        }

        return $this->render('admin/settings.html.twig', [
            'form' => $form->createView(),
            'sections' => $this->registry->getSections(),
            'test_to_default' => $this->defaultTestRecipient(),
            // Warn (and let the admin re-enter the password) when a stored SMTP password can
            // no longer be decrypted — mail silently falls back to env until it's fixed.
            'smtp_password_unreadable' => !$this->settings->isEncryptedValueReadable('smtp_password'),
        ]);
    }

    #[Route('/test-email', name: 'admin_settings_test_email', methods: ['POST'])]
    public function testEmail(Request $request, MailerInterface $mailer, SettingsMailerTransport $transport): Response
    {
        if (!$this->isCsrfTokenValid('settings_test_email', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $to = trim((string) $request->request->get('test_to')) ?: $this->defaultTestRecipient();
        if ('' === $to) {
            $this->addFlash('warning', 'Upiši adresu primatelja (ili postavi "Pošiljatelj (email)").');

            return $this->redirectToRoute('admin_settings');
        }
        if (false === filter_var($to, \FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('warning', \sprintf('"%s" nije ispravna email adresa.', $to));

            return $this->redirectToRoute('admin_settings');
        }

        // Which transport will actually carry it — reported in the result so there's no
        // guessing whether DB SMTP or the env fallback was used.
        $via = $transport->activeTransportLabel();

        // From/Reply-To are filled by DefaultFromListener.
        $email = (new Email())
            ->to($to)
            ->subject('Tallyst — test e-pošte')
            ->text('Ovo je test poruka iz Tallyst Postavki. Ako je vidiš, konfiguracija radi.');

        try {
            $mailer->send($email);
            $this->addFlash('success', \sprintf('Poslano na %s preko %s.', $to, $via));
        } catch (TransportExceptionInterface $e) {
            // The REAL transport error (auth failed, connection refused, …), not a generic one.
            $this->addFlash('danger', \sprintf('Slanje preko %s nije uspjelo: %s', $via, $e->getMessage()));
        } catch (\Throwable $e) {
            $this->addFlash('danger', \sprintf('Greška (%s): %s', (new \ReflectionClass($e))->getShortName(), $e->getMessage()));
        }

        return $this->redirectToRoute('admin_settings');
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

    private function buildForm(): FormInterface
    {
        $data = [];
        foreach ($this->registry->allDefinitions() as $def) {
            $data[$def->key] = $this->settings->getForForm($def);
        }

        $builder = $this->createFormBuilder($data);
        foreach ($this->registry->allDefinitions() as $def) {
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
            $options['always_empty'] = true;
            $options['attr'] = ['placeholder' => '•••• nepromijenjeno'];
        }

        return $options;
    }
}
