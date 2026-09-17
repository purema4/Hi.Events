<?php

namespace HiEvents\Resources\Wallet;

use HiEvents\Services\Application\Handlers\Wallet\DTO\OrderWalletPassesDTO;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderWalletPassesDTO
 */
class OrderWalletPassesResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'apple_wallet_pass_url' => $this->appleWalletPassUrl,
            'google_wallet_save_url' => $this->googleWalletSaveUrl,
        ];
    }
}
