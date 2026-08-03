<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\SubscribeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function subscribe(SubscribeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $plan = config("plans.{$data['plan']}");

        $subscription = $request->user()
            ->newSubscription('default', $plan['stripe_price'])
            ->create($data['payment_method']);

        return response()->json([
            'data' => $this->subscriptionPayload($subscription, $data['plan']),
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        $subscription = $request->user()->subscription('default');

        if ($subscription === null) {
            return response()->json(['data' => null]);
        }

        $planKey = collect(config('plans'))
            ->search(fn ($plan) => $plan['stripe_price'] === $subscription->stripe_price);

        return response()->json([
            'data' => $this->subscriptionPayload($subscription, $planKey ?: null),
        ]);
    }

    private function subscriptionPayload($subscription, ?string $planKey): array
    {
        return [
            'plan' => $planKey,
            'status' => $subscription->stripe_status,
            'ends_at' => $subscription->ends_at,
            'trial_ends_at' => $subscription->trial_ends_at,
        ];
    }
}
