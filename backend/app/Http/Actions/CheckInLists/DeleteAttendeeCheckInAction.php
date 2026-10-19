<?php

namespace HiEvents\Http\Actions\CheckInLists;

use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use HiEvents\Services\Application\Handlers\CheckInList\Public\DeleteAttendeeCheckInPublicHandler;
use HiEvents\Services\Application\Handlers\CheckInList\Public\DTO\DeleteAttendeeCheckInPublicDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class DeleteAttendeeCheckInAction extends BaseAction
{
    public function __construct(
        private readonly DeleteAttendeeCheckInPublicHandler $deleteAttendeeCheckInPublicHandler,
        private readonly CheckInListRepositoryInterface $checkInListRepository,
    ) {}

    public function __invoke(int $eventId, int $checkInListId, string $checkInShortId, Request $request): Response|JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        /** @var CheckInListDomainObject|null $checkInList */
        $checkInList = $this->checkInListRepository->findFirstWhere([
            'id' => $checkInListId,
            'event_id' => $eventId,
        ]);

        if ($checkInList === null) {
            throw new ResourceNotFoundException(__('Check-in list not found'));
        }

        try {
            $this->deleteAttendeeCheckInPublicHandler->handle(new DeleteAttendeeCheckInPublicDTO(
                checkInListShortId: $checkInList->getShortId(),
                checkInShortId: $checkInShortId,
                checkInUserIpAddress: $request->ip(),
            ));
        } catch (CannotCheckInException $e) {
            return $this->jsonResponse([
                'message' => $e->getMessage(),
                'errors' => [],
                'error_code' => $e->getFailureCode()?->value,
            ], Response::HTTP_CONFLICT);
        }

        return $this->deletedResponse();
    }
}
