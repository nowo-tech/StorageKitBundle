<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Support;

use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\StorageKitBundle\Controller\StorageSettingsController;
use Nowo\StorageKitBundle\DependencyInjection\NowoStorageKitExtension;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StorageSync;
use Nowo\StorageKitBundle\Form\StorageProfileType;
use Nowo\StorageKitBundle\NowoStorageKitBundle;
use Nowo\StorageKitBundle\Profile\ConfigProfiles;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Repository\StorageProfileRepository;
use Nowo\StorageKitBundle\Scope\TargetScopeResolver;
use Nowo\StorageKitBundle\Security\EndpointGuard;
use Nowo\StorageKitBundle\Storage\StorageProvider;
use Nowo\StorageKitBundle\Ui\AdminPages;
use Nowo\StorageKitBundle\Ui\StorageKitPages;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Validator\Validation;

/**
 * Page contexts of the REQ-UI-003 contract test: view models from {@see AdminPages} (the service the controllers use)
 * and the FormView of the real FormKit form type.
 */
final class PageFixtures
{
    public static function factory(): FormFactoryInterface
    {
        $profile = NowoStorageKitExtension::formKitProfile();
        unset($profile['alias']);
        /** @var array<string, array{translation_domain: string, defaults: array{attr: array<string, mixed>, row_attr: array<string, mixed>}, field_types: array<string, array<string, mixed>>}> $profiles */
        $profiles = [NowoStorageKitBundle::FORM_KIT_PROFILE => $profile + ['by_form' => []]];
        $type     = new StorageProfileType(new EndpointGuard());
        $type->setFormOptionsMerger(new FormOptionsMerger($profiles, NowoStorageKitBundle::FORM_KIT_PROFILE, new ConstraintDefinitionFactory()));

        return Forms::createFormFactoryBuilder()
            ->addExtension(new PreloadedExtension([$type], []))
            ->addExtension(new HttpFoundationExtension())
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory();
    }

    public static function profiles(): StorageProfiles
    {
        $config = new ConfigProfiles(['main' => ['dsn' => 's3://k:s@aws/main', 'endpoint' => null, 'public_endpoint' => null, 'region' => 'us-east-1', 'bucket' => null, 'prefix' => '', 'access_key' => null, 'secret' => null, 'path_style' => false, 'delivery' => 'redirect']]);
        $em     = new StorageEntityManager(new NullRegistry());

        return new StorageProfiles(new StorageProfileRepository($em), $em, $config, new TargetScopeResolver());
    }

    public static function pages(): AdminPages
    {
        return new AdminPages(new StorageKitPages(), self::profiles());
    }

    public static function profile(?string $scope = null, bool $tested = true, ?string $state = null): StorageProfile
    {
        $profile = new StorageProfile($scope, $state ?? StorageProfile::STATE_ACTIVE);
        $profile->configure(StorageProvider::S3_COMPATIBLE, true, 'https://s3.example.com', null, 'eu-west-1', 'files', 'app', 'test-key', 'test-secret-not-real', true);
        if ($tested) {
            $profile->recordTest(true, false);
        }

        return $profile;
    }

    private static function form(string $name, bool $withEnabled, bool $readOnly = false): FormView
    {
        return self::factory()->createNamed($name, StorageProfileType::class, ['bucket' => 'files', 'region' => 'eu-west-1'], [
            'with_enabled'    => $withEnabled,
            'read_only'       => $readOnly,
            'has_secret'      => true,
            'csrf_protection' => false,
        ])->createView();
    }

    /**
     * @return array<string, mixed>
     */
    public static function settings(?string $scope = null, ?string $managed = null): array
    {
        $pages   = self::pages();
        $profile = match ($managed) {
            StorageSettingsController::MANAGED_CONFIG   => new StorageProfile(null, StorageProfile::STATE_ACTIVE, 'main'),
            StorageSettingsController::MANAGED_SETTINGS => self::settingsBacked(),
            default                                     => self::profile($scope),
        };
        $usage = ['files' => 3, 'bytes' => 4096];
        $test  = AdminPages::postAction('settings.action.test', '/admin/storage/test', self::factory()->createNamed('csrf_only', FormType::class)->createView(), 'storage-kit-test');

        return [
            'page'        => $pages->settingsPage($scope, $managed !== null ? null : self::form('storage_profile', true), $profile, $managed, $usage, [$test]),
            'aside'       => $pages->settingsAside($profile, $managed === StorageSettingsController::MANAGED_CONFIG, $usage),
            'scope'       => $scope,
            'managed'     => $managed,
            'env_managed' => $managed === StorageSettingsController::MANAGED_CONFIG,
        ];
    }

    private static function settingsBacked(): StorageProfile
    {
        $profile = new StorageProfile();
        $profile->useInstanceSettings();

        return $profile;
    }

    /**
     * @return array<string, mixed>
     */
    public static function move(bool $running = false): array
    {
        $pages  = self::pages();
        $active = self::profile();
        $target = self::profile(null, false, StorageProfile::STATE_TARGET);
        $sync   = null;
        if ($running) {
            $sync = new StorageSync($active, $target);
            $sync->start(4);
            $sync->advance(1, 3, 0);
        }
        $form = self::factory()->createNamed('csrf_only', FormType::class)->createView();

        return [
            'page'    => $pages->movePage(null, self::form('storage_target', false, $running), $running, [AdminPages::postAction('move.action.sync', '/admin/storage/move/sync', $form, 'storage-kit-move-sync', 'primary', $running)], '/admin/storage'),
            'aside'   => $pages->moveAside($active, $target, $sync, ['files' => 1, 'bytes' => 10], [$retired = self::profile(null, false, StorageProfile::STATE_RETIRED)], $running),
            'sync'    => $sync,
            'running' => $running,
            'scope'   => null,
        ];
    }
}
