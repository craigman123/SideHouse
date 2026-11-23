<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\UserAccessService;
use Symfony\Component\HttpFoundation\Response;

class RedactStaffFinancialData
{
    private const FINANCIAL_FIELDS = ['amount', 'total_spent', 'total_income', 'today_income', 'revenue'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $user = $request->user();
        if (! $user || app(UserAccessService::class)->effectiveRole($user, $request) !== 'staff' || ! $response instanceof JsonResponse) {
            return $response;
        }

        $data = $response->getData(true);
        if (! is_array($data)) {
            return $response;
        }

        $access = app(UserAccessService::class);
        $canCreateWalkIn = $access->allows($request->user(), 'bookings.create');
        $canViewSpending = $access->allows($request->user(), 'customers.view_spending');
        $response->setData($this->withoutFinancialFields($data, $canCreateWalkIn, $canViewSpending));

        return $response;
    }

    private function withoutFinancialFields(array $value, bool $canCreateWalkIn, bool $canViewSpending): array
    {
        foreach ($value as $key => $item) {
            if ((! $canViewSpending && in_array((string) $key, self::FINANCIAL_FIELDS, true))
                || (! $canCreateWalkIn && in_array((string) $key, ['hourly_rate', 'peak_adjustment_type', 'peak_adjustment_value'], true))) {
                unset($value[$key]);
                continue;
            }
            if (is_array($item)) {
                $value[$key] = $this->withoutFinancialFields($item, $canCreateWalkIn, $canViewSpending);
            }
        }

        return $value;
    }
}
