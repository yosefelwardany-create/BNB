<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Availability\Models\CalendarBlock;
use App\Domain\Operations\Enums\TaskKind;
use App\Domain\Operations\Enums\TaskPriority;
use App\Domain\Reservations\Enums\ReservationStatus;

/**
 * A sample client account: "Bogota Colombia", ten properties across Bogotá's
 * neighbourhoods. Listing-style names on real streets; the properties
 * themselves are made up. See {@see SampleClientAccountSeeder} for how it is
 * written and what it never sends.
 */
class BogotaColombiaSampleSeeder extends SampleClientAccountSeeder
{
    public const SLUG = 'bogota-colombia';

    /** The window bookings are spread over, in days from today. */
    private const FIRST_DAY = -45;

    private const LAST_DAY = 50;

    /** How long people stay, weighted the way a city calendar looks: mostly short, the odd long one. */
    private const NIGHTS = [2, 2, 2, 3, 3, 3, 3, 4, 4, 4, 5, 5, 6, 7, 7, 9, 12];

    /** Empty nights between two stays, weighted towards short gaps. */
    private const GAPS = [1, 1, 1, 2, 2, 2, 3, 3, 4, 5, 6, 8];

    /** Properties whose inbox needs a guest in the house today. */
    private const IN_HOUSE_TODAY = ['chapinero-studio', 'zona-t-penthouse'];

    protected function slug(): string
    {
        return self::SLUG;
    }

    /** 2: each property has its own calendar, rather than one shared rhythm. */
    protected function version(): int
    {
        return 2;
    }

    protected function accountName(): string
    {
        return 'Bogota Colombia';
    }

    protected function holder(): array
    {
        return [
            'first_name' => 'Bogota',
            'last_name' => 'Colombia',
            'email' => 'bogota.colombia@example.com',
            'phone' => '+57 300 000 1000',
            'bank_name' => 'Bancolombia',
            'bank_account_name' => 'Bogota Colombia',
        ];
    }

    protected function portfolio(): array
    {
        return [
            'name' => 'Bogotá',
            'slug' => 'bogota',
            'description' => 'Short-term rentals across Bogotá.',
            'color' => '#0ea5e9',
        ];
    }

