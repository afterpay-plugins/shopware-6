<?php

namespace Colo\AfterPay\Manager;

use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderCollection;
use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderEntity;
use Shopware\Core\Content\Media\Aggregate\MediaFolderConfiguration\MediaFolderConfigurationEntity;
use Shopware\Core\Content\Media\Aggregate\MediaThumbnailSize\MediaThumbnailSizeCollection;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\DependencyInjection\ContainerInterface;

class MediaFolderManager
{

    public const MAIN_FOLDER_NAME = 'AfterPay';

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * MediaFolderManager constructor.
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * @param Context $context
     * @param string $name
     * @param MediaFolderEntity $parentMediaFolder
     * @param array $thumbnailSizes
     * @return MediaFolderEntity
     */
    public function createMediaFolder(Context $context, string $name, MediaFolderEntity $parentMediaFolder = null, array $thumbnailSizes = [])
    {
        /** @var EntityRepositoryInterface $repository */
        $repository = $this->container->get('media_folder.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.colo_afterpay_folder', $name));
        if (!empty($parentMediaFolder)) {
            $criteria->addFilter(new EqualsFilter('parentId', $parentMediaFolder->getId()));
        }

        $mediaFolder = $repository->search($criteria, $context)->first();
        if (!empty($mediaFolder)) {
            return $mediaFolder;
        }

        $id = Uuid::randomHex();
        $albumFolderData = [
            'id' => $id,
            'name' => $name,
            'useParentConfiguration' => false,
            'customFields' => [
                'colo_afterpay_folder' => $name
            ]
        ];

        if (empty($parentMediaFolder)) {
            $thumbnailSizeCollection = $this->createMediaThumbnailSizes($context, $thumbnailSizes);

            $configuration = $this->createMediaFolderConfiguration($context, $thumbnailSizeCollection);

            $albumFolderData['configurationId'] = $configuration->getId();
        } else {
            $albumFolderData['parentId'] = $parentMediaFolder->getId();
            $albumFolderData['useParentConfiguration'] = true;
            if ($parentMediaFolder->getConfiguration()) {
                $albumFolderData['configurationId'] = $parentMediaFolder->getConfiguration()->getId();
            }
        }

        $repository->upsert([$albumFolderData], $context);

