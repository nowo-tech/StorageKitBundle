<?php

declare(strict_types=1);

namespace App\Form;

use Nowo\FormKitBundle\Form\FormKitAbstractType;
use Override;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Demo form (REQ-TWIG-005 rule 7: multipart through FileType): a Markdown note and an optional image. The image goes
 * through the bundle's PHP API (ImageUploader) — the same checks as the JSON upload endpoint editors call.
 *
 * @extends FormKitAbstractType<array{body: string, image: mixed}>
 */
final class NoteType extends FormKitAbstractType
{
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('body', TextareaType::class, ['label' => 'Note (Markdown)', 'constraints' => [new NotBlank(), new Length(max: 2000)], 'attr' => ['rows' => 3, 'data-testid' => 'demo-note-body']])
            ->add('image', FileType::class, ['label' => 'Image (PNG, JPEG, GIF or WebP)', 'required' => false, 'attr' => ['accept' => 'image/png,image/jpeg,image/gif,image/webp', 'data-testid' => 'demo-note-image']])
            ->add('save', SubmitType::class, ['label' => 'Save note', 'attr' => ['class' => 'btn btn-primary', 'data-testid' => 'demo-note-save']]);
    }
}
