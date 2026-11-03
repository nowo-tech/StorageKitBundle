<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Controller;

use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StorageSync;
use Nowo\StorageKitBundle\Form\StorageProfileType;
use Nowo\StorageKitBundle\NowoStorageKitBundle;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Repository\StorageProfileRepository;
use Nowo\StorageKitBundle\Repository\StoredFileRepository;
use Nowo\StorageKitBundle\Scope\StorageScope;
use Nowo\StorageKitBundle\Security\AccessGuard;
use Nowo\StorageKitBundle\Sync\StorageMove;
use Nowo\StorageKitBundle\Ui\AdminPages;
use Nowo\StorageKitBundle\Ui\ProfileTester;
use Nowo\StorageKitBundle\Ui\StorageKitPages;
use Nowo\UiKitBundle\View\Action;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * *Move to another bucket* of an owner (instance or scope, same access as its Storage page): the target bucket (same
 * FormKit form, secret write-only), *Test connection*, the sync with its progress (Messenger worker or
 * `nowo:storage-kit:sync`), *Switch* (only after a complete, verified sync with no upload since), *Cancel* and
 * *Discard*. Every action is a POST with a CSRF token bound to the action and the owner
 * (`nowo_storage_kit/move/<action>/instance`, `…/scope/<key>`), audited, and redirects to the Move page (path only).
 */
final class StorageMoveController extends AbstractController
{
    /** Instance Move page. */
    public const ROUTE = 'nowo_storage_kit_move';

    /** Instance Move action. */
    public const ROUTE_ACTION = 'nowo_storage_kit_move_action';

    /** Scope Move page. */
    public const ROUTE_SCOPE = 'nowo_storage_kit_scope_move';

    /** Scope Move action. */
    public const ROUTE_SCOPE_ACTION = 'nowo_storage_kit_scope_move_action';

    /** The POST actions. */
    public const ACTIONS = 'test|sync|cancel|switch|discard';

    /**
     * Dependencies and configuration.
     */
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly StorageProfiles $resolver,
        private readonly StorageProfileRepository $profiles,
        private readonly StoredFileRepository $files,
        private readonly StorageMove $move,
        private readonly ProfileTester $tester,
        private readonly FormFactoryInterface $formFactory,
        private readonly CsrfOnlyFormFactory $csrfForms,
        private readonly AdminPages $adminPages,
        private readonly StorageKitPages $pages,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * Instance Move page.
     */
    #[Route(path: '/move', name: self::ROUTE, methods: ['GET', 'POST'])]
    public function instance(Request $request): Response
    {
        if (!$this->guard->isGranted()) {
            $this->guard->deny('Access denied to the storage settings.');
        }

        return $this->page($request, null);
    }

    /**
     * Instance Move action.
     */
    #[Route(path: '/move/{action}', name: self::ROUTE_ACTION, requirements: ['action' => self::ACTIONS], methods: ['POST'])]
    public function instanceAction(Request $request, string $action): RedirectResponse
    {
        if (!$this->guard->isGranted()) {
            $this->guard->deny('Access denied to the storage settings.');
        }

        return $this->act($request, $action, null);
    }

    /**
     * A scope's Move page.
     */
    #[Route(path: '/{scope}/move', name: self::ROUTE_SCOPE, requirements: ['scope' => StorageScope::KEY_PATTERN], methods: ['GET', 'POST'])]
    public function scope(Request $request, string $scope): Response
    {
        return $this->page($request, $this->scopeOrNotFound($scope));
    }

    /**
     * A scope's Move action.
     */
    #[Route(path: '/{scope}/move/{action}', name: self::ROUTE_SCOPE_ACTION, requirements: ['scope' => StorageScope::KEY_PATTERN, 'action' => self::ACTIONS], methods: ['POST'])]
    public function scopeAction(Request $request, string $scope, string $action): RedirectResponse
    {
        return $this->act($request, $action, $this->scopeOrNotFound($scope));
    }

    /**
     * CSRF token id of a Move action (`nowo_storage_kit/move/<action>/<owner>`; `target` is the form's).
     */
    public static function tokenId(string $action, ?string $scope): string
    {
        return 'nowo_storage_kit/move/' . $action . '/' . StorageScope::suffix($scope);
    }

