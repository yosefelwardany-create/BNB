{{--
    A receipt for one payment.

    The honesty case that matters in this whole document set: a receipt for a
    payment that no processor handled must say so, unmissably. A PDF stating that
    money was received when it was not is the single most damaging untruth this
    product could produce, because a receipt is exactly the artefact somebody
    forwards to an accountant.
--}}
@extends('documents.layout')

@section('content')
    @if ($payment->is_simulated)
        <div class="notice">
            <strong>No money moved.</strong> This payment was recorded by a local simulation
            because no payment provider is configured on this installation. This document is
            not evidence of a transaction and must not be treated as a receipt for one.
        </div>
    @elseif (! $payment->is_collected_by_us)
        <div class="notice">
            <strong>Collected by {{ $collectedBy }}.</strong> The money was paid to them and
            has not reached our account. This confirms the guest paid; it is not a record of
            funds we hold.
        </div>
    @endif

    <table>
        <tr>
            <td style="vertical-align: top;">
                <div class="small muted">Received from</div>
                <div class="strong">{{ $payerName }}</div>
                @if ($reservationCode)
                    <div class="small muted">Booking {{ $reservationCode }}</div>
                @endif
            </td>
            <td style="vertical-align: top;" class="right">
                <div class="small muted">Amount</div>
                <div class="strong" style="font-size: 15pt;">{{ $amount }}</div>
                <div class="small muted">{{ $capturedAt }}</div>
            </td>
        </tr>
    </table>

    <h2>Details</h2>

    <table class="lines">
        <tbody>
            @foreach ($details as $label => $value)
                <tr>
                    <td class="small">{{ $label }}</td>
                    <td class="right small">{{ $value }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection

@section('footer')
    @if ($payment->is_simulated)
        Simulated payment — not a financial record.
    @else
        Amounts are in {{ $payment->currency }}.
    @endif
@endsection
