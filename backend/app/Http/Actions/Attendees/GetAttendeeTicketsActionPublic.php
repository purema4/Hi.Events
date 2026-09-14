<?php

namespace HiEvents\Http\Actions\Attendees;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Attendee\AttendeeResourcePublic;
use HiEvents\Services\Application\Handlers\Attendee\DTO\GetAttendeeTicketsDTO;
use HiEvents\Services\Application\Handlers\Attendee\GetAttendeeTicketsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class GetAttendeeTicketsActionPublic extends BaseAction
{
    public function __construct(
        private readonly GetAttendeeTicketsHandler $getAttendeeTicketsHandler,
    ) {}

    public function __invoke(int $eventId, string $attendeeShortId): JsonResponse|Response
    {
        try {
            $attendees = $this->getAttendeeTicketsHandler->handle(new GetAttendeeTicketsDTO(
                attendeeShortId: $attendeeShortId,
                eventId: $eventId,
            ));
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->resourceResponse(AttendeeResourcePublic::class, $attendees);
    }
}
