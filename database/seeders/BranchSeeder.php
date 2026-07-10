<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        // نفس BRANCHES في التصميم
        $branches = [
            ['name' => 'الفرع الرئيسي — جدة', 'city' => 'جدة', 'phone' => '012 000 0000'],
            ['name' => 'فرع الرياض', 'city' => 'الرياض', 'phone' => '011 000 0000'],
            ['name' => 'فرع الدمام', 'city' => 'الدمام', 'phone' => '013 000 0000'],
        ];

        foreach ($branches as $b) {
            Branch::updateOrCreate(['name' => $b['name']], $b);
        }
    }
}
