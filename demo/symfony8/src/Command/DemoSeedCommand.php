<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Note;
use AsyncAws\Core\Credentials\ConfigurationProvider;
use AsyncAws\S3\Exception\BucketAlreadyOwnedByYouException;
use AsyncAws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StorageSync;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Entity\StoredFileReference;
use Nowo\StorageKitBundle\Repository\StorageProfileRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

use function sprintf;

/**
 * Demo seed (idempotent): creates the two private RustFS buckets (`storage-kit-demo`, `storage-kit-archive`) and the
 * instance storage profile on the first one. `--reset` first removes the notes, files, moves and profiles (e2e).
 */
#[AsCommand(name: 'app:demo:seed', description: 'Create the demo buckets and the instance storage profile')]
final class DemoSeedCommand extends Command
{
    public const BUCKETS = ['storage-kit-demo', 'storage-kit-archive'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StorageProfileRepository $profiles,
        #[Autowire('%env(DEMO_S3_ENDPOINT)%')]
        private readonly string $endpoint,
        #[Autowire('%env(DEMO_S3_ACCESS_KEY)%')]
        private readonly string $accessKey,
        #[Autowire('%env(DEMO_S3_SECRET_KEY)%')]
        private readonly string $secretKey,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('reset', null, InputOption::VALUE_NONE, 'Remove the demo notes, files, moves and profiles first');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $s3 = new S3Client([
            'endpoint'          => $this->endpoint,
            'region'            => 'us-east-1',
            'accessKeyId'       => $this->accessKey,
            'accessKeySecret'   => $this->secretKey,
            'pathStyleEndpoint' => 'true',
            'sendChunkedBody'   => 'false',
        ], new ConfigurationProvider());
        foreach (self::BUCKETS as $bucket) {
            try {
                $s3->createBucket(['Bucket' => $bucket])->resolve();
            } catch (BucketAlreadyOwnedByYouException) {
            } catch (Throwable $e) {
                $io->warning(sprintf('Bucket %s: %s', $bucket, $e::class));
            }
        }

        if ($input->getOption('reset') === true) {
            foreach ([StoredFileReference::class, Note::class, StorageSync::class, StoredFile::class, StorageProfile::class] as $class) {
                $this->entityManager->createQuery('DELETE FROM ' . $class . ' e')->execute();
            }
        }
        if ($this->profiles->activeOf(null) === null) {
            $profile = new StorageProfile();
            $profile->configure(true, $this->endpoint, null, 'us-east-1', self::BUCKETS[0], 'demo', $this->accessKey, $this->secretKey, true);
            $this->entityManager->persist($profile);
            $this->entityManager->flush();
        }
        $io->success('Demo buckets and instance storage profile ready.');

        return Command::SUCCESS;
    }
}