    protected function propertyDefinitions(): array
    {
        return [
            'chapinero-studio' => [
                'name' => 'Modern Studio with City Views in Chapinero Alto',
                'reference' => 'BC-001',
                'type' => 'studio',
                'address' => ['Calle 57 # 4-10', 'Apto 801', '110231'],
                'neighbourhood' => 'Chapinero Alto',
                'coordinates' => [4.6440, -74.0590],
                'bedrooms' => 0, 'bathrooms' => 1, 'beds' => 1, 'occupancy' => 2, 'size' => 38, 'floor' => '8',
                'rate' => 4500, 'cleaning' => 1200, 'deposit' => 10000,
                'summary' => 'A compact modern studio on the eighth floor with views over the city and the eastern hills.',
                'space' => 'Open-plan studio with a queen bed, a sofa, a kitchenette with an induction hob and a desk facing the window.',
                'cameras' => [1, 'Tapo', 'Building entrance to the apartment door: guest entry and exit, guest count against the reservation.'],
                'secrets' => ['door_code' => '381940', 'wifi_network' => 'Studio-801', 'wifi_password' => 'cerros-vista-801'],
                'method' => 'smart_lock',
                'amenities' => ['wifi', 'high_speed_internet', 'hot_water', 'kitchenette', 'refrigerator', 'microwave', 'coffee_maker', 'workspace', 'desk', 'smart_tv', 'elevator', 'smoke_alarm', 'essentials'],
                'bot' => 'Sara',
                'photos' => [7147293, 4740580, 7147288],
            ],
            'zona-g-loft' => [
                'name' => 'Bright 1BR Loft near Zona G Restaurants',
                'reference' => 'BC-002',
                'type' => 'loft',
                'address' => ['Carrera 5 # 69-22', 'Loft 2', '110231'],
                'neighbourhood' => 'Quinta Camacho',
                'coordinates' => [4.6555, -74.0560],
                'bedrooms' => 1, 'bathrooms' => 1, 'beds' => 1, 'occupancy' => 2, 'size' => 55, 'floor' => '2',
                'rate' => 6000, 'cleaning' => 1500, 'deposit' => 12000,
                'summary' => 'A bright loft with tall windows, two blocks from the restaurants of Zona G.',
                'space' => 'Double-height living room, a mezzanine bedroom with a queen bed, a full kitchen and a reading nook.',
                'cameras' => [1, 'Tapo', 'Entrance: guest entry and exit, visitors who are not on the reservation.'],
                'secrets' => ['door_code' => '529061', 'wifi_network' => 'ZonaG-Loft', 'wifi_password' => 'quinta-camacho-22'],
                'method' => 'smart_lock',
                'amenities' => ['wifi', 'hot_water', 'kitchen', 'refrigerator', 'stove', 'oven', 'coffee_maker', 'cooking_basics', 'books', 'tv', 'smoke_alarm', 'essentials'],
                'bot' => 'Tomás',
                'photos' => [7511693, 271624, 3741314],
            ],
            'parque-93' => [
                'name' => 'Stylish 2BR Apartment by Parque 93',
                'reference' => 'BC-003',
                'type' => 'apartment',
                'address' => ['Calle 93B # 13-45', 'Apto 603', '110221'],
                'neighbourhood' => 'Chicó',
                'coordinates' => [4.6766, -74.0480],
                'bedrooms' => 2, 'bathrooms' => 2, 'beds' => 2, 'occupancy' => 4, 'size' => 85, 'floor' => '6',
                'rate' => 9500, 'cleaning' => 2500, 'deposit' => 20000,
                'summary' => 'A two-bedroom, two-bathroom apartment a block from Parque 93, with a balcony and building gym.',
                'space' => 'Living and dining room opening onto a balcony, a full kitchen, a main bedroom with en-suite and a second bedroom.',
                'cameras' => [1, 'Tapo', 'Apartment door: guest count against the reservation, parties, objects being removed.'],
                'secrets' => ['door_code' => '640287', 'wifi_network' => 'Parque93-603', 'wifi_password' => 'chico-balcon-603'],
                'method' => 'smart_lock',
                'amenities' => ['wifi', 'high_speed_internet', 'hot_water', 'kitchen', 'refrigerator', 'oven', 'stove', 'dishwasher', 'cooking_basics', 'dishes_and_cutlery', 'washing_machine', 'balcony', 'gym', 'elevator', 'smart_tv', 'smoke_alarm', 'essentials'],
                'bot' => 'Gabriela',
                'photos' => [6527029, 6207825, 6207945],
            ],
            'zona-t-penthouse' => [
                'name' => 'Penthouse with Rooftop Terrace in Zona T',
                'reference' => 'BC-004',
                'type' => 'apartment',
                'address' => ['Carrera 12 # 83-20', 'PH 1', '110221'],
                'neighbourhood' => 'El Retiro',
                'coordinates' => [4.6670, -74.0530],
                'bedrooms' => 3, 'bathrooms' => 3, 'beds' => 4, 'occupancy' => 6, 'size' => 160, 'floor' => '10',
                'rate' => 22000, 'cleaning' => 5000, 'deposit' => 60000,
                'summary' => 'A three-bedroom penthouse with a private rooftop terrace above the Zona T and Andino.',
                'space' => 'Large living room with a fireplace, a full kitchen, three en-suite bedrooms and a rooftop terrace with a grill and city views.',
                'cameras' => [2, 'Tapo', 'Private lift lobby and terrace access: guest count, parties and noise, visitors.'],
                'secrets' => ['door_code' => '715403', 'wifi_network' => 'ZonaT-PH', 'wifi_password' => 'terraza-andino-10'],
                'method' => 'smart_lock',
                'amenities' => ['wifi', 'high_speed_internet', 'hot_water', 'heating', 'kitchen', 'refrigerator', 'freezer', 'oven', 'stove', 'dishwasher', 'cooking_basics', 'wine_glasses', 'dining_table', 'washing_machine', 'dryer', 'terrace', 'bbq_grill', 'outdoor_furniture', 'smart_tv', 'sound_system', 'elevator', 'garage', 'smoke_alarm', 'fire_extinguisher', 'essentials'],
                'bot' => 'Martín',
                'photos' => [7851904, 3144580, 6585628],
            ],
            'candelaria-house' => [
                'name' => 'Colonial House with Courtyard in La Candelaria',
                'reference' => 'BC-005',
                'type' => 'house',
                'address' => ['Calle 10 # 2-65', null, '111711'],
                'neighbourhood' => 'La Candelaria',
                'coordinates' => [4.5965, -74.0700],
                'bedrooms' => 4, 'bathrooms' => 3, 'beds' => 6, 'occupancy' => 8, 'size' => 230, 'floor' => '1–2',
                'rate' => 15000, 'cleaning' => 4500, 'deposit' => 40000,
                'summary' => 'A restored colonial house around a planted courtyard, a short walk from Plaza de Bolívar and the Gold Museum.',
                'space' => 'Four bedrooms around a central courtyard, a living room with original beams, a full kitchen and a small roof terrace.',
                'cameras' => [3, 'DMS', 'Street door and courtyard: guest entry authorization against the reservation, visitors, deliveries.'],
                'secrets' => ['door_code' => '268514', 'wifi_network' => 'CasaCandelaria', 'wifi_password' => 'patio-bolivar-10'],
                'method' => 'smart_lock',
                'amenities' => ['wifi', 'hot_water', 'heating', 'kitchen', 'refrigerator', 'oven', 'stove', 'cooking_basics', 'dishes_and_cutlery', 'dining_table', 'washing_machine', 'patio', 'terrace', 'books', 'smart_tv', 'smoke_alarm', 'first_aid_kit', 'essentials'],
                'bot' => 'Rafael',
                'photos' => [3075974, 4946986, 6312353],
            ],
            'usaquen-house' => [
                'name' => 'Family Home with Garden in Usaquén',
                'reference' => 'BC-006',
                'type' => 'house',
                'address' => ['Carrera 6 # 119-24', null, '110111'],
                'neighbourhood' => 'Usaquén',
                'coordinates' => [4.6950, -74.0310],
                'bedrooms' => 4, 'bathrooms' => 3, 'beds' => 5, 'occupancy' => 8, 'size' => 260, 'floor' => '1–2',
                'rate' => 18000, 'cleaning' => 5000, 'deposit' => 45000,
                'summary' => 'A four-bedroom family house with a garden, near the Sunday flea market and plaza of Usaquén.',
                'space' => 'Living and dining rooms opening onto the garden, a full kitchen, four bedrooms upstairs and parking for two cars.',
                'cameras' => [2, 'Tapo', 'Gate and garage: guest count, cars against the reservation, parties.'],
                'secrets' => ['door_code' => '903175', 'wifi_network' => 'Usaquen-Casa', 'wifi_password' => 'jardin-mercado-119'],
                'method' => 'smart_lock',
                'amenities' => ['wifi', 'hot_water', 'heating', 'kitchen', 'refrigerator', 'freezer', 'oven', 'stove', 'dishwasher', 'cooking_basics', 'dining_table', 'high_chair', 'crib', 'washing_machine', 'dryer', 'garden', 'outdoor_furniture', 'bbq_grill', 'free_parking', 'smart_tv', 'board_games', 'smoke_alarm', 'first_aid_kit', 'essentials'],
                'bot' => 'Paula',
                'photos' => [7587880, 6588599, 3933240, 7061339],
            ],
            'el-virrey' => [
                'name' => 'Quiet 2BR Apartment near Parque El Virrey',
                'reference' => 'BC-007',
                'type' => 'apartment',
                'address' => ['Calle 87 # 15-30', 'Apto 402', '110221'],
                'neighbourhood' => 'El Virrey',
                'coordinates' => [4.6720, -74.0545],
                'bedrooms' => 2, 'bathrooms' => 2, 'beds' => 3, 'occupancy' => 5, 'size' => 90, 'floor' => '4',
                'rate' => 8500, 'cleaning' => 2500, 'deposit' => 20000,
                'summary' => 'A calm two-bedroom apartment facing the trees of Parque El Virrey, ten minutes\' walk from the Zona T.',
                'space' => 'Living room with a sofa bed, a full kitchen, a main bedroom with en-suite and a twin bedroom.',
                'cameras' => [1, 'Tapo', 'Apartment door: guest entry and exit, guest count against the reservation.'],
                'secrets' => ['door_code' => '476328', 'wifi_network' => 'Virrey-402', 'wifi_password' => 'parque-arboles-87'],
                'method' => 'smart_lock',
                'amenities' => ['wifi', 'hot_water', 'kitchen', 'refrigerator', 'oven', 'stove', 'cooking_basics', 'dishes_and_cutlery', 'washing_machine', 'elevator', 'smart_tv', 'workspace', 'smoke_alarm', 'essentials'],
                'bot' => 'Laura',
                'photos' => [6934189, 4440214, 6032225],
            ],
            'teusaquillo' => [
                'name' => 'Art Deco Apartment in Teusaquillo',
                'reference' => 'BC-008',
                'type' => 'apartment',
                'address' => ['Calle 39 # 17-12', 'Apto 201', '111311'],
                'neighbourhood' => 'Teusaquillo',
                'coordinates' => [4.6280, -74.0710],
                'bedrooms' => 2, 'bathrooms' => 1, 'beds' => 2, 'occupancy' => 4, 'size' => 80, 'floor' => '2',
                'rate' => 6500, 'cleaning' => 2000, 'deposit' => 15000,
                'summary' => 'A two-bedroom apartment in a 1940s brick building in Teusaquillo, near Parque Simón Bolívar.',
                'space' => 'High-ceilinged living room with original wooden floors, a kitchen with a breakfast bar and two bedrooms.',
                'cameras' => [1, 'Tapo', 'Building door to the apartment: guest entry and exit, unauthorized visitors.'],
                'secrets' => ['door_code' => '152739', 'wifi_network' => 'Teusaquillo-201', 'wifi_password' => 'ladrillo-deco-39'],
                'method' => 'smart_lock',
                'amenities' => ['wifi', 'hot_water', 'kitchen', 'refrigerator', 'stove', 'coffee_maker', 'cooking_basics', 'dishes_and_cutlery', 'washing_machine', 'tv', 'books', 'smoke_alarm', 'essentials'],
                'bot' => 'Felipe',
                'photos' => [6447384, 7060814, 6207825],
            ],
            'las-aguas-suite' => [
                'name' => 'Boutique Suite near Universidad de los Andes',
                'reference' => 'BC-009',
                'type' => 'serviced_apartment',
                'address' => ['Carrera 3 # 18-40', 'Suite 5', '111711'],
                'neighbourhood' => 'Las Aguas',
                'coordinates' => [4.6020, -74.0680],
                'bedrooms' => 1, 'bathrooms' => 1, 'beds' => 1, 'occupancy' => 2, 'size' => 42, 'floor' => '5',
                'rate' => 5000, 'cleaning' => 1200, 'deposit' => 10000,
                'summary' => 'A one-bedroom serviced suite by the Eje Ambiental, steps from the Universidad de los Andes and the Monserrate cable car.',
                'space' => 'Bedroom with a queen bed, a lounge with a sofa, a kitchenette and a work desk; weekly cleaning included on long stays.',
                'cameras' => [1, 'Tapo', 'Suite door: guest entry and exit, guest count against the reservation.'],
                'secrets' => ['door_code' => '847216', 'wifi_network' => 'LasAguas-5', 'wifi_password' => 'monserrate-eje-18'],
                'method' => 'smart_lock',
                'amenities' => ['wifi', 'high_speed_internet', 'hot_water', 'kitchenette', 'refrigerator', 'microwave', 'kettle', 'workspace', 'desk', 'smart_tv', 'elevator', 'smoke_alarm', 'essentials'],
                'bot' => 'Juliana',
                'photos' => [6492403, 6492390, 6492402],
            ],
            'rosales-loft' => [
                'name' => 'Eco Loft with Mountain Views in Rosales',
                'reference' => 'BC-010',
                'type' => 'loft',
                'address' => ['Carrera 1 Este # 70-15', null, '110231'],
                'neighbourhood' => 'Rosales',
                'coordinates' => [4.6580, -74.0500],
                'bedrooms' => 2, 'bathrooms' => 2, 'beds' => 2, 'occupancy' => 4, 'size' => 110, 'floor' => '1–2',
                'rate' => 13000, 'cleaning' => 3500, 'deposit' => 30000,
                'summary' => 'A two-bedroom loft at the foot of the eastern hills, with solar hot water, a garden and mountain views.',
                'space' => 'Open living area with floor-to-ceiling windows, a full kitchen, two en-suite bedrooms and a garden with a fire pit.',
                'cameras' => [2, 'Tapo', 'Gate and garden: guest entry and exit, visitors, parties and noise.'],
                'secrets' => ['door_code' => '390652', 'wifi_network' => 'Rosales-Eco', 'wifi_password' => 'cerros-solar-70'],
                'method' => 'smart_lock',
                'amenities' => ['wifi', 'hot_water', 'heating', 'kitchen', 'refrigerator', 'oven', 'stove', 'cooking_basics', 'dishes_and_cutlery', 'washing_machine', 'garden', 'fire_pit', 'outdoor_furniture', 'free_parking', 'smart_tv', 'smoke_alarm', 'essentials'],
                'bot' => 'Andrea',
                'photos' => [7174109, 6585628, 3933240],
            ],
        ];
    }

