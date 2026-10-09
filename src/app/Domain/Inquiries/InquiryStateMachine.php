<?php

namespace App\Domain\Inquiries;

use App\Enums\InquiryStatus;
use App\Models\Inquiry;
use InvalidArgumentException;

class InquiryStateMachine
{
    /**
     * @throws InvalidArgumentException
     */
    public function ensureTransition(Inquiry $inquiry, InquiryStatus $to, ?int $changedBy, ?string $note = null): void
    {
        $from = $inquiry->status;

        if (! $from instanceof InquiryStatus) {
            $from = InquiryStatus::from((string) $from);
        }

        if (! $from->canTransitionTo($to)) {
            throw new InvalidArgumentException(
                sprintf('Недопустимый переход статуса: %s → %s', $from->value, $to->value)
            );
        }

        $inquiry->status = $to;
        $inquiry->save();

        $inquiry->statusHistory()->create([
            'from_status' => $from->value,
            'to_status' => $to->value,
            'changed_by' => $changedBy,
            'note' => $note,
        ]);
    }
}
