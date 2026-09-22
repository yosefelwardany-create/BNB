<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Registries;

use App\Domain\Integrations\Contracts\ChannelAdapterInterface;
use App\Domain\Integrations\Providers\Channels\DirectBookingAdapter;
use App\Domain\Integrations\Providers\Channels\IcalChannelAdapter;
use App\Domain\Integrations\Providers\Channels\SimulatedOtaAdapter;

/**
 * @extends ProviderRegistry<ChannelAdapterInterface>
 */
class ChannelAdapterRegistry extends ProviderRegistry
{
    /**
     * Channels the platform models, whether or not a live adapter exists yet.
     *
     * The mapping, synchronisation and reservation import machinery is
     * channel-agnostic, so adding a real adapter is the only work left for
     * each of these.
     *
     * @var array<string, string>
     */
    public const KNOWN_CHANNELS = [
        'airbnb' => 'Airbnb',
        'booking_com' => 'Booking.com',
        'vrbo' => 'Vrbo',
        'expedia' => 'Expedia',
        'google_vacation_rentals' => 'Google Vacation Rentals',
        'ical' => 'iCal feed',
        'direct' => 'Direct booking',
    ];

    protected function registerDefaults(): void
    {
        // Direct bookings come from our own booking engine: no external system
        // is involved, so this adapter is genuinely live.
        $this->register('direct', DirectBookingAdapter::class);

        // iCal is a real, open protocol and this adapter really speaks it.
        $this->register('ical', IcalChannelAdapter::class);

        // The major OTAs each require a commercial partner agreement before
        // their APIs can be used. Until credentials exist, they are served by
        // a simulated adapter which exercises the full synchronisation path
        // locally and is always labelled as simulated in the interface.
        foreach (['airbnb', 'booking_com', 'vrbo', 'expedia', 'google_vacation_rentals'] as $key) {
            $this->register($key, fn ($container) => new SimulatedOtaAdapter(
                $key,
                self::KNOWN_CHANNELS[$key],
            ));
        }
    }

    protected function defaultKey(): string
    {
        return 'direct';
    }

    protected static function providerNoun(): string
    {
        return 'channel adapter';
    }
}
