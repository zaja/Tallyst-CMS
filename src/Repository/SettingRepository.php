<?php

namespace App\Repository;

use App\Entity\Setting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Setting>
 */
class SettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Setting::class);
    }

    public function get(string $name, ?string $default = null): ?string
    {
        $setting = $this->findOneBy(['name' => $name]);

        return $setting?->getValue() ?? $default;
    }

    public function set(string $name, ?string $value): Setting
    {
        $setting = $this->findOneBy(['name' => $name]) ?? new Setting($name);
        $setting->setValue($value);

        $em = $this->getEntityManager();
        $em->persist($setting);
        $em->flush();

        return $setting;
    }
}
