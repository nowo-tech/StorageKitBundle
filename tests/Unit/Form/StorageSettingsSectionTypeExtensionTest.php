<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Form;

use Nowo\InstanceSettingsBundle\Form\SettingsSectionType;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionInterface;
use Nowo\StorageKitBundle\Form\Extension\StorageSettingsSectionTypeExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * The CSV row is added to the `storage` section only (the storage case is covered by the functional tests).
 */
final class StorageSettingsSectionTypeExtensionTest extends TestCase
{
    public function testOtherSectionsAreLeftAlone(): void
    {
        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->expects(self::never())->method('add');
        $builder->expects(self::never())->method('addEventListener');

        (new StorageSettingsSectionTypeExtension())->buildForm($builder, ['section' => $this->createStub(SettingsSectionInterface::class)]);
        self::assertSame([SettingsSectionType::class], [...StorageSettingsSectionTypeExtension::getExtendedTypes()]);
    }
}
