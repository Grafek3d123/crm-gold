<?php

namespace App\Actions\Customers;

use App\Attribution\AttributionProjectionService;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Inquiry;
use App\Models\Touchpoint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Ручное слияние двух карточек клиента.
 *
 * Доступно только администратору (проверяется в контроллере через Policy).
 * Выполняется одной транзакцией, переносит поддерживаемые связи,
 * сохраняет merge audit и не теряет attribution history.
 */
class MergeCustomersAction
{
    public function __construct(
        private readonly AttributionProjectionService $projections,
    ) {}

    public function execute(Customer $winner, Customer $loser, string $reason): Customer
    {
        if ($winner->is($loser)) {
            throw new CustomerMergeConflictException('Нельзя слить карточку с самой собой');
        }

        if ($loser->is_merged) {
            throw new CustomerMergeConflictException('Карточка уже объединена с другой');
        }

        if ($winner->is_merged) {
            throw new CustomerMergeConflictException('Целевая карточка уже объединена с другой');
        }

        if ($loser->phone_normalized !== null
            && $loser->phone_normalized === $winner->phone_normalized) {
            throw new CustomerMergeConflictException('Оба клиента имеют одинаковый телефон');
        }

        if ($loser->email_normalized !== null
            && $loser->email_normalized === $winner->email_normalized) {
            throw new CustomerMergeConflictException('Оба клиента имеют одинаковый email');
        }

        $loserKeys = CustomerIdentity::query()
            ->where('customer_id', $loser->id)
            ->get()
            ->map(fn ($i) => $i->provider.'|'.$i->external_key)
            ->all();

        if (! empty($loserKeys)) {
            $overlap = CustomerIdentity::query()
                ->where('customer_id', $winner->id)
                ->get()
                ->filter(fn ($i) => in_array($i->provider.'|'.$i->external_key, $loserKeys, true))
                ->isNotEmpty();

            if ($overlap) {
                throw new CustomerMergeConflictException('Клиенты имеют общую идентичность');
            }
        }

        return DB::transaction(function () use ($winner, $loser, $reason) {
            /** @var \App\Models\User $actor */
            $actor = Auth::user();

            $moved = [
                'inquiries' => Inquiry::query()->where('customer_id', $loser->id)->update(['customer_id' => $winner->id]),
                'touchpoints' => Touchpoint::query()->where('customer_id', $loser->id)->update(['customer_id' => $winner->id]),
                'activities' => Activity::query()->where('customer_id', $loser->id)->update(['customer_id' => $winner->id]),
            ];

            CustomerIdentity::query()
                ->where('customer_id', $loser->id)
                ->update(['customer_id' => $winner->id]);

            $loser->is_merged = true;
            $loser->comment = trim(($loser->comment ?? '').' [объединена с #'.$winner->id.': '.$reason.']');
            $loser->save();

            $winnerSnapshot = [
                'phone_normalized' => $winner->phone_normalized,
                'email_normalized' => $winner->email_normalized,
                'name' => $winner->name,
                'comment' => $winner->comment,
            ];

            // Дополняем контакты победителя, если у него их не было
            $changed = false;

            if ($winner->phone_normalized === null && $loser->phone_normalized !== null) {
                $winner->phone_normalized = $loser->phone_normalized;
                $winner->phone_raw = $loser->phone_raw;
                $changed = true;
            }

            if ($winner->email_normalized === null && $loser->email_normalized !== null) {
                $winner->email_normalized = $loser->email_normalized;
                $winner->email_raw = $loser->email_raw;
                $changed = true;
            }

            if ($winner->name === null && $loser->name !== null) {
                $winner->name = $loser->name;
                $changed = true;
            }

            if ($changed) {
                $winner->save();
            }

            $this->projections->rebuildFor($winner);

            AuditLog::query()->create([
                'actor_id' => $actor?->id,
                'action' => 'customer_merged',
                'entity_type' => Customer::class,
                'entity_id' => $winner->id,
                'before' => [
                    'winner' => $winnerSnapshot,
                    'loser' => [
                        'id' => $loser->id,
                        'phone_normalized' => $loser->phone_normalized,
                        'email_normalized' => $loser->email_normalized,
                        'name' => $loser->name,
                    ],
                ],
                'after' => ['merged_into' => $winner->id, 'moved' => $moved, 'reason' => $reason],
            ]);

            return $winner;
        });
    }
}
