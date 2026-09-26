<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $subscription = $this->whenLoaded('subscriptions', fn () => $this->subscriptions->first());

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'subscription' => $subscription
                ? new SubscriptionResource($subscription)
                : null,
        ];
    }
}
