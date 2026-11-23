<?php

namespace HiEvents\Mcp\Tools;

use HiEvents\Helper\Url;
use HiEvents\Http\Actions\SaleLinks\CreateSaleLinkAction;
use HiEvents\Mcp\Tools\Support\McpPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('create_sale_link')]
#[Title('Create sale link')]
#[Description('Creates a private sale link for an event that sells specific tickets at custom prices, optionally outside the ticket cap. Returns the URL to send the buyer; the order is created when the buyer opens it and goes through the normal checkout. Requires permission to view financials.')]
#[IsDestructive]
class CreateSaleLinkTool extends WriteApiTool
{
    private const FIELDS = [
        'event_occurrence_id',
        'items',
        'price_includes_tax',
        'promo_code_id',
        'promo_mode',
        'affiliate_id',
        'bypass_capacity',
        'count_toward_cap',
        'max_uses',
        'expires_at',
        'email',
        'email_locked',
        'note',
    ];

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_id' => $schema->integer()->description('The event id.')->required(),
            'event_occurrence_id' => $schema->integer()->description('The event date id. Required when the event has more than one date.'),
            'items' => $schema->array()
                ->items($schema->object([
                    'product_id' => $schema->integer()->description('The ticket id.')->required(),
                    'product_price_id' => $schema->integer()->description('The ticket price or tier id.')->required(),
                    'quantity' => $schema->integer()->min(1)->required(),
                    'unit_price' => $schema->number()->min(0)->description('Price per ticket in the event currency.')->required(),
                ]))
                ->description('The tickets the link sells, 1 to 20 lines.')
                ->required(),
            'price_includes_tax' => $schema->boolean()->description('Whether unit_price already includes the ticket taxes and fees. Default false.'),
            'promo_code_id' => $schema->integer()->description('Promo code to attribute the sale to.'),
            'promo_mode' => $schema->string()->enum(['ATTRIBUTION_ONLY', 'APPLY_DISCOUNT'])->description('ATTRIBUTION_ONLY (default) records the code without discounting; APPLY_DISCOUNT also applies its discount to the link price.'),
            'affiliate_id' => $schema->integer()->description('Active affiliate to attribute the sale to.'),
            'bypass_capacity' => $schema->boolean()->description('Sell even if the tickets are sold out. Default true.'),
            'count_toward_cap' => $schema->boolean()->description('Count these tickets against the public ticket quantity. Default true.'),
            'max_uses' => $schema->integer()->min(1)->description('How many orders the link can create. Default 1.'),
            'expires_at' => $schema->string()->description(McpPresenter::LOCAL_DATE_DESCRIPTION),
            'email' => $schema->string()->description('Buyer email shown on the link.'),
            'email_locked' => $schema->boolean()->description('Only allow checkout with this email. Default false.'),
            'note' => $schema->string()->description('Internal note, never shown to the buyer.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $eventId = (int) $request->validate(['event_id' => 'required|integer|min:1'])['event_id'];

        if ($limited = $this->writeLimitResponse($request)) {
            return $limited;
        }

        $eventResponse = $this->getEvent($eventId);

        if (! $eventResponse->isSuccessful()) {
            return $this->errorResponse($eventResponse);
        }

        $response = $this->api->send('POST', CreateSaleLinkAction::class, ['event_id' => $eventId], $request->only(self::FIELDS));

        if (! $response->isSuccessful()) {
            return $this->errorResponse($response);
        }

        $saleLink = $response->data();
        $timezone = $eventResponse->data()['timezone'];

        return Response::structured([
            'sale_link' => [
                'id' => $saleLink['id'],
                'url' => sprintf(Url::getFrontEndUrlFromConfig(Url::SALE_LINK), $eventId, $saleLink['token']),
                'status' => $saleLink['status'],
                'max_uses' => $saleLink['max_uses'],
                'expires_at' => McpPresenter::localDate($saleLink['expires_at'], $timezone),
                'items' => array_map(static fn (array $item) => [
                    'product_id' => $item['product_id'],
                    'product_price_id' => $item['product_price_id'],
                    'title' => $item['product_title'],
                    'tier' => $item['price_label'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'] ?? null,
                ], $saleLink['items']),
            ],
        ]);
    }
}
