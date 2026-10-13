<?php

namespace HiEvents\Http\Request\ApiKey;

use HiEvents\DomainObjects\Enums\ApiKeyAbility;
use HiEvents\Http\Request\BaseRequest;
use Illuminate\Validation\Rule;

class CreateApiKeyRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['required', 'string', 'distinct', Rule::in(ApiKeyAbility::valuesArray())],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
