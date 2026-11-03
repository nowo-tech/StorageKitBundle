<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\StorageKitBundle\NowoStorageKitBundle;
use Nowo\StorageKitBundle\Security\EndpointGuard;
use Nowo\StorageKitBundle\Storage\S3Settings;
use Nowo\StorageKitBundle\Storage\StorageProvider;
use Nowo\UiKitBundle\Form\VisibleWhen;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function assert;
use function is_array;
use function trim;

/**
 * A bucket connection (REQ-TWIG-005 rule 11: FormKit type, profile `storage_kit`) — a scope's Storage page (with the
 * `enabled` switch) and the Move page's target (`with_enabled: false`). The provider choice decides which fields are
 * shown ({@see StorageProvider}): the endpoint, public endpoint and path-style rows carry UiKit `visible-when`
 * attributes and are hidden for Amazon S3; on submit the submitted provider decides, and hidden rows keep their
 * stored values (never validated, never cleared). The secret is write-only (`always_empty`; blank keeps the saved
 * one). For Amazon S3 the access key and secret can also come from the AWS console's access-key CSV (unmapped
 * `access_key_csv` file row, {@see AwsCsvImport}: parsed on submit, never stored; a typed value that differs is a
 * conflict). Turning uploads on needs a bucket, an access key, a secret (typed or saved: `has_secret`) and, for an
 * S3-compatible provider, an endpoint. `read_only` greys everything out (environment-managed instance profile). SEC-STORAGE-SSRF: every
 * URL-building field is validated with the same rules as {@see S3Settings} (endpoints are bare http(s) origins, the
 * region a plain token), and `restricted` profiles also refuse server-side endpoints the {@see EndpointGuard}
 * refuses (private, loopback, link-local, metadata, unresolvable). The CSRF token id comes from the controller
 * (`nowo_storage_kit/settings/<owner>`, `nowo_storage_kit/move/target/<owner>`).
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig(NowoStorageKitBundle::FORM_KIT_PROFILE)]
final class StorageProfileType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * The SSRF guard checks the server-side endpoint of restricted profiles.
     */
    public function __construct(
        private readonly EndpointGuard $guard,
    ) {
    }

    /**
     * Provider choice, enabled switch, bucket fields (endpoint fields only for an S3-compatible provider), write-only
     * secret.
     *
     * @param FormBuilderInterface<mixed> $builder form builder
     * @param array<string, mixed> $options resolved options (read_only, has_secret, restricted, with_enabled)
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $readOnly     = $options['read_only'] === true;
        $hasSecret    = $options['has_secret'] === true;
        $restricted   = $options['restricted'] === true;
        $controller   = $builder->getName() . '[provider]';
        $endpointOnly = VisibleWhen::attributes($controller, [StorageProvider::S3_COMPATIBLE]);

        $this->addWithDefaults($builder, 'provider', ChoiceType::class, [
            'label'       => 'profile.field.provider',
            'help'        => 'profile.help.provider',
            'choices'     => StorageProvider::choices(),
            'disabled'    => $readOnly,
            'constraints' => [new NotBlank(message: 'profile.error.provider'), new Choice(choices: StorageProvider::ALL, message: 'profile.error.provider')],
        ]);
        if ($options['with_enabled'] === true) {
            $this->addWithDefaults($builder, 'enabled', CheckboxType::class, [
                'label'       => 'profile.field.enabled',
                'help'        => 'profile.help.enabled',
                'required'    => false,
                'disabled'    => $readOnly,
                'constraints' => [new Callback(static function (mixed $enabled, ExecutionContextInterface $context) use ($hasSecret): void {
                    if ($enabled !== true) {
                        return;
                    }
                    $form = $context->getRoot();
                    assert($form instanceof FormInterface);
                    $value           = static fn (string $name): string => trim((string) $form->get($name)->getData());
                    $endpointMissing = $value('provider') === StorageProvider::S3_COMPATIBLE && $value('endpoint') === '';
                    if ($endpointMissing || $value('bucket') === '' || $value('access_key') === '' || (!$hasSecret && $value('secret') === '')) {
                        $context->buildViolation('profile.error.incomplete')->setTranslationDomain(NowoStorageKitBundle::TRANSLATION_DOMAIN)->addViolation();
                    }
                })],
            ]);
        }
        $this->addWithDefaults($builder, 'endpoint', TextType::class, [
            'label'       => 'profile.field.endpoint',
            'help'        => $restricted ? 'profile.help.endpoint_restricted' : 'profile.help.endpoint',
            'required'    => false,
            'disabled'    => $readOnly,
            'constraints' => ProfileConstraints::endpoint($this->guard, $restricted),
            'attr'        => ['autocomplete' => 'off', 'spellcheck' => 'false', 'placeholder' => 'profile.placeholder.endpoint'],
            'row_attr'    => $endpointOnly,
        ]);
        $this->addWithDefaults($builder, 'public_endpoint', TextType::class, [
            'label'       => 'profile.field.public_endpoint',
            'help'        => 'profile.help.public_endpoint',
            'required'    => false,
            'disabled'    => $readOnly,
            'constraints' => ProfileConstraints::endpoint(null, false),
            'attr'        => ['autocomplete' => 'off', 'spellcheck' => 'false'],
            'row_attr'    => $endpointOnly,
        ]);
        $this->addWithDefaults($builder, 'region', TextType::class, [
            'label'       => 'profile.field.region',
            'required'    => false,
            'disabled'    => $readOnly,
            'constraints' => ProfileConstraints::region(),
            'attr'        => ['autocomplete' => 'off', 'spellcheck' => 'false', 'placeholder' => 'profile.placeholder.region'],
        ]);
        $this->addWithDefaults($builder, 'bucket', TextType::class, [
            'label'       => 'profile.field.bucket',
            'required'    => false,
            'disabled'    => $readOnly,
            'constraints' => ProfileConstraints::bucket(),
            'attr'        => ['autocomplete' => 'off', 'spellcheck' => 'false'],
        ]);
        $this->addWithDefaults($builder, 'prefix', TextType::class, [
            'label'       => 'profile.field.prefix',
            'help'        => 'profile.help.prefix',
            'required'    => false,
            'disabled'    => $readOnly,
            'constraints' => ProfileConstraints::prefix(),
            'attr'        => ['autocomplete' => 'off', 'spellcheck' => 'false'],
        ]);
        // Optional AWS console CSV (Amazon S3 only): fills the access key and the secret on submit.
        AwsCsvImport::add($builder, ['secret'], $readOnly, function (string $name, array $fieldOptions) use ($builder): void {
            $this->addWithDefaults($builder, $name, FileType::class, $fieldOptions);
        });
        $this->addWithDefaults($builder, 'access_key', TextType::class, [
            'label'       => 'profile.field.access_key',
            'required'    => false,
            'disabled'    => $readOnly,
            'constraints' => ProfileConstraints::accessKey(),
            'attr'        => ['autocomplete' => 'off', 'spellcheck' => 'false'],
        ]);
        $this->addWithDefaults($builder, 'secret', PasswordType::class, [
            'label'        => 'profile.field.secret',
            'help'         => $hasSecret ? 'profile.help.secret_kept' : 'profile.help.secret',
            'required'     => false,
            'disabled'     => $readOnly,
            'always_empty' => true,
            'constraints'  => ProfileConstraints::secret(),
            'attr'         => ['autocomplete' => 'new-password'],
        ]);
        $this->addWithDefaults($builder, 'path_style', CheckboxType::class, [
            'label'    => 'profile.field.path_style',
            'help'     => 'profile.help.path_style',
            'required' => false,
            'disabled' => $readOnly,
            'row_attr' => $endpointOnly,
        ]);
        // The submitted provider decides which fields count (InstanceSettings 1.3 rules): the endpoint fields hidden
        // for Amazon S3 get their current values back, so they are neither validated nor changed.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();
            if (!is_array($data) || ($data['provider'] ?? null) !== StorageProvider::AWS_S3) {
                return;
            }
            $form = $event->getForm();
            foreach (StorageProvider::ENDPOINT_FIELDS as $name) {
                $view = $form->get($name)->getViewData();
                if ($view === null) {
                    unset($data[$name]);
                } else {
                    $data[$name] = $view;
                }
            }
            $event->setData($data);
        });
    }

    /**
     * POST with CSRF (token id from the controller), bundle translation domain; options read_only, has_secret,
     * restricted, with_enabled.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_storage_kit/settings/instance',
            'translation_domain' => NowoStorageKitBundle::TRANSLATION_DOMAIN,
            'read_only'          => false,
            'has_secret'         => false,
            'restricted'         => true,
            'with_enabled'       => true,
        ]);
        $resolver->setAllowedTypes('read_only', 'bool');
        $resolver->setAllowedTypes('has_secret', 'bool');
        $resolver->setAllowedTypes('restricted', 'bool');
        $resolver->setAllowedTypes('with_enabled', 'bool');
    }

    /**
     * Block prefix `storage_profile`.
     */
    public function getBlockPrefix(): string
    {
        return 'storage_profile';
    }

    /*
     * A non-empty value must (`$match`) or must not match `$regex`; the message is a bundle-domain key.
     */
}
