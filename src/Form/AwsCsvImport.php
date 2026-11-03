<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Form;

use ArrayObject;
use Nowo\StorageKitBundle\NowoStorageKitBundle;
use Nowo\StorageKitBundle\Storage\StorageProvider;
use Nowo\UiKitBundle\Form\VisibleWhen;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function array_slice;
use function count;
use function is_array;
use function is_scalar;
use function trim;

/**
 * The optional *Import from AWS CSV* row of a bucket form (unmapped `FileType` {@see FIELD}, shown only for the
 * `aws_s3` provider through UiKit `visible-when`). On submit, a file sent with provider `aws_s3` is parsed server-side
 * ({@see AwsAccessKeyCsv}) and fills the access key and the (write-only) secret before validation; the file is only
 * read from PHP's temporary upload, never moved, stored or logged.
 *
 * Precedence: a typed value that differs from the file is a conflict and the form refuses it
 * (`profile.error.csv_conflict`) instead of guessing. "Typed" means a non-blank secret, or an access key the user
 * changed (the access key row is pre-filled with the saved key, which the file may replace — key rotation). The same
 * value in both is fine. A file sent with another provider is ignored like every hidden row. Refusals are form errors on
 * the file row (`profile.error.csv_<reason>`), never repeating the file's content.
 *
 * Used by {@see StorageProfileType} (secret path `[secret]`) and, for the `storage` Instance Settings section, by
 * {@see Extension\StorageSettingsSectionTypeExtension} (secret path `[secret][value]`, DoctrineEncrypt `SecretType`).
 */
final class AwsCsvImport
{
    /** Form field name. */
    public const FIELD = 'access_key_csv';

    /** Name of the access key field next to it. */
    public const ACCESS_KEY = 'access_key';

    /** Stable `data-testid` of the file input (host e2e contract). */
    public const TEST_ID = 'storage-kit-access-key-csv';

    /** `accept` attribute of the file input (a hint only: the server judges the content). */
    public const ACCEPT = '.csv,text/csv,text/plain';

    /**
     * Adds the file row and the submit listener to a bucket form.
     *
     * @param FormBuilderInterface<mixed> $builder the bucket form (fields `provider` and `access_key`)
     * @param list<string> $secretPath path of the secret value in the submitted data
     * @param bool $disabled read-only form: the row is disabled and no file is read
     * @param (callable(string, array<string, mixed>): void)|null $adder adds the `FileType` field by name and options (e.g. through FormKit's `addWithDefaults`); default `$builder->add()`
     */
    public static function add(FormBuilderInterface $builder, array $secretPath, bool $disabled = false, ?callable $adder = null): void
    {
        $adder ??= static function (string $name, array $options) use ($builder): void {
            $builder->add($name, FileType::class, $options);
        };
        /** @var ArrayObject<string, string> $state refusal reason of this form instance */
        $state = new ArrayObject();
        $adder(self::FIELD, [
            'label'              => 'profile.field.access_key_csv',
            'help'               => 'profile.help.access_key_csv',
            'translation_domain' => NowoStorageKitBundle::TRANSLATION_DOMAIN,
            'mapped'             => false,
            'required'           => false,
            'multiple'           => false,
            'disabled'           => $disabled,
            'attr'               => ['accept' => self::ACCEPT, 'data-testid' => self::TEST_ID],
            'row_attr'           => VisibleWhen::attributes($builder->getName() . '[provider]', [StorageProvider::AWS_S3]),
            'constraints'        => [new Callback(static function (mixed $file, ExecutionContextInterface $context) use ($state): void {
                if (isset($state['reason'])) {
                    $context->buildViolation('profile.error.csv_' . $state['reason'])->setTranslationDomain(NowoStorageKitBundle::TRANSLATION_DOMAIN)->addViolation();
                }
            })],
        ]);
        if ($disabled) {
            return;
        }
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event) use ($state, $secretPath): void {
            $data = $event->getData();
            if (!is_array($data) || !($data[self::FIELD] ?? null) instanceof UploadedFile || ($data['provider'] ?? null) !== StorageProvider::AWS_S3) {
                return;
            }
            try {
                $csv = AwsAccessKeyCsv::fromUpload($data[self::FIELD]);
            } catch (AwsAccessKeyCsvException $refused) {
                $state['reason'] = $refused->reason;

                return;
            }
            $typedKey    = self::text($data[self::ACCESS_KEY] ?? null);
            $savedKey    = self::text($event->getForm()->get(self::ACCESS_KEY)->getViewData());
            $typedSecret = self::text(self::read($data, $secretPath));
            if (($typedKey !== '' && $typedKey !== $savedKey && $typedKey !== $csv->keyId) || ($typedSecret !== '' && $typedSecret !== $csv->secret())) {
                $state['reason'] = AwsAccessKeyCsvException::REASON_CONFLICT;

                return;
            }
            $data[self::ACCESS_KEY] = $csv->keyId;
            $event->setData(self::write($data, $secretPath, $csv->secret()));
        });
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $path
     */
    private static function read(array $data, array $path): mixed
    {
        $value = $data;
        foreach ($path as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $path
     *
     * @return array<mixed>
     */
    private static function write(array $data, array $path, string $value): array
    {
        $key = $path[0];
        if (count($path) === 1) {
            $data[$key] = $value;

            return $data;
        }
        $data[$key] = self::write(is_array($data[$key] ?? null) ? $data[$key] : [], array_slice($path, 1), $value);

        return $data;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
