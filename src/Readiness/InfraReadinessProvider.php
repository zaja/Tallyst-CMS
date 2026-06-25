<?php

namespace App\Readiness;

use App\Messenger\WorkerHeartbeat;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Infrastructure readiness checks: assets, filesystem, DB migrations, and the messenger worker.
 * These touch the real filesystem/DB (smoke-tested). The worker is checked via heartbeat (honest:
 * stale/missing => "provjeri ručno", never a hard dead claim), with the message queue backlog as
 * a supplementary signal.
 */
class InfraReadinessProvider implements ReadinessCheckProviderInterface
{
    private const G_ASSETS = 'Asseti';
    private const G_FS = 'Datotečni sustav';
    private const G_DB = 'Baza podataka';
    private const G_WORKER = 'Pozadinski procesi';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly Connection $connection,
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private readonly DependencyFactory $migrations,
        private readonly WorkerHeartbeat $heartbeat,
    ) {
    }

    public function getChecks(): iterable
    {
        yield $this->checkAssetsManifest();
        yield $this->checkThemePublished();
        yield $this->checkVarWritable();
        yield $this->checkUploadsWritable();
        yield $this->checkMigrations();
        yield $this->checkWorker();
        yield $this->checkQueue();
    }

    private function checkAssetsManifest(): Check
    {
        if (is_file($this->projectDir.'/public/assets/manifest.json')) {
            return Check::ok(self::G_ASSETS, 'Kompajlirani asseti', 'public/assets/manifest.json postoji.');
        }

        return Check::problem(self::G_ASSETS, 'Kompajlirani asseti',
            'public/assets/manifest.json ne postoji — JS/CSS ne radi (graf na nadzornoj ploči, pretraga, editor…).',
            'Pokreni: php8.5 bin/console asset-map:compile');
    }

    private function checkThemePublished(): Check
    {
        $path = $this->projectDir.'/public/themes/default';
        if (is_link($path) || is_dir($path)) {
            return Check::ok(self::G_ASSETS, 'Objavljena tema', 'public/themes/default postoji.');
        }

        return Check::problem(self::G_ASSETS, 'Objavljena tema',
            'public/themes/default ne postoji — front nema CSS teme.',
            'Pokreni: php8.5 bin/console app:theme:assets:install');
    }

    private function checkVarWritable(): Check
    {
        $var = $this->projectDir.'/var';
        if (is_dir($var) && is_writable($var)) {
            return Check::ok(self::G_FS, 'var/ zapisiv', 'cache/log direktorij je zapisiv.');
        }

        return Check::problem(self::G_FS, 'var/ zapisiv',
            'var/ nije zapisiv — cache i logovi će pucati.',
            sprintf('Dodijeli prava web korisniku: chmod -R u+rwX %s', $var));
    }

    private function checkUploadsWritable(): Check
    {
        $dir = $this->projectDir.'/public/media/uploads';
        if (!is_dir($dir)) {
            return Check::warning(self::G_FS, 'Upload direktorij',
                'public/media/uploads još ne postoji (stvorit će se pri prvom uploadu).',
                sprintf('Provjeri da je public/media zapisiv: chmod -R u+rwX %s/public/media', $this->projectDir));
        }
        if (is_writable($dir)) {
            return Check::ok(self::G_FS, 'Upload direktorij', 'public/media/uploads je zapisiv.');
        }

        return Check::problem(self::G_FS, 'Upload direktorij',
            'public/media/uploads nije zapisiv — uploadi slika će pucati.',
            sprintf('chmod -R u+rwX %s', $dir));
    }

    private function checkMigrations(): Check
    {
        try {
            $pending = \count($this->migrations->getMigrationStatusCalculator()->getNewMigrations());
        } catch (\Throwable $e) {
            return Check::problem(self::G_DB, 'Migracije',
                'Ne mogu provjeriti stanje migracija: '.$e->getMessage(),
                'Provjeri DB konekciju, pa pokreni: php8.5 bin/console doctrine:migrations:migrate');
        }

        if (0 === $pending) {
            return Check::ok(self::G_DB, 'Migracije', 'Sve migracije su pokrenute.');
        }

        return Check::problem(self::G_DB, 'Migracije',
            sprintf('%d migracija nije pokrenuto — shema baze je zastarjela.', $pending),
            'Pokreni: php8.5 bin/console doctrine:migrations:migrate');
    }

    private function checkWorker(): Check
    {
        $lastSeen = $this->heartbeat->lastSeen();
        if (null !== $lastSeen && $this->heartbeat->isFresh()) {
            return Check::ok(self::G_WORKER, 'Messenger worker',
                sprintf('Aktivan — zadnji put viđen prije %d s (heartbeat).', max(0, time() - $lastSeen)));
        }

        $detail = null === $lastSeen
            ? 'Worker se nije javio (heartbeat prazan) — možda ne radi, ili je tek pokrenut/restartan i još nije "kucnuo".'
            : sprintf('Worker se zadnji put javio prije %d s (zastarjelo) — možda je stao.', time() - $lastSeen);

        return Check::manual(self::G_WORKER, 'Messenger worker',
            $detail.' Ne mogu 100% potvrditi iz aplikacije.',
            'Provjeri ručno: systemctl --user status tallyst-messenger (pa po potrebi restart). Heartbeat se osvježava dok worker radi.');
    }

    private function checkQueue(): Check
    {
        try {
            $pending = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'default' AND delivered_at IS NULL"
            );
            $failed = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'"
            );
        } catch (\Throwable $e) {
            return Check::manual(self::G_WORKER, 'Red poruka',
                'Ne mogu pročitati red poruka: '.$e->getMessage(),
                'Provjeri DB / tablicu messenger_messages (migracije).');
        }

        if ($failed > 0) {
            return Check::warning(self::G_WORKER, 'Red poruka',
                sprintf('%d neuspjelih poruka (i %d na čekanju).', $failed, $pending),
                'Pogledaj: php8.5 bin/console messenger:failed:show — pa retry ili remove.');
        }
        if ($pending > 50) {
            return Check::warning(self::G_WORKER, 'Red poruka',
                sprintf('%d poruka na čekanju — ako broj raste, worker vjerojatno ne radi.', $pending),
                'Provjeri da worker radi (gore).');
        }

        return Check::ok(self::G_WORKER, 'Red poruka',
            sprintf('%d na čekanju, %d neuspjelih.', $pending, $failed));
    }
}
