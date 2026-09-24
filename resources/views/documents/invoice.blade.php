{{--
    A guest invoice for a booking.

    Shows what was charged and what has actually been received, which are two
    different numbers — and a document that prints only the first is the one a
    guest pays twice.
--}}
@extends('documents.layout')

@section('content')
    <table>
        <tr>
            <td style="vertical-align: top;">
                <div class="small muted">Invoiced to</div>
                <div class="strong">{{ $guestName }}</div>
                @if ($guestEmail)
                    <div class="small muted">{{ $guestEmail }}</div>
                @endif
            </td>
            <td style="vertical-align: top;" class="right">
                <div class="small muted">Stay</div>
                <div>{{ $stay }}</div>
                <div class="small muted">{{ $property }}</div>
            </td>
        </tr>
    </table>

    <h2>Charges</h2>

    <table class="lines">
        <thead>
            <tr>
                <th>Description</th>
                <th class="right">Quantity</th>
                <th class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($charges as $charge)
                <tr>
                    <td>
                        {{ $charge['label'] }}
                        @if ($charge['description'])
                            <div class="small muted">{{ $charge['description'] }}</div>
                        @endif
                    </td>
                    <td class="right small">{{ $charge['quantity'] }}</td>
                    <td class="right">{{ $charge['amount'] }}</td>
                </tr>
            @endforeach

            <tr class="total">
                <td colspan="2">Total</td>
                <td class="right">{{ $total }}</td>
            </tr>
        </tbody>
    </table>

    <h2>Payment</h2>

    <table class="lines">
        <tbody>
            <tr>
                <td>Received</td>
                <td class="right">{{ $paid }}</td>
            </tr>
            @if ($refunded !== null)
                <tr>
                    <td>Refunded</td>
                    <td class="right">{{ $refunded }}</td>
                </tr>
            @endif
            <tr class="total">
                <td>{{ $outstandingIsCredit ? 'In credit' : 'Outstanding' }}</td>
                <td class="right">{{ $outstanding }}</td>
            </tr>
        </tbody>
    </table>

    @if ($collectedByChannel)
        <p class="small muted">
            {{-- The guest paid; they did not pay us. Printing this as though we
                 received it invites a dispute nobody can win. --}}
            This booking was paid through {{ $channelName }}, who collected the payment
            directly. Nothing further is owed to us.
        </p>
    @endif
@endsection

@section('footer')
    Amounts are in {{ $currency }}.
@endsection
