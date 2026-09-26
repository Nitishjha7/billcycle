<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'subtotal_paise' => $this->subtotal_paise,
            'total_paise' => $this->total_paise,
            'status' => $this->status,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'attempts_count' => $this->whenCounted('attempts'),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'description' => $line->description,
                'amount_paise' => $line->amount_paise,
                'type' => $line->type,
            ])),
            'attempts' => $this->whenLoaded('attempts', fn () => $this->attempts->map(fn ($attempt) => [
                'id' => $attempt->id,
                'attempt_number' => $attempt->attempt_number,
                'failure_code' => $attempt->failure_code,
                'attempted_at' => $attempt->attempted_at?->toIso8601String(),
                'next_retry_at' => $attempt->next_retry_at?->toIso8601String(),
            ])),
            'customer' => $this->when(
                $this->relationLoaded('subscription') && $this->subscription->relationLoaded('customer'),
                fn () => [
                    'id' => $this->subscription->customer->id,
                    'name' => $this->subscription->customer->name,
                ],
            ),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'status' => $payment->status,
                'created_at' => $payment->created_at?->toIso8601String(),
            ])),
        ];
    }
}
