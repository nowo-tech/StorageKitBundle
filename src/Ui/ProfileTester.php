<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Ui;

use Nowo\StorageKitBundle\Audit\AuditRecorder;
use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\NowoStorageKitBundle;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Storage\S3Settings;
use Nowo\StorageKitBundle\Storage\StorageProbe;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * *Test connection* of the Storage and Move pages: runs the probe on a profile, records and audits the outcome and
 * returns the flash to show — a generic reason and the HTTP status class, never the storage's own message
 * (SEC-STORAGE-SSRF: no response oracle).
 */
final readonly class ProfileTester
{
    /**
     * Dependencies and configuration.
     */
    public function __construct(
        private StorageProfiles $profiles,
        private StorageProbe $probe,
        private StorageEntityManager $entityManager,
        private AuditRecorder $audit,
        private ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * Tests `$profile`.
     *
     * @return array{0: 'error'|'success'|'warning', 1: string} flash type and translated message
     */
    public function test(StorageProfile $profile, ?object $actor): array
    {
        $settings = $this->profiles->settingsOf($profile);
        if (!$settings instanceof S3Settings) {
            return ['error', $this->trans('test.flash.unusable')];
        }
        $result = $this->probe->run($settings);
        $profile->recordTest($result->ok(), $result->publicRead);
        $this->entityManager->manager()->flush();
        $this->audit->record(StorageAuditEvent::PROFILE_TESTED, $profile->getScope(), $actor, [
            'profile' => $profile->getUuid(),
            'ok'      => $result->ok(),
            'step'    => $result->failedStep,
            'reason'  => $result->reason === '' ? null : $result->reason,
        ]);
        if (!$result->ok()) {
            return ['error', $this->trans('test.flash.failed', [
                '%step%'  => $this->trans('probe.step.' . $result->failedStep),
                '%error%' => $this->trans('probe.reason.' . $result->reason, ['%status%' => $result->statusClass() ?? '']),
            ])];
        }

        return $result->publicRead ? ['warning', $this->trans('test.flash.public')] : ['success', $this->trans('test.flash.ok')];
    }

    /**
     * @param array<string, string> $params
     */
    private function trans(string $key, array $params = []): string
    {
        return $this->translator?->trans($key, $params, NowoStorageKitBundle::TRANSLATION_DOMAIN) ?? $key;
    }
}
