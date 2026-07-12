<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GiftIdeaFeature;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GiftIdeaFeature>
 */
class GiftIdeaFeatureFactory extends Factory
{
    protected $model = GiftIdeaFeature::class;

    public function definition(): array
    {
        $shop = $this->faker->randomNumber(8);
        $item = $this->faker->randomNumber(8);

        return [
            'source_product_id' => "{$shop}_{$item}",
            'name' => ucwords($this->faker->words(3, true)),
            'image_url' => $this->faker->imageUrl(),
            'offer_link' => 'https://s.shopee.sg/'.$this->faker->lexify('??????'),
            'product_link' => "https://shopee.sg/product/{$shop}/{$item}",
            'price' => $this->faker->randomFloat(2, 3, 40),
            'currency' => 'SGD',
            'shop_name' => $this->faker->company(),
            'ip_flagged' => false,
            'sort' => 0,
            'created_by' => null,
        ];
    }
}
