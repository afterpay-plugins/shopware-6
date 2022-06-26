<?php

namespace Colo\AfterPay\Manager;

use Shopware\Core\Content\Media\File\MediaFile;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderEntity;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Shopware\Core\Content\Media\File\FileSaver;

class MediaManager
{

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var EntityRepositoryInterface
     */
    private $mediaRepository;

    /**
     * @var FileSaver
     */
    private $fileSaver;

    /**
     * MediaManager constructor.
     * @param ContainerInterface $container
     * @param EntityRepositoryInterface $mediaRepository
     * @param FileSaver $fileSaver
     */
    public function __construct(ContainerInterface $container, EntityRepositoryInterface $mediaRepository, FileSaver $fileSaver)
    {
        $this->container = $container;
        $this->mediaRepository = $mediaRepository;
        $this->fileSaver = $fileSaver;
    }

    /**
     * @param Context $context
     * @param MediaFolderEntity $mediaFolder
     * @param string $filePath
     * @return MediaFolderEntity
     */
    public function createMedia(Context $context, MediaFolderEntity $mediaFolder, string $filePath)
    {
        $pathinfo = pathinfo($filePath);
        $fileName = $pathinfo['filename'];

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('mediaFolderId', $mediaFolder->getId()));
        $criteria->addFilter(new EqualsFilter('fileName', $fileName));
        $media = $this->mediaRepository->search($criteria, $context)->first();

        if (!empty($media)) {
            return $media;
        }

        $fileBlob = file_get_contents($filePath);

        $mediaId = Uuid::randomHex();
        $this->mediaRepository->create(
            [
                [
                    'id' => $mediaId,
                    'private' => false,
                    'mediaFolderId' => $mediaFolder->getId(),
                ],
            ],
            $context
        );

        $mediaFile = $this->fetchBlob($fileBlob, $pathinfo['extension'], mime_content_type($filePath));

        $this->fileSaver->persistFileToMedia($mediaFile, $fileName, $mediaId, $context);

        return $this->mediaRepository->search($criteria, $context)->first();
    }

    private function fetchBlob(string $blob, string $extension, string $contentType): MediaFile
    {
        $tempFile = tempnam(sys_get_temp_dir(), '');
        $fh = @fopen($tempFile, 'wb');
        $blobSize = @fwrite($fh, $blob);

        return new MediaFile(
            $tempFile,
            $contentType,
            $extension,
            $blobSize
        );
    }

}