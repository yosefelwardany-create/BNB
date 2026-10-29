@php
    /** @var \App\Domain\Websites\DataObjects\SearchCriteria $criteria */
@endphp
<form class="search-bar" method="get" action="{{ $site->url('search') }}" role="search">
    <label class="search-bar__field">
        <span>Check in</span>
        <input type="date" name="checkin" value="{{ $criteria->checkIn?->toDateString() }}" min="{{ now()->toDateString() }}">
    </label>
    <label class="search-bar__field">
        <span>Check out</span>
        <input type="date" name="checkout" value="{{ $criteria->checkOut?->toDateString() }}" min="{{ now()->addDay()->toDateString() }}">
    </label>
    <label class="search-bar__field search-bar__field--narrow">
        <span>Guests</span>
        <input type="number" name="guests" min="1" max="50" value="{{ $criteria->guests }}">
    </label>
    @foreach ($extra ?? [] as $name => $value)
        @if (is_array($value))
            @foreach ($value as $item)
                <input type="hidden" name="{{ $name }}[]" value="{{ $item }}">
            @endforeach
        @elseif ($value !== null)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endif
    @endforeach
    <button type="submit" class="button">Search</button>
</form>
