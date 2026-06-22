<?php

namespace App\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Self-service 2FA management ("Sigurnost"). Each user enrols their OWN device:
 * confirm-before-activate (a stored-but-unconfirmed secret is inert, so a mis-scan can't
 * lock you out), backup codes shown ONCE, and disabling requires the current password (a
 * hijacked session can't strip 2FA). Lives in the EA shell, visible to all logged-in users.
 */
#[Route('/admin/security', defaults: ['dashboardControllerFqcn' => 'App\Controller\Admin\DashboardController'])]
#[IsGranted('ROLE_EDITOR')]
class SecurityController extends AbstractController
{
    private const int BACKUP_CODE_COUNT = 8;
    private const string SESSION_BACKUP_KEY = '2fa_backup_codes_plain';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TotpAuthenticatorInterface $totp,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    #[Route('', name: 'admin_security', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('admin/security.html.twig', ['enabled' => $user->isTotpAuthenticationEnabled()]);
    }

    /**
     * Enrolment. GET (re)generates a FRESH secret (overwrites any stale pending one) and shows
     * the QR + secret. POST confirms a code → only THEN is 2FA activated + backup codes issued.
     */
    #[Route('/2fa/enable', name: 'admin_security_2fa_enable', methods: ['GET', 'POST'])]
    public function enable(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($user->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('admin_security');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('2fa_enable', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $code = trim((string) $request->request->get('code'));
            if (null === $user->getTotpSecret() || !$this->totp->checkCode($user, $code)) {
                $this->addFlash('danger', 'Kod nije ispravan. Skeniraj QR i pokušaj ponovno.');

                return $this->redirectToRoute('admin_security_2fa_enable');
            }

            // Confirmed → activate + issue backup codes (stored hashed; plaintext shown once).
            $plain = $this->generateBackupCodes();
            $user->setTotpEnabled(true)->setBackupCodesFromPlain($plain);
            $this->em->flush();

            $request->getSession()->set(self::SESSION_BACKUP_KEY, $plain);
            $this->addFlash('success', 'Dvostruka provjera je uključena.');

            return $this->redirectToRoute('admin_security_2fa_backup_codes');
        }

        // Fresh secret on every GET (overwrites a stale pending one), kept UNCONFIRMED/inert.
        $secret = $this->totp->generateSecret();
        $user->setTotpSecret($secret)->setTotpEnabled(false);
        $this->em->flush();

        $qr = (new Builder())->build(data: $this->totp->getQRContent($user), size: 220, margin: 8)->getDataUri();

        return $this->render('admin/security_2fa_enable.html.twig', ['qr' => $qr, 'secret' => $secret]);
    }

    /** Show the freshly generated backup codes ONCE (read + cleared from the session). */
    #[Route('/2fa/backup-codes', name: 'admin_security_2fa_backup_codes', methods: ['GET'])]
    public function backupCodes(Request $request): Response
    {
        $codes = $request->getSession()->remove(self::SESSION_BACKUP_KEY);
        if (!is_array($codes) || [] === $codes) {
            return $this->redirectToRoute('admin_security');
        }

        return $this->render('admin/security_2fa_backup_codes.html.twig', ['codes' => $codes]);
    }

    #[Route('/2fa/disable', name: 'admin_security_2fa_disable', methods: ['POST'])]
    public function disable(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('2fa_disable', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // Re-auth: a hijacked session must not be able to strip 2FA without the password.
        if (!$this->hasher->isPasswordValid($user, (string) $request->request->get('password'))) {
            $this->addFlash('danger', 'Lozinka nije ispravna — 2FA nije isključen.');

            return $this->redirectToRoute('admin_security');
        }

        $user->setTotpSecret(null)->setTotpEnabled(false)->setBackupCodes(null);
        $this->em->flush();
        $this->addFlash('success', 'Dvostruka provjera je isključena.');

        return $this->redirectToRoute('admin_security');
    }

    /** @return string[] high-entropy codes (10 chars × 32-symbol alphabet ≈ 50 bits → sha256 ok) */
    private function generateBackupCodes(): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I to avoid mis-reads
        $max = \strlen($alphabet) - 1;
        $codes = [];
        for ($i = 0; $i < self::BACKUP_CODE_COUNT; ++$i) {
            $code = '';
            for ($j = 0; $j < 10; ++$j) {
                $code .= $alphabet[random_int(0, $max)];
            }
            $codes[] = $code;
        }

        return $codes;
    }
}
