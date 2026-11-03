<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Form\Extension;

use Nowo\InstanceSettingsBundle\Form\SettingsSectionType;
use Nowo\StorageKitBundle\Form\AwsCsvImport;
use Nowo\StorageKitBundle\Settings\StorageSettingsSection;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Adds the *Import from AWS CSV* row ({@see AwsCsvImport}) to the `storage` Instance Settings section form, after its
 * fields; other sections are left alone. The secret is DoctrineEncrypt's compound `SecretType`, so the CSV secret goes
 * to `[secret][value]`. The row is unmapped: Instance Settings saves only the section's own fields.
 *
 * Registered only when the instance bucket is settings-backed.
 */
final class StorageSettingsSectionTypeExtension extends AbstractTypeExtension
{
    /**
     * The file row and its submit listener, on the `storage` section only.
     *
     * @param FormBuilderInterface<mixed> $builder section form
     * @param array<string, mixed> $options resolved options (`section`)
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (!$options['section'] instanceof StorageSettingsSection) {
            return;
        }
        AwsCsvImport::add($builder, ['secret', 'value']);
    }

    /**
     * Extends Instance Settings' section form.
     *
     * @return iterable<class-string>
     */
    public static function getExtendedTypes(): iterable
    {
        return [SettingsSectionType::class];
    }
}
