<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Note;
use App\Form\NoteType;
use App\Storage\DemoBoardContext;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\StorageKitBundle\Reference\FileUris;
use Nowo\StorageKitBundle\Upload\ImageUploader;
use Nowo\StorageKitBundle\Upload\UploadRefused;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;

use function file_get_contents;
use function pathinfo;
use function sprintf;
use function trim;

use const PATHINFO_FILENAME;

final class DemoController extends AbstractController
{
    #[Route('/', name: 'demo_home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('demo/home.html.twig');
    }

    #[Route('/notes', name: 'demo_notes', methods: ['GET', 'POST'])]
    public function notes(Request $request, EntityManagerInterface $entityManager, ImageUploader $uploader, FileUris $uris): Response
    {
        $user = $this->getUser();
        \assert($user instanceof UserInterface);
        $form = $this->createForm(NoteType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $body  = trim((string) $form->get('body')->getData());
            $image = $form->get('image')->getData();
            if ($image instanceof UploadedFile) {
                try {
                    $file = $uploader->upload($user, DemoBoardContext::TYPE, DemoBoardContext::ID, (string) file_get_contents($image->getPathname()), $image->getClientOriginalName());
                    $body .= sprintf("\n\n![%s](%s)", pathinfo($file->getOriginalName(), PATHINFO_FILENAME), $uris->uri($file->getUuid()));
                } catch (UploadRefused $e) {
                    $this->addFlash('error', 'Image refused: ' . $e->reason);

                    return $this->redirectToRoute('demo_notes');
                }
            }
            $entityManager->persist(new Note($user->getUserIdentifier(), $body));
            $entityManager->flush();
            $this->addFlash('success', 'Note saved.');

            return $this->redirectToRoute('demo_notes');
        }

        return $this->render('demo/notes.html.twig', [
            'form'  => $form->createView(),
            'notes' => $entityManager->getRepository(Note::class)->findBy([], ['createdAt' => 'DESC'], 20),
            'board' => DemoBoardContext::ID,
        ]);
    }
}
