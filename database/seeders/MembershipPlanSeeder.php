<?php

namespace Database\Seeders;

use App\Models\MembershipPlan;
use Illuminate\Database\Seeder;

class MembershipPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Free',
                'description' => 'No discount — standard hourly rates.',
                'price' => 0,
                'discount_percent' => 0,
                'duration_days' => 365,
                'status' => 'active',
            ],
            [
                'name' => 'Silver',
                'description' => '10% off every court booking.',
                'price' => 199,
                'discount_percent' => 10,
                'duration_days' => 30,
                'status' => 'active',
            ],
            [
                'name' => 'Gold',
                'description' => '20% off every court booking.',
                'price' => 349,
                'discount_percent' => 20,
                'duration_days' => 30,
                'status' => 'active',
            ],
        ];

        foreach ($plans as $plan) {
            MembershipPlan::updateOrCreate(['name' => $plan['name']], $plan);
        }
    }
}
