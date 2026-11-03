<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Controller;

use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\StorageKitBundle\Audit\AuditRecorder;
use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Form\StorageProfileType;
use Nowo\StorageKitBundle\NowoStorageKitBundle;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Repository\StorageProfileRepository;
use Nowo\StorageKitBundle\Repository\StoredFileRepository;
use Nowo\StorageKitBundle\Scope\StorageScope;
use Nowo\StorageKitBundle\Security\AccessGuard;
use Nowo\StorageKitBundle\Settings\StorageSettingsSection;
use Nowo\StorageKitBundle\Ui\AdminPages;
use Nowo\StorageKitBundle\Ui\ProfileTester;
use Nowo\StorageKitBundle\Ui\StorageKitPages;
use Nowo\UiKitBundle\View\Action;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_filter;
use function array_keys;
use function array_values;
use function implode;
use function in_array;
use function trim;

/**
 * The Storage page of an owner — the instance (`web_ui.path_prefix`, {@see AccessGuard::isGranted()}; also checked
 * by the access listener) or a scope (`scopes.path_prefix/{scope}`, {@see AccessGuard::isScopeGranted()}: 404 for
 * anyone else). The bucket form (FormKit, secret write-only and encrypted at rest), *Test connection* (POST,
 * CSRF-only form) and the usage. An environment-managed instance profile is read-only. Saving audits the names of the
 * changed fields only; every redirect is to a bundle route (path only). Routes come only from the `nowo_storage_kit`
 * loader (REQ-SF-004 rule 7).
 */
final class StorageSettingsController extends AbstractController
{
    /** Instance page. */
    public const ROUTE = 'nowo_storage_kit_settings';

    /** Instance *Test connection*. */
    public const ROUTE_TEST = 'nowo_storage_kit_settings_test';

    /** Scope page. */
    public const ROUTE_SCOPE = 'nowo_storage_kit_scope_settings';

    /** Scope *Test connection*. */
    public const ROUTE_SCOPE_TEST = 'nowo_storage_kit_scope_settings_test';

    /** Instance Settings section page route (`nowo_instance_settings_section`). */
    public const SETTINGS_SECTION_ROUTE = InstanceSettingsRoutes::SECTION;

    /** Fields compared to name the changed ones in the audit event. */
    /** `from` query value of the instance test posted from the Instance Settings section (redirects back there). */
    public const FROM_SETTINGS = 'settings';

    /** Instance page notice: the bucket comes from the configuration. */
    public const MANAGED_CONFIG = 'config';

    /** Instance page notice: the bucket is edited in the `storage` Instance Settings section. */
    public const MANAGED_SETTINGS = 'settings';

    private const FIELDS = ['provider', 'enabled', 'endpoint', 'public_endpoint', 'region', 'bucket', 'prefix', 'access_key', 'path_style'];

    /**
     * Dependencies and configuration.
     */
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly StorageProfiles $resolver,
        private readonly StorageProfileRepository $profiles,
        private readonly StoredFileRepository $files,
        private readonly ProfileTester $tester,
        private readonly FormFactoryInterface $formFactory,
        private readonly StorageEntityManager $entityManager,
        private readonly AuditRecorder $audit,
        private readonly CsrfOnlyFormFactory $csrfForms,
        private readonly AdminPages $adminPages,
        private readonly StorageKitPages $pages,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * Instance Storage page.
     */
    #[Route(path: '', name: self::ROUTE, methods: ['GET', 'POST'])]
    public function instance(Request $request): Response
    {
        if (!$this->guard->isGranted()) {
            $this->guard->deny('Access denied to the storage settings.');
        }

        return $this->edit($request, null);
    }

    /**
     * Instance *Test connection*.
     */
    #[Route(path: '/test', name: self::ROUTE_TEST, methods: ['POST'])]
    public function testInstance(Request $request): RedirectResponse
    {
        if (!$this->guard->isGranted()) {
            $this->guard->deny('Access denied to the storage settings.');
        }

        return $this->test($request, null);
    }

    /**
     * A scope's Storage page.
     */
    #[Route(path: '/{scope}', name: self::ROUTE_SCOPE, requirements: ['scope' => StorageScope::KEY_PATTERN], methods: ['GET', 'POST'])]
    public function scope(Request $request, string $scope): Response
    {
        return $this->edit($request, $this->scopeOrNotFound($scope));
    }

