<?php

namespace Database\Seeders;

use App\Models\Equipment;
use Illuminate\Database\Seeder;

class EquipmentSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            [
                'name' => 'Padel Racket',
                'category' => 'padel',
                'price' => 50,
                'stock_total' => 6,
                'status' => 'active',
            ],
            [
                'name' => 'Pickleball Paddle',
                'category' => 'pickleball',
                'price' => 20,
                'stock_total' => 10,
                'status' => 'active',
            ],
            [
                'name' => 'Pickleball Balls (3-pack)',
                'category' => 'pickleball',
                'price' => 60,
                'stock_total' => 3,
                'status' => 'active',
            ],
        ];

        foreach ($items as $item) {
            Equipment::updateOrCreate(['name' => $item['name']], $item);
        }
    }
}