    protected function guests(): array
    {
        return [
            ['Camila', 'Rojas', 'CO', '+57 300 000 2001', 'es'],
            ['Sebastián', 'Mejía', 'CO', '+57 300 000 2002', 'es'],
            ['Olivia', 'Brooks', 'US', '+1 312 555 2003', 'en'],
            ['Mateo', 'Álvarez', 'AR', '+54 11 5555 2004', 'es'],
            ['Lena', 'Schmidt', 'DE', '+49 151 5550 2005', 'en'],
            ['Fernanda', 'Ruiz', 'MX', '+52 55 5555 2006', 'es'],
            ['James', 'Walker', 'GB', '+44 7700 900207', 'en'],
            ['Mariana', 'Ospina', 'CO', '+57 300 000 2008', 'es'],
            ['Luca', 'Bianchi', 'IT', '+39 333 555 2009', 'en'],
            ['Beatriz', 'Santos', 'BR', '+55 21 95555 2010', 'en'],
            ['Diego', 'Castro', 'CO', '+57 300 000 2011', 'es'],
            ['Chloé', 'Martin', 'FR', '+33 6 55 55 20 12', 'en'],
            ['Joaquín', 'Silva', 'CL', '+56 9 5555 2013', 'es'],
            ['Grace', 'Lee', 'US', '+1 646 555 2014', 'en'],
            ['Pablo', 'Navarro', 'ES', '+34 600 555 015', 'es'],
            ['Ana', 'Quintero', 'CO', '+57 300 000 2016', 'es'],
            ['Noah', 'Peters', 'CA', '+1 416 555 2017', 'en'],
            ['Valentina', 'Paredes', 'PE', '+51 900 555 018', 'es'],
            ['Emma', 'Jansen', 'NL', '+31 6 5555 2019', 'en'],
            ['Ricardo', 'Mora', 'CO', '+57 300 000 2020', 'es'],
            ['Isabella', 'Gómez', 'CO', '+57 300 000 2021', 'es'],
            ['Thomas', 'Müller', 'DE', '+49 151 5550 2022', 'en'],
            ['Sofía', 'Herrera', 'MX', '+52 55 5555 2023', 'es'],
            ['Daniel', 'Cohen', 'US', '+1 917 555 2024', 'en'],
            ['Laura', 'Restrepo', 'CO', '+57 300 000 2025', 'es'],
            ['Hugo', 'Lefèvre', 'FR', '+33 6 55 55 20 26', 'en'],
            ['Martina', 'López', 'AR', '+54 11 5555 2027', 'es'],
            ['William', 'Taylor', 'GB', '+44 7700 900228', 'en'],
            ['Gabriela', 'Pinto', 'BR', '+55 11 95555 2029', 'en'],
            ['Andrés', 'Cárdenas', 'CO', '+57 300 000 2030', 'es'],
            ['Hannah', 'Fischer', 'AT', '+43 660 555 2031', 'en'],
            ['Javier', 'Ortega', 'ES', '+34 600 555 032', 'es'],
            ['Emily', 'Clarke', 'AU', '+61 400 555 033', 'en'],
            ['Tomás', 'Vargas', 'CL', '+56 9 5555 2034', 'es'],
            ['Sara', 'Lindqvist', 'SE', '+46 70 555 2035', 'en'],
            ['Felipe', 'Arango', 'CO', '+57 300 000 2036', 'es'],
            ['Michael', 'Nguyen', 'US', '+1 213 555 2037', 'en'],
            ['Lucía', 'Fernández', 'UY', '+598 99 555 038', 'es'],
            ['Jan', 'de Vries', 'NL', '+31 6 5555 2039', 'en'],
            ['Catalina', 'Duque', 'CO', '+57 300 000 2040', 'es'],
            ['Ethan', 'Murphy', 'IE', '+353 85 555 2041', 'en'],
            ['Renata', 'Campos', 'PE', '+51 900 555 042', 'es'],
            ['Marco', 'Rossi', 'IT', '+39 333 555 2043', 'en'],
            ['Juliana', 'Salazar', 'CO', '+57 300 000 2044', 'es'],
            ['Sophie', 'Dubois', 'BE', '+32 470 55 20 45', 'en'],
            ['Nicolás', 'Rincón', 'CO', '+57 300 000 2046', 'es'],
            ['Ava', 'Thompson', 'CA', '+1 604 555 2047', 'en'],
            ['Alejandro', 'Guerrero', 'EC', '+593 99 555 2048', 'es'],
            ['Yuki', 'Tanaka', 'JP', '+81 90 5555 2049', 'en'],
            ['Daniela', 'Torres', 'CO', '+57 300 000 2050', 'es'],
            ['Oliver', 'Hansen', 'DK', '+45 20 55 20 51', 'en'],
            ['Carolina', 'Méndez', 'CR', '+506 8555 2052', 'es'],
            ['Lucas', 'Oliveira', 'BR', '+55 21 95555 2053', 'en'],
            ['Paula', 'Jiménez', 'CO', '+57 300 000 2054', 'es'],
            ['Benjamin', 'Wright', 'US', '+1 305 555 2055', 'en'],
            ['Elena', 'Petrova', 'BG', '+359 88 555 2056', 'en'],
            ['Santiago', 'Molina', 'CO', '+57 300 000 2057', 'es'],
            ['Charlotte', 'Evans', 'GB', '+44 7700 900258', 'en'],
            ['Manuela', 'Zapata', 'CO', '+57 300 000 2059', 'es'],
            ['Ryan', 'Patel', 'US', '+1 415 555 2060', 'en'],
        ];
    }