    /**
     * A scope's *Test connection*.
     */
    #[Route(path: '/{scope}/test', name: self::ROUTE_SCOPE_TEST, requirements: ['scope' => StorageScope::KEY_PATTERN], methods: ['POST'])]
    public function testScope(Request $request, string $scope): RedirectResponse
    {
        return $this->test($request, $this->scopeOrNotFound($scope));
    }

    private function edit(Request $request, ?string $scope): Response
    {
        $managed = match (true) {
            $this->resolver->isEnvManaged($scope)                => self::MANAGED_CONFIG,
            $scope === null && $this->resolver->settingsBacked() => self::MANAGED_SETTINGS,
            default                                              => null,
        };
        $profile = $managed !== null ? $this->resolver->activeOf(null) : $this->profiles->activeOf($scope);
        $profile ??= new StorageProfile($scope);
        $form = $managed !== null ? null : $this->form($profile, $scope);
        if ($form instanceof FormInterface) {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $this->save($form, $profile, $scope);
                $this->addFlash('success', $this->trans('settings.flash.saved'));

                return $this->redirectToRoute(...self::route($scope));
            }
        }
        $usage   = $profile->getId() === null ? ['files' => 0, 'bytes' => 0] : $this->files->usageOf($profile);
        $actions = [];
        if ($profile->getId() !== null) {
            [$testRoute, $testParams] = $scope === null ? [self::ROUTE_TEST, []] : [self::ROUTE_SCOPE_TEST, ['scope' => $scope]];
            $testUrl                  = $this->generateUrl($testRoute, $testParams);
            $actions[]                = AdminPages::postAction('settings.action.test', $testUrl, $this->csrfForms->createNamed($testUrl, self::testTokenId($scope))->createView(), 'storage-kit-test');
        }
        if ($managed === self::MANAGED_SETTINGS) {
            try {
                $actions[] = new Action('custom', StorageKitPages::label('settings.action.edit'), href: $this->generateUrl(self::SETTINGS_SECTION_ROUTE, ['section' => StorageSettingsSection::NAME]), variant: 'primary', attributes: ['data-testid' => 'storage-kit-edit-link']);
            } catch (RouteNotFoundException) {
                // The host did not import the Instance Settings routes: the section is edited elsewhere.
            }
        }
        [$moveRoute, $moveParams] = StorageMoveController::route($scope);
        $actions[]                = new Action('custom', StorageKitPages::label('settings.action.move'), href: $this->generateUrl($moveRoute, $moveParams), variant: 'secondary', attributes: ['data-testid' => 'storage-kit-move-link']);

        $response = $this->render($this->pages->template('settings'), [
            'page'        => $this->adminPages->settingsPage($scope, $form?->createView(), $profile, $managed, $usage, $actions),
            'aside'       => $this->adminPages->settingsAside($profile, $managed === self::MANAGED_CONFIG, $usage),
            'scope'       => $scope,
            'managed'     => $managed,
            'env_managed' => $managed === self::MANAGED_CONFIG,
        ], new Response(status: $form?->isSubmitted() === true ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));

        return $this->noStore($response);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function save(FormInterface $form, StorageProfile $profile, ?string $scope): void
    {
        /** @var array{provider: string|null, enabled: bool|null, endpoint: string|null, public_endpoint: string|null, region: string|null, bucket: string|null, prefix: string|null, access_key: string|null, secret: string|null, path_style: bool|null} $data */
        $data   = $form->getData();
        $before = $this->snapshot($profile);
        $profile->configure(
            (string) $data['provider'],
            $data['enabled'] === true,
            $data['endpoint'],
            $data['public_endpoint'],
            (string) $data['region'],
            (string) $data['bucket'],
            (string) $data['prefix'],
            (string) $data['access_key'],
            $data['secret'],
            $data['path_style'] === true,
        );
        $changed = self::changed($before, $this->snapshot($profile));
        if ($data['secret'] !== null && trim($data['secret']) !== '') {
            $changed[] = 'secret';
        }
        $manager = $this->entityManager->manager();
        $manager->persist($profile);
        $manager->flush();
        $this->resolver->reset();
        $this->audit->record(StorageAuditEvent::PROFILE_SAVED, $scope, $this->guard->user(), ['profile' => $profile->getUuid(), 'fields' => implode(',', $changed)]);
    }

