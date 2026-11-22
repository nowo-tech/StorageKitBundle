<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Functional;

use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use Nowo\StorageKitBundle\Concurrency\RowVersion;
use Nowo\StorageKitBundle\Concurrency\StaleEditException;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Sync\StorageMove;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Form;

use function array_filter;

/**
 * 2.1 (REQ-RUNTIME-003 rule 4): the bucket forms of the Storage and Move pages carry the row version they were built
 * from. A save from a page that is no longer current writes nothing and answers a domain 409
 * (`X-Error-Code: STORAGE_KIT_STALE_EDIT`, no `X-Conflict-Code`) with the form and a translated message; a current
 * page saves and advances the version by one. SQLite and, in the CI database jobs, MySQL, MariaDB and PostgreSQL.
 */
final class StaleEditTest extends KernelTestCase
{
    private function storage(StorageKitTestKernel $kernel): StorageEntityManager
    {
        return $this->service($kernel, StorageEntityManager::class);
    }

    private function version(StorageKitTestKernel $kernel, StorageProfile $profile): int
    {
        return RowVersion::read($this->storage($kernel)->connection(), $this->storage($kernel)->table(StorageProfile::class), (int) $profile->getId());
    }

    private function bucket(StorageKitTestKernel $kernel, StorageProfile $profile): string
    {
        return (string) $this->storage($kernel)->connection()->fetchOne('SELECT bucket FROM storage_kit_profile WHERE id = ?', [$profile->getId()]);
    }

    private function settingsForm(KernelBrowser $client, string $bucket): Form
    {
        $form                            = $client->request('GET', '/storage/scopes/acme')->selectButton('Save')->form()->disableValidation();
        $form['storage_profile[bucket]'] = $bucket;

        return $form;
    }

    private function moveForm(KernelBrowser $client, string $bucket): Form
    {
        return $client->request('GET', '/admin/storage/move')->selectButton('Save the new bucket')->form([
            'storage_target[endpoint]'   => 'http://minio:9000',
            'storage_target[region]'     => 'us-east-1',
            'storage_target[bucket]'     => $bucket,
            'storage_target[prefix]'     => '',
            'storage_target[access_key]' => 'test-key',
            'storage_target[secret]'     => 'test-secret-not-real',
        ]);
    }

    private function assertStale(KernelBrowser $client, string $message): void
    {
        $response = $client->getResponse();
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(StaleEditException::ERROR_CODE, $response->headers->get('X-Error-Code'));
        self::assertFalse($response->headers->has('X-Conflict-Code'), 'a stale page is a domain answer, not a lock conflict');
        self::assertFalse($response->headers->has('Retry-After'));
        self::assertStringContainsString($message, (string) $response->getContent());
    }

    public function testTheStoragePageCarriesTheVersionAndRefusesAStalePage(): void
    {
        $kernel  = $this->bootKernel();
        $profile = $this->profile($kernel, 'acme');
        $mine    = $this->client($kernel, 'scopeadmin');
        $theirs  = $this->client($kernel, 'scopeadmin');

        // Both admins open the page at version 1.
        $mineForm   = $this->settingsForm($mine, 'mine');
        $theirsForm = $this->settingsForm($theirs, 'theirs');
        self::assertSame($profile->getId() . ':1', $mineForm->getValues()['storage_profile[rowVersion]'] ?? null);

        // The other admin saves first: the version advances by one.
        $theirs->submit($theirsForm);
        self::assertSame(302, $theirs->getResponse()->getStatusCode());
        self::assertSame(2, $this->version($kernel, $profile));

        // This page is stale: nothing is written, the form is shown again with the message.
        $mine->submit($mineForm);
        $this->assertStale($mine, 'Someone else saved this bucket after you opened the page.');
        self::assertSame('theirs', $this->bucket($kernel, $profile));
        self::assertSame(2, $this->version($kernel, $profile));
        self::assertCount(1, $mine->getCrawler()->filter('input[name="storage_profile[bucket]"]'));

        // Reloaded, the page saves.
        $mine->submit($this->settingsForm($mine, 'mine'));
        self::assertSame(302, $mine->getResponse()->getStatusCode());
        self::assertSame('mine', $this->bucket($kernel, $profile));
        self::assertSame(3, $this->version($kernel, $profile));
        self::assertCount(2, array_filter($this->audit($kernel)->actions(), static fn (string $action): bool => $action === StorageAuditEvent::PROFILE_SAVED));
    }

