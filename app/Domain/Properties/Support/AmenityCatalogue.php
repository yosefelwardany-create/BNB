<?php

declare(strict_types=1);

namespace App\Domain\Properties\Support;

/**
 * The platform amenity catalogue.
 *
 * Shared across every organization so that channel adapters have a stable set
 * of keys to map onto, and so search and reporting can compare like with like.
 * Organizations may add their own amenities, but only these travel to
 * channels.
 */
final class AmenityCatalogue
{
    /**
     * key => [name, category, highlight]
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function all(): array
    {
        return [
            // Essentials
            'wifi' => ['Wi-Fi', 'essentials', true],
            'heating' => ['Heating', 'essentials', true],
            'air_conditioning' => ['Air conditioning', 'essentials', true],
            'hot_water' => ['Hot water', 'essentials', false],
            'essentials' => ['Towels and bed linen', 'essentials', false],
            'hangers' => ['Hangers', 'essentials', false],
            'iron' => ['Iron', 'essentials', false],
            'hair_dryer' => ['Hair dryer', 'essentials', false],
            'shampoo' => ['Shampoo', 'essentials', false],
            'bed_linen' => ['Fresh bed linen', 'essentials', false],
            'extra_pillows' => ['Extra pillows and blankets', 'essentials', false],
            'room_darkening_blinds' => ['Blackout blinds', 'essentials', false],
            'cleaning_products' => ['Cleaning products', 'essentials', false],

            // Kitchen and dining
            'kitchen' => ['Kitchen', 'kitchen', true],
            'kitchenette' => ['Kitchenette', 'kitchen', false],
            'refrigerator' => ['Refrigerator', 'kitchen', false],
            'freezer' => ['Freezer', 'kitchen', false],
            'microwave' => ['Microwave', 'kitchen', false],
            'oven' => ['Oven', 'kitchen', false],
            'stove' => ['Hob', 'kitchen', false],
            'dishwasher' => ['Dishwasher', 'kitchen', false],
            'coffee_maker' => ['Coffee maker', 'kitchen', false],
            'kettle' => ['Kettle', 'kitchen', false],
            'toaster' => ['Toaster', 'kitchen', false],
            'cooking_basics' => ['Cooking basics', 'kitchen', false],
            'dishes_and_cutlery' => ['Dishes and cutlery', 'kitchen', false],
            'wine_glasses' => ['Wine glasses', 'kitchen', false],
            'dining_table' => ['Dining table', 'kitchen', false],
            'high_chair' => ['High chair', 'kitchen', false],

            // Bathroom and laundry
            'bathtub' => ['Bathtub', 'bathroom', false],
            'shower' => ['Shower', 'bathroom', false],
            'washing_machine' => ['Washing machine', 'laundry', true],
            'dryer' => ['Tumble dryer', 'laundry', false],
            'drying_rack' => ['Drying rack', 'laundry', false],

            // Entertainment and work
            'tv' => ['TV', 'entertainment', false],
            'smart_tv' => ['Smart TV', 'entertainment', false],
            'streaming_services' => ['Streaming services', 'entertainment', false],
            'sound_system' => ['Sound system', 'entertainment', false],
            'books' => ['Books and reading material', 'entertainment', false],
            'board_games' => ['Board games', 'entertainment', false],
            'workspace' => ['Dedicated workspace', 'work', true],
            'desk' => ['Desk', 'work', false],
            'high_speed_internet' => ['High-speed internet', 'work', true],
            'printer' => ['Printer', 'work', false],

            // Outdoor and leisure
            'pool' => ['Swimming pool', 'outdoor', true],
            'private_pool' => ['Private pool', 'outdoor', true],
            'heated_pool' => ['Heated pool', 'outdoor', false],
            'hot_tub' => ['Hot tub', 'outdoor', true],
            'sauna' => ['Sauna', 'outdoor', false],
            'garden' => ['Garden', 'outdoor', false],
            'balcony' => ['Balcony', 'outdoor', false],
            'terrace' => ['Terrace', 'outdoor', false],
            'patio' => ['Patio', 'outdoor', false],
            'bbq_grill' => ['Barbecue', 'outdoor', false],
            'outdoor_furniture' => ['Outdoor furniture', 'outdoor', false],
            'fire_pit' => ['Fire pit', 'outdoor', false],
            'beach_access' => ['Beach access', 'outdoor', true],
            'ski_in_ski_out' => ['Ski-in / ski-out', 'outdoor', true],
            'gym' => ['Gym', 'outdoor', true],
            'bicycles' => ['Bicycles', 'outdoor', false],

            // Parking and access
            'free_parking' => ['Free parking on site', 'parking', true],
            'paid_parking' => ['Paid parking', 'parking', false],
            'street_parking' => ['Street parking', 'parking', false],
            'garage' => ['Garage', 'parking', false],
            'ev_charger' => ['EV charger', 'parking', false],
            'elevator' => ['Lift', 'accessibility', false],
            'step_free_access' => ['Step-free access', 'accessibility', false],
            'wide_doorways' => ['Wide doorways', 'accessibility', false],
            'accessible_bathroom' => ['Accessible bathroom', 'accessibility', false],
            'ground_floor' => ['Ground floor', 'accessibility', false],

            // Safety
            'smoke_alarm' => ['Smoke alarm', 'safety', false],
            'carbon_monoxide_alarm' => ['Carbon monoxide alarm', 'safety', false],
            'fire_extinguisher' => ['Fire extinguisher', 'safety', false],
            'first_aid_kit' => ['First aid kit', 'safety', false],
            'security_cameras_exterior' => ['Exterior security cameras', 'safety', false],
            'safe' => ['Safe', 'safety', false],

            // Family
            'crib' => ['Cot', 'family', false],
            'pack_n_play' => ['Travel cot', 'family', false],
            'baby_bath' => ['Baby bath', 'family', false],
            'changing_table' => ['Changing table', 'family', false],
            'baby_gates' => ['Stair gates', 'family', false],
            'children_books_toys' => ['Children\'s books and toys', 'family', false],

            // Services and access
            'self_check_in' => ['Self check-in', 'services', true],
            'smart_lock' => ['Smart lock', 'services', false],
            'lockbox' => ['Lockbox', 'services', false],
            'keypad' => ['Keypad', 'services', false],
            'doorman' => ['Doorman', 'services', false],
            'concierge' => ['Concierge', 'services', false],
            'housekeeping' => ['Housekeeping', 'services', false],
            'breakfast' => ['Breakfast', 'services', false],
            'luggage_drop_off' => ['Luggage drop-off', 'services', false],
            'long_term_stays' => ['Long-term stays allowed', 'services', false],
            'pets_allowed' => ['Pets allowed', 'policies', true],
            'smoking_allowed' => ['Smoking allowed', 'policies', false],
            'events_allowed' => ['Events allowed', 'policies', false],
        ];
    }

    /**
     * @return list<string>
     */
    public static function categories(): array
    {
        return array_values(array_unique(array_map(
            fn (array $a): string => $a[1],
            self::all(),
        )));
    }
}
