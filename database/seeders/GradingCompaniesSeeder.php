<?php

namespace Database\Seeders;

use App\Models\GradingCompany;
use Illuminate\Database\Seeder;

/**
 * Seeds the grading services (§4.3). Idempotent — keyed on slug.
 */
class GradingCompaniesSeeder extends Seeder
{
    public function run(): void
    {
        $companies = [
            ['slug' => 'psa', 'name' => 'PSA', 'scale_max' => 10, 'supports_half_grades' => false, 'supports_subgrades' => false, 'supports_pristine_black_label' => false],
            ['slug' => 'bgs', 'name' => 'Beckett (BGS)', 'scale_max' => 10, 'supports_half_grades' => true, 'supports_subgrades' => true, 'supports_pristine_black_label' => true],
            ['slug' => 'cgc', 'name' => 'CGC', 'scale_max' => 10, 'supports_half_grades' => true, 'supports_subgrades' => true, 'supports_pristine_black_label' => true],
            ['slug' => 'sgc', 'name' => 'SGC', 'scale_max' => 10, 'supports_half_grades' => true, 'supports_subgrades' => false, 'supports_pristine_black_label' => false],
            ['slug' => 'tag', 'name' => 'TAG Grading', 'scale_max' => 10, 'supports_half_grades' => true, 'supports_subgrades' => true, 'supports_pristine_black_label' => false],
            ['slug' => 'ace', 'name' => 'ACE Grading', 'scale_max' => 10, 'supports_half_grades' => true, 'supports_subgrades' => false, 'supports_pristine_black_label' => false],
        ];

        foreach ($companies as $company) {
            GradingCompany::updateOrCreate(['slug' => $company['slug']], $company);
        }
    }
}