    public function testAMissingOrForgedVersionIsStale(): void
    {
        $kernel  = $this->bootKernel();
        $profile = $this->profile($kernel, 'acme');
        $client  = $this->client($kernel, 'scopeadmin');

        $id = (string) $profile->getId();
        foreach (['', 'abc', '-1', '1e3', '1', $id, $id . ':', ':1', $id . ':0', $id . ':01', $id . ':1:1', '0' . $id . ':1', $id . ':99999999999999999999', null] as $token) {
            $form   = $this->settingsForm($client, 'forged');
            $values = $form->getPhpValues();
            if ($token === null) {
                unset($values['storage_profile']['rowVersion']);
            } else {
                $values['storage_profile']['rowVersion'] = $token;
            }
            $client->request('POST', '/storage/scopes/acme', $values);
            $this->assertStale($client, 'Someone else saved this bucket after you opened the page.');
        }
        self::assertSame('files', $this->bucket($kernel, $profile));
        self::assertSame(1, $this->version($kernel, $profile));
    }

    public function testAFirstSaveHasNoVersionAndABucketCreatedMeanwhileMakesThePageStale(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel, 'scopeadmin');
        $form   = $this->settingsForm($client, 'mine');
        self::assertFalse($form->has('storage_profile[rowVersion]'));

