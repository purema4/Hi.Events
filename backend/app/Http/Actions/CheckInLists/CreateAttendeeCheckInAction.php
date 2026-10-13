<?php

namespace HiEvents\Http\Actions\CheckInLists;

use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Middleware\EnforceApiKeyScope;
use HiEvents\Http\Request\CheckInList\CreateAttendeeCheckInRequest;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use HiEvents\Resources\CheckInList\AttendeeCheckInPublicResource;
use HiEvents\Services\Application\Handlers\CheckInList\Public\CreateAttendeeCheckInPublicHandler;
use HiEvents\Services\Application\Handlers\CheckInList\Public\DTO\CreateAttendeeCheckInPublicDTO;
use HiEvents\Services\Domain\Auth\AuthUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class CreateAttendeeCheckInAction extends BaseAction
{
    public function __construct(
        private readonly CreateAttendeeCheckInPublicHandler $createAttendeeCheckInPublicHandler,
        private readonly CheckInListRepositoryInterface $checkInListRepository,
        private readonly AuthUserService $authUserService,
    ) {}

    public function __invoke(int $eventId, int $checkInListId, CreateAttendeeCheckInRequest $request): JsonResponse
    {
        if ($this->authUserService->isApiKeyRequest() && ! $request->onlyChecksIn()) {
            return $this->jsonResponse([
                'message' => __('API keys can only check attendees in, not mark their orders as paid.'),
                'error_code' => EnforceApiKeyScope::ERROR_CODE,
                'ability' => null,
            ], Response::HTTP_FORBIDDEN);
        }

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
            $checkIns = $this->createAttendeeCheckInPublicHandler->handle(CreateAttendeeCheckInPublicDTO::from([
                'checkInListUuid' => $checkInList->getShortId(),
                'checkInUserIpAddress' => $request->ip(),
                'attendeesAndActions' => $request->validated('attendees'),
            ]));
        } catch (CannotCheckInException $e) {
            return $this->jsonResponse([
                'message' => $e->getMessage(),
                'errors' => [],
                'error_code' => $e->getFailureCode()?->value,
            ], Response::HTTP_CONFLICT);
        }

        return $this->resourceResponse(
            resource: AttendeeCheckInPublicResource::class,
            data: $checkIns->attendeeCheckIns,
            errors: $checkIns->errors->toArray()
        );
    }
}
