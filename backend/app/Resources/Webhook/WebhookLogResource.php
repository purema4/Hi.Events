<?php

namespace HiEvents\Resources\Webhook;

use HiEvents\DomainObjects\WebhookLogDomainObject;
use HiEvents\Resources\BaseResource;
use HiEvents\Services\Domain\Auth\AuthUserService;

/**
 * @mixin WebhookLogDomainObject
 */
class WebhookLogResource extends BaseResource
{
    public function toArray($request): array
    {
        $isApiKeyRequest = app(AuthUserService::class)->isApiKeyRequest();

        return [
            'id' => $this->getId(),
            'webhook_id' => $this->getWebhookId(),
            'payload' => $this->unless($isApiKeyRequest, fn () => $this->getPayload()),
            'response_body' => $this->unless($isApiKeyRequest, fn () => $this->getResponseBody()),
            'response_code' => $this->getResponseCode(),
            'created_at' => $this->getCreatedAt(),
            'event_type' => $this->getEventType(),
        ];
    }
}