    private function test(Request $request, ?string $scope): RedirectResponse
    {
        $form = $this->csrfForms->createNamed('', self::testTokenId($scope));
        $form->handleRequest($request);
        $profile = $scope === null ? $this->resolver->activeOf(null) : $this->profiles->activeOf($scope);
        // Only the literal value: the redirect target is a fixed route, never taken from the request (no open redirect).
        $back = $scope === null && $this->resolver->settingsBacked() && $request->query->get('from') === self::FROM_SETTINGS
            ? [self::SETTINGS_SECTION_ROUTE, ['section' => StorageSettingsSection::NAME]]
            : self::route($scope);
        if (!$profile instanceof StorageProfile || !$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', $this->trans($scope === null && $this->resolver->settingsBacked() ? 'test.flash.unavailable_settings' : 'test.flash.unavailable'));

            return $this->redirectToRoute(...$back);
        }
        [$type, $message] = $this->tester->test($profile, $this->guard->user());
        $this->addFlash($type, $message);

        return $this->redirectToRoute(...$back);
    }

    /**
     * CSRF token id of *Test connection* (`nowo_storage_kit/test/instance`, `nowo_storage_kit/test/scope/<key>`).
     */
    public static function testTokenId(?string $scope): string
    {
        return 'nowo_storage_kit/test/' . StorageScope::suffix($scope);
    }

    /**
     * CSRF token id of the bucket form.
     */
    public static function formTokenId(?string $scope): string
    {
        return 'nowo_storage_kit/settings/' . StorageScope::suffix($scope);
    }

    /**
     * Route name and parameters of an owner's Storage page.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function route(?string $scope): array
    {
        return $scope === null ? [self::ROUTE, []] : [self::ROUTE_SCOPE, ['scope' => $scope]];
    }

    /**
     * @return FormInterface<mixed>
     */
    private function form(StorageProfile $profile, ?string $scope): FormInterface
    {
        return $this->formFactory->createNamed('storage_profile', StorageProfileType::class, [
            'provider'        => $profile->getProvider(),
            'enabled'         => $profile->isEnabled(),
            'endpoint'        => $profile->getEndpoint(),
            'public_endpoint' => $profile->getPublicEndpoint(),
            'region'          => $profile->getRegion(),
            'bucket'          => $profile->getBucket(),
            'prefix'          => $profile->getPrefix(),
            'access_key'      => $profile->getAccessKey(),
            'path_style'      => $profile->isPathStyle(),
        ], [
            'csrf_token_id' => self::formTokenId($scope),
            'has_secret'    => $profile->hasSecret(),
            'restricted'    => $this->resolver->isRestricted($scope),
        ]);
    }

    private function scopeOrNotFound(string $scope): string
    {
        if (!$this->resolver->scopesEnabled() || !$this->guard->isScopeGranted($scope)) {
            throw new NotFoundHttpException();
        }

        return $scope;
    }

    /**
     * @return array<string, bool|string|null>
     */
    private function snapshot(StorageProfile $profile): array
    {
        return [
            'provider'        => $profile->getProvider(),
            'enabled'         => $profile->isEnabled(),
            'endpoint'        => $profile->getEndpoint(),
            'public_endpoint' => $profile->getPublicEndpoint(),
            'region'          => $profile->getRegion(),
            'bucket'          => $profile->getBucket(),
            'prefix'          => $profile->getPrefix(),
            'access_key'      => $profile->getAccessKey(),
            'path_style'      => $profile->isPathStyle(),
        ];
    }

    /**
     * Names of the fields that differ between two snapshots.
     *
     * @param array<string, bool|string|null> $before
     * @param array<string, bool|string|null> $after
     *
     * @return list<string>
     */
    public static function changed(array $before, array $after): array
    {
        return array_values(array_filter(array_keys($after), static fn (string $name): bool => in_array($name, self::FIELDS, true) && ($before[$name] ?? null) !== $after[$name]));
    }

    private function noStore(Response $response): Response
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * @param array<string, string> $params
     */
    private function trans(string $key, array $params = []): string
    {
        return $this->translator?->trans($key, $params, NowoStorageKitBundle::TRANSLATION_DOMAIN) ?? $key;
    }
}
