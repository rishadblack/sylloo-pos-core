<?php

namespace Rishadblack\SyllooPosCore\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Rishadblack\SyllooPosCore\Models\Setting;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    /**
     * @var class-string<Setting>
     */
    protected $model = Setting::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parameter' => 'test_'.fake()->unique()->word(),
            'value' => fake()->word(),
        ];
    }
}
