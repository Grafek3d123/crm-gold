<?php

namespace App\Attribution;

use App\Models\Customer;
use App\Models\Touchpoint;

/**
 * Проекции first/last touch.
 *
 * Это кэш истории, а не отдельный источник истины: пересчитывается из
 * таблицы touchpoints и не участвует в matching.
 */
class AttributionProjectionService
{
    public function rebuildFor(Customer $customer): void
    {
        $first = Touchpoint::query()
            ->where('customer_id', $customer->id)
            ->orderBy('touched_at')
            ->orderBy('id')
            ->first();

        $last = Touchpoint::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('touched_at')
            ->orderByDesc('id')
            ->first();

        $customer->first_touchpoint_id = $first?->id;
        $customer->last_touchpoint_id = $last?->id;
        $customer->save();
    }

    public function rebuildAll(): int
    {
        $count = 0;

        Customer::query()
            ->where('is_merged', false)
            ->orderBy('id')
            ->chunkById(200, function ($customers) use (&$count) {
                foreach ($customers as $customer) {
                    $this->rebuildFor($customer);
                    $count++;
                }
            });

        return $count;
    }

    public function firstTouch(Customer $customer): ?Touchpoint
    {
        if ($customer->first_touchpoint_id === null) {
            return null;
        }

        if ($customer->relationLoaded('firstTouchpoint') && $customer->firstTouchpoint !== null) {
            return $customer->firstTouchpoint;
        }

        return Touchpoint::query()->find($customer->first_touchpoint_id);
    }

    public function lastTouch(Customer $customer): ?Touchpoint
    {
        if ($customer->last_touchpoint_id === null) {
            return null;
        }

        return Touchpoint::query()->find($customer->last_touchpoint_id);
    }
}
