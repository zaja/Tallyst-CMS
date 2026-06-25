<?php

namespace App\Readiness;

use App\Messenger\WorkerHeartbeat;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Infrastructure readiness checks: assets, filesystem, DB migrations, and the messenger worker.
 * These touch the real filesystem/DB (smoke-tested). The worker is checked via heartbeat (honest:
 * stale/missing => "provjeri ručno", never a hard dead claim), with the message queue backlog as
 * a supplementary signal.
 */
class InfraReadinessProvider implements ReadinessCheckProviderInterface
{
    // Group names are `admin`-domain keys (translated via t()); labels + detail/fix are keys too.
    private const G_ASSETS = 'admin.readiness.group.assets';
    private const G_FS = 'admin.readiness.group.fs';
    private const G_DB = 'admin.readiness.group.db';
    private const G_WORKER = 'admin.readiness.group.worker';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly Connection $connection,
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private readonly DependencyFactory $migrations,
        private readonly WorkerHeartbeat $heartbeat,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** @param array<string, string|int> $params */
    private function t(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params, 'admin');
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
        $g = $this->t(self::G_ASSETS);
        $label = $this->t('admin.readiness.assets_manifest.label');
        if (is_file($this->projectDir.'/public/assets/manifest.json')) {
            return Check::ok($g, $label, $this->t('admin.readiness.assets_manifest.detail.ok'));
        }

        return Check::problem($g, $label,
            $this->t('admin.readiness.assets_manifest.detail.problem'),
            $this->t('admin.readiness.assets_manifest.fix'));
    }

    private function checkThemePublished(): Check
    {
        $g = $this->t(self::G_ASSETS);
        $label = $this->t('admin.readiness.theme_published.label');
        $path = $this->projectDir.'/public/themes/default';
        if (is_link($path) || is_dir($path)) {
            return Check::ok($g, $label, $this->t('admin.readiness.theme_published.detail.ok'));
        }

        return Check::problem($g, $label,
            $this->t('admin.readiness.theme_published.detail.problem'),
            $this->t('admin.readiness.theme_published.fix'));
    }

    private function checkVarWritable(): Check
    {
        $g = $this->t(self::G_FS);
        $label = $this->t('admin.readiness.var_writable.label');
        $var = $this->projectDir.'/var';
        if (is_dir($var) && is_writable($var)) {
            return Check::ok($g, $label, $this->t('admin.readiness.var_writable.detail.ok'));
        }

        return Check::problem($g, $label,
            $this->t('admin.readiness.var_writable.detail.problem'),
            $this->t('admin.readiness.var_writable.fix', ['%path%' => $var]));
    }

    private function checkUploadsWritable(): Check
    {
        $g = $this->t(self::G_FS);
        $label = $this->t('admin.readiness.uploads.label');
        $dir = $this->projectDir.'/public/media/uploads';
        if (!is_dir($dir)) {
            return Check::warning($g, $label,
                $this->t('admin.readiness.uploads.detail.missing'),
                $this->t('admin.readiness.uploads.fix.missing', ['%path%' => $this->projectDir]));
        }
        if (is_writable($dir)) {
            return Check::ok($g, $label, $this->t('admin.readiness.uploads.detail.ok'));
        }

        return Check::problem($g, $label,
            $this->t('admin.readiness.uploads.detail.problem'),
            $this->t('admin.readiness.uploads.fix.problem', ['%path%' => $dir]));
    }

    private function checkMigrations(): Check
    {
        $g = $this->t(self::G_DB);
        $label = $this->t('admin.readiness.migrations.label');
        try {
            $pending = \count($this->migrations->getMigrationStatusCalculator()->getNewMigrations());
        } catch (\Throwable $e) {
            return Check::problem($g, $label,
                $this->t('admin.readiness.migrations.detail.error', ['%error%' => $e->getMessage()]),
                $this->t('admin.readiness.migrations.fix.error'));
        }

        if (0 === $pending) {
            return Check::ok($g, $label, $this->t('admin.readiness.migrations.detail.ok'));
        }

        return Check::problem($g, $label,
            $this->t('admin.readiness.migrations.detail.pending', ['%count%' => $pending]),
            $this->t('admin.readiness.migrations.fix.pending'));
    }

    private function checkWorker(): Check
    {
        $g = $this->t(self::G_WORKER);
        $label = $this->t('admin.readiness.worker.label');
        $lastSeen = $this->heartbeat->lastSeen();
        if (null !== $lastSeen && $this->heartbeat->isFresh()) {
            return Check::ok($g, $label,
                $this->t('admin.readiness.worker.detail.active', ['%seconds%' => max(0, time() - $lastSeen)]));
        }

        $detail = null === $lastSeen
            ? $this->t('admin.readiness.worker.detail.empty')
            : $this->t('admin.readiness.worker.detail.stale', ['%seconds%' => time() - $lastSeen]);

        return Check::manual($g, $label,
            $detail.$this->t('admin.readiness.worker.detail.suffix'),
            $this->t('admin.readiness.worker.fix'));
    }

    private function checkQueue(): Check
    {
        $g = $this->t(self::G_WORKER);
        $label = $this->t('admin.readiness.queue.label');
        try {
            $pending = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'default' AND delivered_at IS NULL"
            );
            $failed = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'"
            );
        } catch (\Throwable $e) {
            return Check::manual($g, $label,
                $this->t('admin.readiness.queue.detail.error', ['%error%' => $e->getMessage()]),
                $this->t('admin.readiness.queue.fix.error'));
        }

        if ($failed > 0) {
            return Check::warning($g, $label,
                $this->t('admin.readiness.queue.detail.failed', ['%failed%' => $failed, '%pending%' => $pending]),
                $this->t('admin.readiness.queue.fix.failed'));
        }
        if ($pending > 50) {
            return Check::warning($g, $label,
                $this->t('admin.readiness.queue.detail.backlog', ['%pending%' => $pending]),
                $this->t('admin.readiness.queue.fix.backlog'));
        }

        return Check::ok($g, $label,
            $this->t('admin.readiness.queue.detail.ok', ['%pending%' => $pending, '%failed%' => $failed]));
    }
}
