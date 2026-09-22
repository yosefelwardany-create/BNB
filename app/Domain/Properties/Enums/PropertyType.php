<?php

declare(strict_types=1);

namespace App\Domain\Properties\Enums;

/**
 * The kind of building or accommodation.
 *
 * Channels each use their own vocabulary; adapters map these onto it. Keeping
 * our own list means adding a channel never forces a change to the property
 * records themselves.
 */
enum PropertyType: string
{
    case Apartment = 'apartment';
    case House = 'house';
    case Villa = 'villa';
    case Townhouse = 'townhouse';
    case Condominium = 'condominium';
    case Studio = 'studio';
    case Loft = 'loft';
    case Cabin = 'cabin';
    case Chalet = 'chalet';
    case Cottage = 'cottage';
    case Bungalow = 'bungalow';
    case ServicedApartment = 'serviced_apartment';
    case Aparthotel = 'aparthotel';
    case BoutiqueHotel = 'boutique_hotel';
    case HotelRoom = 'hotel_room';
    case Guesthouse = 'guesthouse';
    case BedAndBreakfast = 'bed_and_breakfast';
    case Hostel = 'hostel';
    case Resort = 'resort';
    case Farmstay = 'farmstay';
    case Boat = 'boat';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BedAndBreakfast => 'Bed & breakfast',
            self::ServicedApartment => 'Serviced apartment',
            self::BoutiqueHotel => 'Boutique hotel',
            self::HotelRoom => 'Hotel room',
            default => ucfirst(str_replace('_', ' ', $this->value)),
        };
    }

    /**
     * Types that normally carry several interchangeable units, so the
     * interface offers unit management by default.
     */
    public function typicallyMultiUnit(): bool
    {
        return in_array($this, [
            self::Aparthotel,
            self::BoutiqueHotel,
            self::HotelRoom,
            self::Hostel,
            self::Resort,
            self::ServicedApartment,
        ], true);
    }
}
