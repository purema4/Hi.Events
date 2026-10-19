<?php

namespace HiEvents\Services\Application\Handlers\Question;

use HiEvents\Services\Application\Handlers\Question\DTO\EditQuestionAnswerDTO;
use HiEvents\Services\Domain\Question\EditQuestionAnswerService;
use HiEvents\Services\Domain\Question\Exception\InvalidAnswerException;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\AttendeeEvent;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use JsonException;

class EditQuestionAnswerHandler
{
    public function __construct(
        private readonly EditQuestionAnswerService $editQuestionAnswerService,
        private readonly DomainEventDispatcherService $domainEventDispatcherService,
    ) {}

    /**
     * @throws InvalidAnswerException
     * @throws JsonException
     */
    public function handle(EditQuestionAnswerDTO $editQuestionAnswerDTO): void
    {
        $questionAnswer = $this->editQuestionAnswerService->editQuestionAnswer(
            eventId: $editQuestionAnswerDTO->eventId,
            questionAnswerId: $editQuestionAnswerDTO->questionAnswerId,
            answer: $editQuestionAnswerDTO->answer,
        );

        if ($questionAnswer === null) {
            return;
        }

        $this->domainEventDispatcherService->dispatch(
            $questionAnswer->getAttendeeId() !== null
                ? new AttendeeEvent(type: DomainEventType::ATTENDEE_UPDATED, attendeeId: $questionAnswer->getAttendeeId())
                : new OrderEvent(type: DomainEventType::ORDER_UPDATED, orderId: $questionAnswer->getOrderId()),
        );
    }
}
