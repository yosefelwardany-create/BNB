<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Agents\Enums\AgentCapability;
use App\Domain\Agents\Exceptions\AgentNotConfiguredException;
use App\Domain\Agents\Models\AgentAction;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Integrations\Providers\Channels\HostexChannelAdapter;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/** Exact, manager-requested changes. Never flushes the general sync backlog. */
class HostexAgentPublisher
{
    public function __construct(private readonly HostexChannelAdapter $hostex) {}

    public static function rules(AgentCapability $capability): array
    {
        if ($capability === AgentCapability::LiveSettings) {
            return ['currency' => 'required|string|size:3', 'settings' => 'required|array:base_price,weekend_price,cleaning_fee,short_term_cleaning_fee,extra_guest_fee,security_deposit,minimum_stay,maximum_stay,advance_notice,check_out_before,instant_booking,max_guests',
                'settings.base_price' => 'sometimes|numeric|min:1|max:1000000',
                'settings.weekend_price' => 'sometimes|numeric|min:1|max:1000000',
                'settings.cleaning_fee' => 'sometimes|numeric|min:0|max:1000000',
                'settings.short_term_cleaning_fee' => 'sometimes|numeric|min:0|max:1000000',
                'settings.extra_guest_fee' => 'sometimes|numeric|min:0|max:1000000',
                'settings.security_deposit' => 'sometimes|numeric|min:0|max:1000000',
                'settings.minimum_stay' => 'sometimes|integer|min:1|max:1125',
                'settings.maximum_stay' => 'sometimes|integer|min:1|max:1125',
                'settings.advance_notice' => 'sometimes|integer|min:0|max:720',
                'settings.check_out_before' => 'sometimes|integer|min:0|max:23',
                'settings.instant_booking' => 'sometimes|boolean',
                'settings.max_guests' => 'sometimes|integer|min:1|max:100'];
        }
        $rules = ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from'];
        if (in_array($capability, [AgentCapability::LiveBlock, AgentCapability::LiveUnblock], true)) {
            $rules['reason'] = 'sometimes|nullable|string|max:500';
        }
        if ($capability === AgentCapability::LiveRate) {
            $rules += ['amount_minor_units' => 'required|integer|min:100|max:100000000', 'currency' => 'required|string|size:3'];
        }

        return $rules;
    }

    public function mapping(Property $property): ChannelListing
    {
        $mappings = ChannelListing::query()->with('account')->where('property_id', $property->id)
            ->where('organization_id', $property->organization_id)->where('is_active', true)
            ->whereHas('account', fn ($q) => $q->where('organization_id', $property->organization_id)->where('channel', 'hostex')->where('status', 'connected'))->get();
        if ($mappings->count() !== 1 || ! ctype_digit((string) $mappings->first()?->external_listing_id)) {
            throw new AgentNotConfiguredException('This property needs one unambiguous connected Hostex mapping. Nothing was pushed.');
        }

        return $mappings->sole();
    }

    public function targetSignature(Property $property): string
    {
        $mapping = $this->mapping($property);

        return hash('sha256', json_encode([$mapping->id, $mapping->channel_account_id, $mapping->external_listing_id, $mapping->metadata['hostex_channels'] ?? []]));
    }

