<?php

namespace Tests\Unit\Mcp\Tools;

use HiEvents\Http\Actions\Events\GetEventAction;
use HiEvents\Http\Actions\SaleLinks\CreateSaleLinkAction;
use HiEvents\Mcp\Tools\CreateSaleLinkTool;
use Laravel\Mcp\Request;

class CreateSaleLinkToolTest extends McpToolTestCase
{
    public function test_it_creates_the_link_and_returns_the_buyer_url_in_local_time(): void
    {
        config(['app.frontend_url' => 'https://tickets.example']);
        $items = [['product_id' => 5, 'product_price_id' => 9, 'quantity' => 2, 'unit_price' => 15]];

        $this->api->shouldReceive('get')->with(GetEventAction::class, ['event_id' => 12])->andReturn($this->ok($this->event()));
        $this->api->shouldReceive('send')
            ->once()
            ->with('POST', CreateSaleLinkAction::class, ['event_id' => 12], [
                'items' => $items,
                'max_uses' => 1,
                'expires_at' => '2027-06-01 20:00',
            ])
            ->andReturn($this->ok([
                'id' => 4,
                'token' => 'abc123',
                'status' => 'ACTIVE',
                'max_uses' => 1,
                'expires_at' => '2027-06-02T00:00:00+00:00',
                'items' => [[
                    'product_id' => 5,
                    'product_price_id' => 9,
                    'product_title' => 'VIP',
                    'price_label' => null,
                    'quantity' => 2,
                    'unit_price' => 15,
                ]],
            ]));

        $result = $this->structured((new CreateSaleLinkTool($this->api))->handle(new Request([
            'event_id' => 12, 'items' => $items, 'max_uses' => 1, 'expires_at' => '2027-06-01 20:00',
        ])));

        $this->assertSame('https://tickets.example/pay/12/abc123', $result['sale_link']['url']);
        $this->assertSame('2027-06-01T20:00:00-04:00', $result['sale_link']['expires_at']);
        $this->assertSame('VIP', $result['sale_link']['items'][0]['title']);
    }
}
