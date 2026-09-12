<?php

namespace HiEvents\Services\Application\Handlers\Event;

use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\Services\Application\Handlers\Event\DTO\CreateEventImageDTO;
use HiEvents\Services\Domain\Event\CreateEventImageService;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletSyncDispatcher;
use Throwable;

class CreateEventImageHandler
{
    public function __construct(
        private readonly CreateEventImageService $createEventImageService,
        private readonly GoogleWalletSyncDispatcher $googleWalletSyncDispatcher,
    ) {}

    /**
     * @throws Throwable
     */
    public function handle(CreateEventImageDTO $imageData): ImageDomainObject
    {
        $image = $this->createEventImageService->createImage(
            eventId: $imageData->eventId,
            accountId: $imageData->accountId,
            image: $imageData->image,
            imageType: $imageData->imageType,
        );

        $this->googleWalletSyncDispatcher->queueImageOwnerClassSync($imageData->imageType, $imageData->eventId);

        return $image;
    }
}