    public function publish(AgentAction $action): string
    {
        $property = $action->property;
        $mapping = $this->mapping($property);
        if (($action->arguments['_live_target'] ?? null) !== $this->targetSignature($property)) {
            throw new AgentNotConfiguredException('The property mapping changed after this request. Nothing was pushed; review the connection first.');
        }
        $args = Validator::make($action->arguments, self::rules($action->capability))->validate();
        $client = $this->hostex->client($mapping->account);
        if ($action->capability !== AgentCapability::LiveSettings) {
            $from = CarbonImmutable::parse($args['from'], $property->timezone);
            $to = CarbonImmutable::parse($args['to'], $property->timezone);
            $today = CarbonImmutable::today($property->timezone);
            if ($from->lessThan($today) || $to->greaterThan($today->addYears(3)) || $from->diffInDays($to) > 399) {
                throw new AgentNotConfiguredException('Choose up to 400 nights from today, within the next three years.');
            }
        }
        if (in_array($action->capability, [AgentCapability::LiveBlock, AgentCapability::LiveUnblock], true)) {
            if (Reservation::query()->where('property_id', $property->id)->where('organization_id', $property->organization_id)
                ->blocking()->overlapping($args['from'], $to->addDay()->toDateString())->exists()) {
                throw new AgentNotConfiguredException('Those nights overlap an existing reservation. No availability was pushed.');
            }
            // Check the source immediately before opening or closing nights;
            // an inbound sync may not yet have seen a new booking.
            for ($offset = 0; $offset < 1000; $offset += 100) {
                $result = $client->get('reservations', ['property_id' => (int) $mapping->external_listing_id,
                    'start_check_out_date' => $args['from'], 'end_check_out_date' => $to->addYears(3)->toDateString(), 'end_check_in_date' => $args['to'], 'offset' => $offset, 'limit' => 100]);
                if (! is_array($result['reservations'] ?? null)) {
                    throw new AgentNotConfiguredException('The live reservation check was incomplete. Nothing was pushed.');
                }
                foreach ($result['reservations'] as $row) {
                    if (! is_array($row) || ! isset($row['property_id'], $row['status'], $row['check_in_date'], $row['check_out_date'])) {
                        throw new AgentNotConfiguredException('A live reservation could not be checked. Nothing was pushed.');
                    }
                    if ((string) $row['property_id'] !== (string) $mapping->external_listing_id) {
                        throw new AgentNotConfiguredException('Hostex returned a reservation for another property. Nothing was pushed.');
                    }
                    if (! in_array($row['status'], ['cancelled', 'denied', 'timeout'], true)
                        && $row['check_in_date'] <= $args['to'] && $row['check_out_date'] > $args['from']) {
                        throw new AgentNotConfiguredException('Hostex has a reservation on those nights. Nothing was pushed.');
                    }
                }
                if (count($result['reservations']) < 100) {
                    break;
                }
                if ($offset === 900) {
                    throw new AgentNotConfiguredException('Too many reservations to verify safely in one action. Nothing was pushed.');
                }
            }
            $dates = [];
            for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
                $dates[] = $day->toDateString();
            }
            $client->post('availabilities', ['property_ids' => [(int) $mapping->external_listing_id], 'dates' => $dates,
                'available' => $action->capability === AgentCapability::LiveUnblock]);

            return 'Submitted to Hostex for only the requested nights. Hostex propagates property availability to its connected channels asynchronously. The next import will refresh the calendar; final channel completion is not yet confirmed.';
        }
        $channels = array_values(array_filter($mapping->metadata['hostex_channels'] ?? [], fn ($c) => ($c['channel_type'] ?? null) === 'airbnb' && ! empty($c['listing_id'])));
        if (count($channels) !== 1) {
            throw new AgentNotConfiguredException('There is no unique Airbnb listing for this property. Nothing was pushed.');
        }
        $listingId = (string) $channels[0]['listing_id'];
        $current = $client->get('listings/airbnb/price_and_rules', ['listing_id' => $listingId]);
        if (($current['listing_currency'] ?? null) !== strtoupper($args['currency'])) {
            throw new AgentNotConfiguredException('The requested currency does not match the current Airbnb listing currency. No conversion or push was made.');
        }
        if ($action->capability === AgentCapability::LiveRate) {
            if ($args['amount_minor_units'] % 100 !== 0) {
                throw new AgentNotConfiguredException('Hostex requires a whole-currency nightly price. Please specify a whole amount.');
            }
            $client->post('listings/prices', ['channel_type' => 'airbnb', 'listing_id' => $listingId,
                'prices' => [['start_date' => $args['from'], 'end_date' => $args['to'], 'price' => intdiv($args['amount_minor_units'], 100)]]]);

            return 'Submitted the requested nightly prices to Hostex for this Airbnb listing only. Channel completion is asynchronous and not yet confirmed; the next import will refresh source prices.';
        }
        if ($args['settings'] === []) {
            throw new AgentNotConfiguredException('Specify at least one supported listing setting.');
        }
        $combined = array_replace($current, $args['settings']);
        if (isset($combined['minimum_stay'], $combined['maximum_stay']) && $combined['minimum_stay'] > $combined['maximum_stay']) {
            throw new AgentNotConfiguredException('Minimum stay cannot exceed maximum stay. Nothing was pushed.');
        }
        $client->post('listings/airbnb/price_and_rules', ['listing_id' => $listingId, 'settings' => $args['settings']]);

        return 'Airbnb accepted only the requested listing settings through Hostex. Other fees, dates and prices were not included. The next import will refresh the property fields.';
    }
}
