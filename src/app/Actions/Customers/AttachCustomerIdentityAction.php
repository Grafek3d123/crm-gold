<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\CustomerIdentity;

/**
 * Сохранение внешних идентификаторов.
 *
 * ClientID / yclid / cookie key НЕ являются основанием для автоматического
 * слияния людей — они лишь фиксируются как внешние identity одной карточки.
 */
class AttachCustomerIdentityAction
{
    public function execute(
        Customer $customer,
        string $provider,
        string $externalKey,
        ?array $payload = null,
    ): CustomerIdentity {
        return CustomerIdentity::query()->updateOrCreate(
            [
                'provider' => $provider,
                'external_key' => $externalKey,
            ],
            [
                'customer_id' => $customer->id,
                'payload' => $payload,
            ]
        );
    }
}