    /**
     * Route name and parameters of an owner's Move page.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function route(?string $scope): array
    {
        return $scope === null ? [self::ROUTE, []] : [self::ROUTE_SCOPE, ['scope' => $scope]];
    }

    private function page(Request $request, ?string $scope): Response
    {
        $active  = $this->resolver->activeOf($scope);
        $target  = $this->move->target($scope);
        $sync    = $this->move->sync($active, $target);
        $running = $sync instanceof StorageSync && $sync->isOpen();
        $form    = $this->form($target, $scope, $running);
        $form->handleRequest($request);
        if (!$running && $form->isSubmitted() && $form->isValid()) {
            /** @var array{provider: string|null, endpoint: string|null, public_endpoint: string|null, region: string|null, bucket: string|null, prefix: string|null, access_key: string|null, secret: string|null, path_style: bool|null} $data */
            $data   = $form->getData();
            $before = $this->snapshot($target);
            $target->configure((string) $data['provider'], false, $data['endpoint'], $data['public_endpoint'], (string) $data['region'], (string) $data['bucket'], (string) $data['prefix'], (string) $data['access_key'], $data['secret'], $data['path_style'] === true);
            if ($target->isComplete()) {
                $fields = StorageSettingsController::changed($before, $this->snapshot($target));
                if ($data['secret'] !== null && $data['secret'] !== '') {
                    $fields[] = 'secret';
                }
                $this->move->saveTarget($target, $this->guard->user(), $fields);
                $this->addFlash('success', $this->trans('move.flash.saved'));

                return $this->redirectToRoute(...self::route($scope));
            }
            // Root errors are not part of the scaffold's child loop: attach it to the first missing field.
            $missing = $target->getBucket() === '' ? 'bucket' : ($target->getAccessKey() === '' ? 'access_key' : 'secret');
            // @igor-ignore - Request-scoped form built in this action.
            $form->get($missing)->addError(new FormError($this->trans('profile.error.incomplete')));
        }
        [$settingsRoute, $settingsParams] = StorageSettingsController::route($scope);
        $usage                            = $active instanceof StorageProfile && $active->getId() !== null ? $this->files->usageOf($active) : ['files' => 0, 'bytes' => 0];

        $response = $this->render($this->pages->template('move'), [
            'page'    => $this->adminPages->movePage($scope, $form->createView(), $running, $this->actions($scope, $active, $target, $sync), $this->generateUrl($settingsRoute, $settingsParams)),
            'aside'   => $this->adminPages->moveAside($active, $target, $sync, $usage, $this->profiles->retiredOf($scope), $this->move->runsInBackground()),
            'sync'    => $sync,
            'running' => $running,
            'scope'   => $scope,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * @return list<Action>
     */
    private function actions(?string $scope, ?StorageProfile $active, StorageProfile $target, ?StorageSync $sync): array
    {
        if ($target->getId() === null) {
            return [];
        }
        $open      = $sync instanceof StorageSync && $sync->isOpen();
        $completed = $sync instanceof StorageSync && $sync->isCompleted();
        $resume    = $sync instanceof StorageSync && !$completed;
        $actions   = [
            $this->action($scope, 'test', 'move.action.test', disabled: $open),
            $this->action($scope, 'sync', $resume ? 'move.action.resume' : ($completed ? 'move.action.resync' : 'move.action.sync'), 'primary', disabled: $open || !$active instanceof StorageProfile),
        ];
        if ($open) {
            $actions[] = $this->action($scope, 'cancel', 'move.action.cancel');
        }
        $actions[] = $this->action($scope, 'switch', 'move.action.switch', disabled: !$completed || $this->resolver->isEnvManaged($scope), confirm: 'move.confirm.switch');
        $actions[] = $this->action($scope, 'discard', 'move.action.discard', 'danger', $open, 'move.confirm.discard');

        return $actions;
    }

    private function action(?string $scope, string $action, string $label, string $variant = 'secondary', bool $disabled = false, ?string $confirm = null): Action
    {
        $url = $scope === null
            ? $this->generateUrl(self::ROUTE_ACTION, ['action' => $action])
            : $this->generateUrl(self::ROUTE_SCOPE_ACTION, ['scope' => $scope, 'action' => $action]);

        return AdminPages::postAction($label, $url, $this->csrfForms->createNamed($url, self::tokenId($action, $scope))->createView(), 'storage-kit-move-' . $action, $variant, $disabled, $confirm);
    }

    private function act(Request $request, string $action, ?string $scope): RedirectResponse
    {
        $form = $this->csrfForms->createNamed('', self::tokenId($action, $scope));
        $form->handleRequest($request);
        $active = $this->resolver->activeOf($scope);
        $target = $this->profiles->targetOf($scope);
        if (!$form->isSubmitted() || !$form->isValid() || !$target instanceof StorageProfile) {
            $this->addFlash('error', $this->trans('move.flash.unavailable'));

            return $this->redirectToRoute(...self::route($scope));
        }
        $sync             = $this->move->sync($active, $target);
        $actor            = $this->guard->user();
        [$type, $message] = match ($action) {
            'test'   => $this->tester->test($target, $actor),
            'sync'   => $this->startSync($active, $target, $sync, $actor),
            'cancel' => $this->cancelSync($sync, $actor),
            'switch' => $this->switch($active, $target, $actor),
            default  => $this->discard($target, $sync, $actor),
        };
        $this->addFlash($type, $message);

        return $this->redirectToRoute(...self::route($scope));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function startSync(?StorageProfile $active, StorageProfile $target, ?StorageSync $sync, ?object $actor): array
    {
        if (!$active instanceof StorageProfile || ($sync instanceof StorageSync && $sync->isOpen())) {
            return ['error', $this->trans('move.flash.unavailable')];
        }
        $started = $this->move->start($active, $target, $actor);

        return ['success', $this->trans($this->move->runsInBackground() ? 'move.flash.started' : 'move.flash.started_cli', ['%from%' => $active->getUuid(), '%to%' => $target->getUuid(), '%sync%' => $started->getUuid()])];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function cancelSync(?StorageSync $sync, ?object $actor): array
    {
        if (!$sync instanceof StorageSync || !$sync->isOpen()) {
            return ['error', $this->trans('move.flash.unavailable')];
        }
        $this->move->cancel($sync, $actor);

        return ['success', $this->trans('move.flash.cancelled')];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function switch(?StorageProfile $active, StorageProfile $target, ?object $actor): array
    {
        $result = $active instanceof StorageProfile ? $this->move->switch($active, $target, $actor) : StorageMove::NOT_SYNCED;

        return [$result === StorageMove::SWITCHED ? 'success' : 'error', $this->trans('move.flash.' . $result)];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function discard(StorageProfile $target, ?StorageSync $sync, ?object $actor): array
    {
        if ($sync instanceof StorageSync && $sync->isOpen()) {
            return ['error', $this->trans('move.flash.unavailable')];
        }
        $this->move->discard($target, $actor);

        return ['success', $this->trans('move.flash.discarded')];
    }

    /**
     * @return FormInterface<mixed>
     */
    private function form(StorageProfile $target, ?string $scope, bool $running): FormInterface
    {
        return $this->formFactory->createNamed('storage_target', StorageProfileType::class, [
            'provider'        => $target->getProvider(),
            'endpoint'        => $target->getEndpoint(),
            'public_endpoint' => $target->getPublicEndpoint(),
            'region'          => $target->getRegion(),
            'bucket'          => $target->getBucket(),
            'prefix'          => $target->getPrefix(),
            'access_key'      => $target->getAccessKey(),
            'path_style'      => $target->isPathStyle(),
        ], [
            'csrf_token_id' => self::tokenId('target', $scope),
            'read_only'     => $running,
            'has_secret'    => $target->hasSecret(),
            'restricted'    => $this->resolver->isRestricted($scope),
            'with_enabled'  => false,
        ]);
    }

    /**
     * @return array<string, bool|string|null>
     */
    private function snapshot(StorageProfile $profile): array
    {
        return [
            'provider'        => $profile->getProvider(),
            'endpoint'        => $profile->getEndpoint(),
            'public_endpoint' => $profile->getPublicEndpoint(),
            'region'          => $profile->getRegion(),
            'bucket'          => $profile->getBucket(),
            'prefix'          => $profile->getPrefix(),
            'access_key'      => $profile->getAccessKey(),
            'path_style'      => $profile->isPathStyle(),
        ];
    }

    private function scopeOrNotFound(string $scope): string
    {
        if (!$this->resolver->scopesEnabled() || !$this->guard->isScopeGranted($scope)) {
            throw new NotFoundHttpException();
        }

        return $scope;
    }

    /**
     * @param array<string, int|string> $params
     */
    private function trans(string $key, array $params = []): string
    {
        return $this->translator?->trans($key, $params, NowoStorageKitBundle::TRANSLATION_DOMAIN) ?? $key;
    }
}
