@php
    /** @var array<string, mixed> $priced the guest's view of a quote, from WebsiteQuotes::present() */
    $nightRates = array_map(fn (array $night): int => $night['amount']['amount'], $priced['nights']);
    $nightCount = count($priced['nights']);
    $even = $nightCount > 0 && count(array_unique($nightRates)) === 1;
    $guests = (int) $priced['adults'] + (int) $priced['children'];
@endphp
<dl class="quote">
    <div class="quote__line"><dt>Check-in</dt><dd>{{ \Carbon\CarbonImmutable::parse($priced['check_in'])->format('D j M Y') }}</dd></div>
    <div class="quote__line"><dt>Check-out</dt><dd>{{ \Carbon\CarbonImmutable::parse($priced['check_out'])->format('D j M Y') }}</dd></div>
    <div class="quote__line"><dt>Guests</dt><dd>{{ $guests }}</dd></div>
</dl>

<dl class="quote">
    <div class="quote__line">
        <dt>{{ $even ? $priced['nights'][0]['amount']['display'].' × ' : '' }}{{ $nightCount }} {{ $nightCount === 1 ? 'night' : 'nights' }}</dt>
        <dd>{{ $priced['totals']['accommodation']['display'] }}</dd>
    </div>
    @foreach ($priced['fees'] as $line)
        <div class="quote__line"><dt>{{ $line['label'] }}</dt><dd>{{ $line['amount']['display'] }}</dd></div>
    @endforeach
    @foreach ($priced['discounts'] as $line)
        <div class="quote__line"><dt>{{ $line['label'] }}</dt><dd>−{{ $line['amount']['display'] }}</dd></div>
    @endforeach
    @foreach ($priced['taxes'] as $line)
        <div class="quote__line"><dt>{{ $line['label'] }}</dt><dd>{{ $line['amount']['display'] }}</dd></div>
    @endforeach
    <div class="quote__line quote__total"><dt>Total</dt><dd>{{ $priced['totals']['grand_total']['display'] }}</dd></div>
    <div class="quote__line"><dt>Due now</dt><dd>{{ $priced['due_now']['display'] }}</dd></div>
    @foreach ($priced['later'] as $instalment)
        <div class="quote__line muted"><dt>Due {{ \Carbon\CarbonImmutable::parse($instalment['due_on'])->format('j M Y') }}</dt><dd>{{ $instalment['amount']['display'] }}</dd></div>
    @endforeach
</dl>