    /**
     * Each property gets its own calendar.
     *
     * A pattern shared by every property, shifted by a day or two, put four
     * guests on one arrival day with the same length of stay, which no real
     * portfolio does. So each property draws its stays from its own seeded
     * sequence instead: lengths, gaps and guests vary, and building the
     * account again gives the same calendar. Stays keep clear of the
     * property's blocked dates and its cancelled booking.
     */
    protected function bookingPlan(): array
    {
        $plan = [];
        $guestCount = count($this->guests());
        $blocked = $this->blockedWindows();

        foreach ($this->propertyDefinitions() as $key => $definition) {
            mt_srand(crc32($this->slug().'/'.$key));

            $stays = $this->staysFor($key, $blocked[$key] ?? []);
            $lastGuest = -1;

            foreach ($stays as [$checkIn, $nights]) {
                $checkOut = $checkIn + $nights;

                $status = match (true) {
                    $checkOut <= 0 => ReservationStatus::CheckedOut,
                    $checkIn < 0 => ReservationStatus::CheckedIn,
                    default => ReservationStatus::Confirmed,
                };

                do {
                    $guest = mt_rand(0, $guestCount - 1);
                } while ($guest === $lastGuest);
                $lastGuest = $guest;

                $occupancy = $definition['occupancy'];
                $party = [1, 2, 2, 2, 2, min(3, $occupancy), min(4, $occupancy), $occupancy];
                $adults = $party[mt_rand(0, count($party) - 1)];

                $plan[] = [$key, $guest, $checkIn, $nights, $adults, $status];
            }
        }

        // Leave the generator unpredictable for whatever runs next.
        mt_srand();

        return $plan;
    }