        // Another admin saved the scope's first bucket meanwhile: this page never saw it.
        $theirs = $this->profile($kernel, 'acme', 'theirs');
        $client->submit($form);
        $this->assertStale($client, 'Someone else saved this bucket after you opened the page.');
        self::assertSame('theirs', $this->bucket($kernel, $theirs));
    }

    public function testTheMovePageRefusesAStaleTarget(): void
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel);
        $target = $this->profile($kernel, null, 'target-files', StorageProfile::STATE_TARGET);
        $mine   = $this->client($kernel);
        $theirs = $this->client($kernel);

        $mineForm = $this->moveForm($mine, 'mine');
        self::assertSame($target->getId() . ':1', $mineForm->getValues()['storage_target[rowVersion]'] ?? null);
        $theirs->submit($this->moveForm($theirs, 'theirs'));
        self::assertSame(302, $theirs->getResponse()->getStatusCode());

        $mine->submit($mineForm);
        $this->assertStale($mine, 'Someone else saved the new bucket after you opened the page.');
        self::assertSame('theirs', $this->bucket($kernel, $target));
        self::assertSame(2, $this->version($kernel, $target));

        $mine->submit($this->moveForm($mine, 'mine'));
        self::assertSame(302, $mine->getResponse()->getStatusCode());
        self::assertSame('mine', $this->bucket($kernel, $target));
    }

    /**
     * Audit ZK-M1 (a): a Move page opened on target T1; another admin discards T1 and saves a new target T2, which
     * starts at the same version (1). The old page names T1, so its save is stale and T2 is unchanged.
     */
    public function testAMovePageOfADiscardedTargetIsStaleForTheNewTarget(): void
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel);
        $first = $this->profile($kernel, null, 'first-target', StorageProfile::STATE_TARGET);
        $mine  = $this->client($kernel);
        $form  = $this->moveForm($mine, 'mine');

        $move = $this->service($kernel, StorageMove::class);
        self::assertTrue($move->discard($first, null));
        $second = $this->profile($kernel, null, 'theirs', StorageProfile::STATE_TARGET);
        self::assertSame(1, $this->version($kernel, $second), 'the same version as the page');

        $mine->submit($form);
        $this->assertStale($mine, 'Someone else saved the new bucket after you opened the page.');
        self::assertSame('theirs', $this->bucket($kernel, $second));
        self::assertSame(1, $this->version($kernel, $second));
    }

    /**
     * Audit ZK-M1 (b): a Storage page opened on the active bucket P; a switch makes another row active, at the same
     * version. The old page names P, so its save is stale and the new active bucket is unchanged.
     */
    public function testAStoragePageOfTheBucketBeforeASwitchIsStaleForTheNewActiveBucket(): void
    {
        $kernel = $this->bootKernel();
        $before = $this->profile($kernel, 'acme', 'before');
        $mine   = $this->client($kernel, 'scopeadmin');
        $form   = $this->settingsForm($mine, 'mine');

        // The switch: the former bucket retires and another row becomes the active one (version 1, like the page).
        $this->storage($kernel)->connection()->executeStatement('UPDATE storage_kit_profile SET state = ? WHERE id = ?', [StorageProfile::STATE_RETIRED, $before->getId()]);
        $after = $this->profile($kernel, 'acme', 'after');
        self::assertSame($this->version($kernel, $before), $this->version($kernel, $after), 'equal versions');

        $mine->submit($form);
        $this->assertStale($mine, 'Someone else saved this bucket after you opened the page.');
        self::assertSame('after', $this->bucket($kernel, $after));
        self::assertSame('before', $this->bucket($kernel, $before));
        self::assertSame(1, $this->version($kernel, $after));
    }

    /**
     * Audit ZK-M1 (c): a token that names another row (with that row's current version) is stale.
     */
    public function testATokenOfAnotherRowIsStale(): void
    {
        $kernel  = $this->bootKernel();
        $profile = $this->profile($kernel, 'acme');
        $other   = $this->profile($kernel, 'other', 'other-files');
        $client  = $this->client($kernel, 'scopeadmin');

        $values                                  = $this->settingsForm($client, 'forged')->getPhpValues();
        $values['storage_profile']['rowVersion'] = $other->getId() . ':' . $this->version($kernel, $other);
        $client->request('POST', '/storage/scopes/acme', $values);

        $this->assertStale($client, 'Someone else saved this bucket after you opened the page.');
        self::assertSame('files', $this->bucket($kernel, $profile));
        self::assertSame(1, $this->version($kernel, $profile));
        self::assertSame('other-files', $this->bucket($kernel, $other));
    }

    public function testASaveOfYourCodeAdvancesTheVersionUnchecked(): void
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel);
        $target = $this->profile($kernel, null, 'target-files', StorageProfile::STATE_TARGET);
        $client = $this->client($kernel);
        $form   = $this->moveForm($client, 'mine');

        // Your code saves the target without a version: pages opened before are stale.
        $move = $this->service($kernel, StorageMove::class);
        self::assertTrue($move->saveTarget($target, null, ['bucket'], static function (StorageProfile $profile): void {
            $profile->configure($profile->getProvider(), false, $profile->getEndpoint(), null, $profile->getRegion(), 'from-code', '', $profile->getAccessKey(), null, true);
        }));
        self::assertSame(2, $move->rowVersion($target));
        $client->submit($form);
        $this->assertStale($client, 'Someone else saved the new bucket after you opened the page.');
        self::assertSame('from-code', $this->bucket($kernel, $target));

        // A wrong version from your code throws and writes nothing.
        try {
            $move->saveTarget($target, null, ['bucket'], null, 1);
            self::fail('A stale version was saved.');
        } catch (StaleEditException $e) {
            self::assertSame((int) $target->getId(), $e->id);
            self::assertSame(1, $e->expected);
        }
        self::assertSame(2, $move->rowVersion($target));
        self::assertNull($move->rowVersion(new StorageProfile()));
    }
}
