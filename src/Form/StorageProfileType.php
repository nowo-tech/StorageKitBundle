<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Form;

use InvalidArgumentException;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\StorageKitBundle\NowoStorageKitBundle;
use Nowo\StorageKitBundle\Security\EndpointGuard;
use Nowo\StorageKitBundle\Storage\S3Settings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function assert;
use function is_string;
use function preg_match;
use function trim;

/**
 * An S3-compatible bucket (REQ-TWIG-005 rule 11: FormKit type, profile `storage_kit`) — the Storage page (with the
 * `enabled` switch) and the Move page's target (`with_enabled: false`). The secret is write-only (`always_empty`;
 * blank keeps the saved one). Turning uploads on needs a bucket, an access key and a secret (typed or saved:
 * `has_secret`). `read_only` greys everything out (environment-managed instance profile). SEC-STORAGE-SSRF: every
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
     * Fields: enabled (optional), endpoint, public_endpoint, region, bucket, prefix, access_key, secret, path_style.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $readOnly   = $options['read_only'] === true;
        $hasSecret  = $options['has_secret'] === true;
        $restricted = $options['restricted'] === true;
        $guard      = $this->guard;

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
                    $value = static fn (string $name): string => trim((string) $form->get($name)->getData());
                    if ($value('bucket') === '' || $value('access_key') === '' || (!$hasSecret && $value('secret') === '')) {
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
            'constraints' => [new Length(max: 255), $this->endpointConstraint($guard, $restricted)],
            'attr'        => ['autocomplete' => 'off', 'spellcheck' => 'false', 'placeholder' => 'profile.placeholder.endpoint'],
        ]);
        $this->addWithDefaults($builder, 'public_endpoint', TextType::class, [
            'label'       => 'profile.field.public_endpoint',
            'help'        => 'profile.help.public_endpoint',
            'required'    => false,
            'disabled'    => $readOnly,
            'constraints' => [new Length(max: 255), $this->endpointConstraint(null, false)],
            'attr'        => ['autocomplete' => 'off', 'spellcheck' => 'false'],
        ]);
        $this->addWithDefaults($builder, 'region', TextType::class, [
            'label'       => 'profile.field.region',
            'required'    => false,
            'disabled'    => $readOnly,
            'constraints' => [new Length(max: S3Settings::REGION_MAX), $this->pattern(S3Settings::REGION_PATTERN, 'profile.error.region')],
            'attr'        => ['autocomplete' => 'off', 'spellcheck' => 'false', 'placeholder' => 'profile.placeholder.region'],
        ]);
        $this->addWithDefaults($builder, 'bucket', TextType::class, [
            'label'       => 'profile.field.bucket',
            'required'    => false,
            'disabled'    => $readOnly,
            'constraints' => [$this->pattern(S3Settings::BUCKET_PATTERN, 'profile.error.bucket'), $this->pattern('/\.\./', 'profile.error.bucket', false)],
            'attr'        => ['autocomplete' => 'off', 'spellcheck' => 'false'],
        ]);
        $this->addWithDefaults($builder, 'prefix', TextType::class, [
            'label'       => 'profile.field.prefix',
            'help'        => 'profile.help.prefix',
            'required'    => false,
            'disabled'    => $readOnly,
            'constraints' => [new Callback(static function (mixed $prefix, ExecutionContextInterface $context): void {
                try {
                    S3Settings::prefix(is_string($prefix) ? $prefix : '');
                } catch (InvalidArgumentException) {
                    $context->buildViolation('profile.error.prefix')->setTranslationDomain(NowoStorageKitBundle::TRANSLATION_DOMAIN)->addViolation();
                }
            })],
            'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
        ]);
        $this->addWithDefaults($builder, 'access_key', TextType::class, [
            'label'       => 'profile.field.access_key',
            'required'    => false,
            'disabled'    => $readOnly,
            'constraints' => [new Length(max: S3Settings::ACCESS_KEY_MAX), $this->pattern('/^[^\x00-\x20\x7F]*$/', 'profile.error.access_key')],
            'attr'        => ['autocomplete' => 'off', 'spellcheck' => 'false'],
        ]);
        $this->addWithDefaults($builder, 'secret', PasswordType::class, [
            'label'        => 'profile.field.secret',
            'help'         => $hasSecret ? 'profile.help.secret_kept' : 'profile.help.secret',
            'required'     => false,
            'disabled'     => $readOnly,
            'always_empty' => true,
            'constraints'  => [new Length(max: S3Settings::SECRET_MAX), $this->pattern('/^[^\x00-\x1F\x7F]*$/', 'profile.error.secret')],
            'attr'         => ['autocomplete' => 'new-password'],
        ]);
        $this->addWithDefaults($builder, 'path_style', CheckboxType::class, [
            'label'    => 'profile.field.path_style',
            'help'     => 'profile.help.path_style',
            'required' => false,
            'disabled' => $readOnly,
        ]);
    }

    /**
     * POST with CSRF (token id from the controller), bundle translation domain.
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

    /**
     * A non-empty value must (`$match`) or must not match `$regex`; the message is a bundle-domain key.
     */
    private function pattern(string $regex, string $key, bool $match = true): Callback
    {
        return new Callback(static function (mixed $value, ExecutionContextInterface $context) use ($regex, $key, $match): void {
            if (!is_string($value) || $value === '') {
                return;
            }
            if ((preg_match($regex, $value) === 1) !== $match) {
                $context->buildViolation($key)->setTranslationDomain(NowoStorageKitBundle::TRANSLATION_DOMAIN)->addViolation();
            }
        });
    }

    private function endpointConstraint(?EndpointGuard $guard, bool $restricted): Callback
    {
        return new Callback(static function (mixed $value, ExecutionContextInterface $context) use ($guard, $restricted): void {
            if (!is_string($value) || trim($value) === '') {
                return;
            }
            try {
                S3Settings::endpoint($value);
            } catch (InvalidArgumentException) {
                $context->buildViolation('profile.error.' . EndpointGuard::INVALID)->setTranslationDomain(NowoStorageKitBundle::TRANSLATION_DOMAIN)->addViolation();

                return;
            }
            $reason = $restricted && $guard instanceof EndpointGuard ? $guard->check($value) : null;
            if ($reason !== null) {
                $context->buildViolation('profile.error.' . $reason)->setTranslationDomain(NowoStorageKitBundle::TRANSLATION_DOMAIN)->addViolation();
            }
        });
    }
}