        return $repository->search((new Criteria([$id]))->addAssociation('configuration'), $context)->first();
    }

    /**
     * @param Context $context
     */
    public function removeAlbumFolders(Context $context)
    {
        /** @var EntityRepositoryInterface $repository */
        $repository = $this->container->get('media_folder.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new NotFilter(
            NotFilter::CONNECTION_OR, [
                new EqualsFilter('customFields.colo_afterpay_folder', null)
            ]
        ));
        $criteria->addAssociation('media');
        $criteria->addAssociation('configuration');
        $criteria->addAssociation('configuration.mediaFolders');
        $criteria->addAssociation('configuration.mediaThumbnailSizes');
        $criteria->addAssociation('configuration.mediaThumbnailSizes.mediaFolderConfigurations');

        $result = $repository->search($criteria, $context);
        if ($result->getTotal() === 0) {
            return;
        }
        /** @var MediaFolderCollection $result */
        foreach ($result as $mediaFolder) {
            /** @var MediaFolderConfigurationEntity $configuration */
            $configuration = $mediaFolder->getConfiguration();
            if (!empty($configuration)) {
                $this->removeMediaFolderConfiguration($context, $configuration, $mediaFolder);
            }

            /** @var MediaCollection $medias */
            $medias = $mediaFolder->getMedia();
            if ($medias->count() > 0) {
                $this->removeMedias($context, $medias);
            }

            $repository->delete([
                [
                    'id' => $mediaFolder->getId()
                ]
            ], $context);
        }
    }

    /**
     * @param Context $context
     * @param array $sizes
     * @return MediaThumbnailSizeCollection
     */
    private function createMediaThumbnailSizes(Context $context, array $sizes = [['w' => 150, 'h' => 150], ['w' => 640, 'h' => 640]])
    {
        $collection = new MediaThumbnailSizeCollection();

        /** @var EntityRepositoryInterface $thumbnailSizesRepository */
        $repository = $this->container->get('media_thumbnail_size.repository');
        if (empty($sizes)) {
            return $collection;
        }
        foreach ($sizes as $size) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('width', $size['w']));
            $criteria->addFilter(new EqualsFilter('height', $size['h']));

            $thumbnailSize = $repository->search($criteria, $context)->first();
            if (empty($thumbnailSize)) {
                $id = Uuid::randomHex();
                $repository->upsert([
                    [
                        'id' => $id,
                        'width' => $size['w'],
                        'height' => $size['h']
                    ]
                ], $context);

                $thumbnailSize = $repository->search(new Criteria([$id]), $context)->first();
            }
            $collection->add($thumbnailSize);
        }

        return $collection;
    }

    /**
     * @param Context $context
     * @param MediaThumbnailSizeCollection $thumbnailSizes
     * @return MediaFolderConfigurationEntity
     */
    private function createMediaFolderConfiguration(Context $context, MediaThumbnailSizeCollection $thumbnailSizes)
    {
        /** @var EntityRepositoryInterface $repository */
        $repository = $this->container->get('media_folder_configuration.repository');
        $id = Uuid::randomHex();

        $mediaThumbnailSizes = [];
        if ($thumbnailSizes->count() > 0) {
            foreach ($thumbnailSizes as $thumbnailSize) {
                $mediaThumbnailSizes[] = ['id' => $thumbnailSize->getId()];
            }
        }

        $repository->upsert([
            [
                'id' => $id,
                'createThumbnails' => true,
                'keepAspectRatio' => true,
                'thumbnailQuality' => 80,
                'private' => false,
                'noAssociation' => false,
                'mediaThumbnailSizes' => $mediaThumbnailSizes
            ]
        ], $context);

        return $repository->search(new Criteria([$id]), $context)->first();
    }

    /**
     * @param Context $context
     * @param MediaFolderConfigurationEntity $configuration
     * @param MediaFolderEntity $mediaFolder
     */
    private function removeMediaFolderConfiguration(Context $context, MediaFolderConfigurationEntity $configuration, MediaFolderEntity $mediaFolder)
    {
        $mediaFolders = $configuration->getMediaFolders();
        $mediaFolderIds = $mediaFolders->getKeys();

        if (count($mediaFolderIds) === 1 && $mediaFolderIds[0] === $mediaFolder->getId()) {
            /** @var MediaThumbnailSizeCollection $thumbnailSizes */
            $thumbnailSizes = $configuration->getMediaThumbnailSizes();
            if ($thumbnailSizes->count() > 0) {
                $this->removeMediaThumbnailSizes($context, $thumbnailSizes, $configuration);
            }

            /** @var EntityRepositoryInterface $repository */
            $repository = $this->container->get('media_folder_configuration.repository');
            $repository->delete([
                [
                    'id' => $configuration->getId(),
                ]
            ], $context);
        }
    }

    /**
     * @param Context $context
     * @param MediaThumbnailSizeCollection $thumbnailSizes
     * @param MediaFolderConfigurationEntity $configuration
     */
    private function removeMediaThumbnailSizes(Context $context, MediaThumbnailSizeCollection $thumbnailSizes, MediaFolderConfigurationEntity $configuration)
    {
        foreach ($thumbnailSizes as $thumbnailSize) {
            $configurations = $thumbnailSize->getMediaFolderConfigurations();
            $configurationIds = $configurations->getKeys();

            if (count($configurationIds) === 1 && $configurationIds[0] === $configuration->getId()) {
                /** @var EntityRepositoryInterface $repository */
                $repository = $this->container->get('media_thumbnail_size.repository');
                $repository->delete([
                    [
                        'id' => $thumbnailSize->getId(),
                    ]
                ], $context);
            }
        }
    }

    /**
     * @param Context $context
     * @param MediaCollection $medias
     */
    private function removeMedias(Context $context, MediaCollection $medias)
    {
        /** @var EntityRepositoryInterface $repository */
        $repository = $this->container->get('media.repository');
        $params = [];
        foreach ($medias as $media) {
            $params[] = ['id' => $media->getId()];
        }
        $repository->delete($params, $context);
    }

}