    /**
     * One property's stays, as [check-in day, nights], earliest first.
     *
     * Built outwards from a stay around today, so the properties whose inbox
     * needs a guest in the house have one, and the rest start on a day of
     * their own.
     *
     * @param  list<array{0: int, 1: int}>  $blocked  [first day, day after the last]
     * @return list<array{0: int, 1: int}>
     */
    private function staysFor(string $key, array $blocked): array
    {
        $nights = fn (): int => self::NIGHTS[mt_rand(0, count(self::NIGHTS) - 1)];
        $gap = fn (): int => self::GAPS[mt_rand(0, count(self::GAPS) - 1)];
        $clashes = function (int $from, int $to) use ($blocked): bool {
            foreach ($blocked as [$start, $end]) {
                if ($from < $end && $to > $start) {
                    return true;
                }
            }

            return false;
        };

        if (in_array($key, self::IN_HOUSE_TODAY, true)) {
            $length = mt_rand(3, 6);
            $anchor = [-mt_rand(1, $length - 1), $length];
        } else {
            $length = $nights();
            $anchor = [mt_rand(-3, 6), $length];
        }

        $stays = [$anchor];

        // Forwards from the anchor.
        $cursor = $anchor[0] + $anchor[1] + $gap();
        while ($cursor < self::LAST_DAY) {
            $length = $nights();
            if ($clashes($cursor, $cursor + $length)) {
                $cursor++;

                continue;
            }
            $stays[] = [$cursor, $length];
            $cursor += $length + $gap();
        }

        // Backwards from the anchor.
        $cursor = $anchor[0] - $gap();
        while ($cursor > self::FIRST_DAY) {
            $length = $nights();
            if ($clashes($cursor - $length, $cursor)) {
                $cursor--;

                continue;
            }
            $stays[] = [$cursor - $length, $length];
            $cursor -= $length + $gap();
        }

        usort($stays, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $stays;
    }

    /**
     * Dates a property's bookings must leave free: its calendar blocks and
     * the stay that was booked and then cancelled.
     *
     * @return array<string, list<array{0: int, 1: int}>>
     */
    private function blockedWindows(): array
    {
        $windows = [];

        foreach ($this->calendarBlocks() as [$key, , $from, $to]) {
            $windows[$key][] = [$from, $to];
        }

        $cancellation = $this->cancellation();
        if ($cancellation !== null) {
            [$key, , $offset, $nights] = $cancellation;
            $windows[$key][] = [$offset, $offset + $nights];
        }

        return $windows;
    }

    protected function cancellation(): ?array
    {
        return ['parque-93', 4, 40, 3, 2];
    }

    protected function calendarBlocks(): array
    {
        return [
            ['zona-t-penthouse', CalendarBlock::KIND_OWNER_STAY, 31, 35, 'Owner stay', 'The owner is using the penthouse.'],
            ['candelaria-house', CalendarBlock::KIND_MAINTENANCE, 32, 34, 'Courtyard waterproofing', 'Roofer booked for the courtyard gutters.'],
            ['usaquen-house', CalendarBlock::KIND_MAINTENANCE, 30, 31, 'Garden and gate maintenance', 'Gardener and gate motor service.'],
            ['teusaquillo', CalendarBlock::KIND_RENOVATION, 33, 38, 'Bathroom renovation', 'New shower and tiling.'],
        ];
    }

    protected function conversations(): array
    {
        return [
            ['chapinero-studio', ReservationStatus::CheckedIn, [
                ['in', 'Hola, ya llegamos. ¿Cuál es la clave del wifi?', 7],
                ['out', '¡Bienvenidos! La red es Studio-801 y la clave está en la tarjeta junto al televisor.', 6],
                ['in', 'Perfecto, gracias. ¿Hay supermercado cerca?', 1],
            ], null],
            ['zona-t-penthouse', ReservationStatus::CheckedIn, [
                ['in', 'Hi! We would like to have a small dinner on the terrace with four friends tonight. Is that OK?', 4],
            ], 'Reservation is for 6 and the penthouse is at capacity. Visitors are not allowed: check the lobby camera tonight.'],
            ['parque-93', ReservationStatus::Confirmed, [
                ['in', 'We arrive at 6 am on a red-eye flight. Is an early check-in possible?', 26],
                ['out', 'We will do our best: the clean usually finishes by 11:00. You can leave your bags with the building concierge before then.', 24],
            ], 'Early arrival: ask the cleaner to start the turnover first thing.'],
            ['usaquen-house', ReservationStatus::Confirmed, [
                ['in', 'Buenas, venimos con dos niños pequeños. ¿Tienen cuna y silla de comer?', 30],
                ['out', 'Sí, la casa tiene cuna y silla de comer. Las dejamos listas antes de su llegada.', 28],
                ['in', '¡Excelente! ¿Y hay parqueadero para dos carros?', 3],
            ], null],
            ['las-aguas-suite', ReservationStatus::Confirmed, [
                ['in', 'Is the suite quiet at night? I need to work early.', 12],
            ], null],
        ];
    }

    protected function tasks(): array
    {
        return [
            [
                'property' => 'zona-t-penthouse', 'kind' => TaskKind::Maintenance, 'priority' => TaskPriority::High,
                'title' => 'Terrace gas grill not igniting',
                'description' => 'Guest reported the grill clicks but does not light. Check the gas cylinder and igniter.',
                'start' => [1, 10], 'due' => [1, 14], 'minutes' => 60,
            ],
            [
                'property' => 'candelaria-house', 'kind' => TaskKind::Maintenance, 'priority' => TaskPriority::Urgent,
                'title' => 'Courtyard camera not recording',
                'description' => 'Live view works but there are no recordings since Monday. Possibly a full memory card.',
                'start' => [-1, 9], 'due' => [-1, 17], 'minutes' => 45,
            ],
            [
                'property' => 'usaquen-house', 'kind' => TaskKind::Inspection, 'priority' => TaskPriority::Normal,
                'title' => 'Quarterly camera and alarm check',
                'start' => [4, 10], 'due' => [4, 13], 'minutes' => 60, 'checklist' => 'Camera check',
            ],
            [
                'property' => 'el-virrey', 'kind' => TaskKind::Maintenance, 'priority' => TaskPriority::Normal,
                'title' => 'Digital lock battery at 15%',
                'description' => 'Replace the AA batteries before the next arrival.',
                'start' => [2, 15], 'due' => [3, 12], 'minutes' => 20,
            ],
            [
                'property' => 'teusaquillo', 'kind' => TaskKind::Maintenance, 'priority' => TaskPriority::Low,
                'title' => 'Squeaky wooden floor in the hallway',
                'description' => 'Not urgent: fix during the bathroom renovation block.',
                'start' => [33, 10], 'due' => [37, 17], 'minutes' => 120,
            ],
            [
                'property' => 'rosales-loft', 'kind' => TaskKind::Cleaning, 'priority' => TaskPriority::Normal,
                'title' => 'Deep clean and window wash',
                'start' => [-5, 9], 'due' => [-5, 16], 'minutes' => 240, 'checklist' => 'Turnover clean', 'complete' => true,
            ],
        ];
    }

    protected function expenses(): array
    {
        return [
            [
                'property' => 'zona-t-penthouse', 'days_ago' => 16, 'category' => 'maintenance',
                'description' => 'Terrace furniture cushions replacement', 'amount' => 18000,
                'billable_to' => 'owner', 'notes' => 'Six outdoor cushions, weather-proof fabric.', 'approve' => true,
            ],
            [
                'property' => 'usaquen-house', 'days_ago' => 11, 'category' => 'gardening',
                'description' => 'Monthly garden maintenance', 'amount' => 7000,
                'billable_to' => 'owner', 'approve' => true,
            ],
            [
                'property' => 'chapinero-studio', 'days_ago' => 8, 'category' => 'supplies',
                'description' => 'Restock: coffee, toiletries, cleaning products', 'amount' => 3500,
                'billable_to' => 'management', 'approve' => true,
            ],
            [
                'property' => 'candelaria-house', 'days_ago' => 4, 'category' => 'maintenance',
                'description' => 'Plumber: courtyard drain', 'amount' => 9500,
                'billable_to' => 'owner', 'notes' => 'Waiting for the invoice before approval.', 'approve' => false,
            ],
        ];
    }

    protected function reviews(): array
    {
        return [
            ['chapinero-studio', 5, 'Great view, great host', 'Small but perfectly designed. The view at night is beautiful.', true],
            ['zona-g-loft', 5, 'Loved the loft', 'Bright, clean and two minutes from amazing restaurants.', true],
            ['parque-93', 4, 'Very comfortable', 'Great location by the park. The building lift was slow at peak times.', false],
            ['zona-t-penthouse', 5, 'Terraza increíble', 'El mejor apartamento en el que nos hemos quedado en Bogotá. La terraza es espectacular.', true],
            ['candelaria-house', 5, 'Historic and charming', 'Walking distance to all the museums. The courtyard is magical.', true],
            ['usaquen-house', 5, 'Perfecta para familias', 'Casa amplia, jardín para los niños y muy cerca del mercado de pulgas.', false],
            ['el-virrey', 4, 'Quiet and well located', 'Exactly as described. Nice walks in the park every morning.', true],
            ['teusaquillo', 4, 'Beautiful old building', 'Lots of character. Hot water took a minute to arrive.', false],
            ['las-aguas-suite', 5, 'Ideal for a work trip', 'Fast wifi, good desk and easy to reach the university.', true],
            ['rosales-loft', 5, 'Mountains at the doorstep', 'Peaceful garden, great fire pit evenings and close to the restaurants of Rosales.', true],
        ];
    }
}
