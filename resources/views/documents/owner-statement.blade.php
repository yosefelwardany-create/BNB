{{--
    An owner statement.

    The most scrutinised document this product makes. Two things it must get
    right, both of which are about the reader rather than the renderer:

      - The lines add up to the closing balance with no rules to remember,
        because the first thing an owner does is add up the column.
      - The terms it was calculated under are printed on it. A statement that
        says "management fee 4,200" and nothing else is the one that starts an
        argument nobody can settle a year later.
--}}
@extends('documents.layout')

@section('content')
    @if ($statement->status === 'draft')
        <div class="notice">
            <strong>Draft.</strong> These figures are not final and may change before this
            statement is approved.
        </div>
    @endif

    <table>
        <tr>
            <td style="vertical-align: top;">
                <div class="small muted">Statement for</div>
                <div class="strong">{{ $owner->display_name }}</div>
                @if ($owner->email)
                    <div class="small muted">{{ $owner->email }}</div>
                @endif
            </td>
            <td style="vertical-align: top;" class="right">
                <div class="small muted">Period</div>
                <div>{{ $period }}</div>
                <div class="small muted">
                    {{ $statement->nights_sold }} night(s) ·
                    {{ $statement->reservations_count }} booking(s)
                </div>
            </td>
        </tr>
    </table>

    <h2>Summary</h2>

    <table class="lines">
        <tbody>
            @foreach ($summary as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="right">{{ $row['amount'] }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>Due to you</td>
                <td class="right">{{ $money($statement->closing_balance) }}</td>
            </tr>
        </tbody>
    </table>

    @if ($statement->opening_balance != 0)
        <p class="small muted">
            {{-- Said in words, because a negative opening balance looks like an
                 error to somebody reading their first statement. --}}
            The opening balance is what was carried forward from your last statement.
            A negative figure means the previous period ended with more paid out or spent
            than earned, and that shortfall is recovered here rather than invoiced to you.
        </p>
    @endif

    <h2>Lines</h2>

    <table class="lines">
        <thead>
            <tr>
                <th>Date</th>
                <th>Description</th>
                <th>Property</th>
                <th class="right">Share</th>
                <th class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td class="small">{{ $line['date'] }}</td>
                    <td>
                        {{ $line['description'] }}
                        @if ($line['explanation'])
                            <div class="small muted">{{ $line['explanation'] }}</div>
                        @endif
                    </td>
                    <td class="small">{{ $line['property'] }}</td>
                    <td class="right small">{{ $line['share'] }}</td>
                    <td class="right">{{ $line['amount'] }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">No activity in this period.</td></tr>
            @endforelse

            <tr class="total">
                <td colspan="4">Total</td>
                <td class="right">{{ $money($statement->closing_balance) }}</td>
            </tr>
        </tbody>
    </table>

    @if ($terms !== [])
        <h2>How this was calculated</h2>

        <table class="lines">
            <tbody>
                @foreach ($terms as $label => $value)
                    <tr>
                        <td class="small">{{ $label }}</td>
                        <td class="right small">{{ $value }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p class="small muted">
            These are the terms in force for this period, as recorded on the statement when
            it was produced. Changing your agreement later does not alter this document.
        </p>
    @endif

    @if ($statement->notes)
        <h2>Notes</h2>
        <p>{{ $statement->notes }}</p>
    @endif
@endsection

@section('footer')
    Amounts are in {{ $statement->currency }}.
    @if ($statement->status === 'draft')
        This is a draft and is not a demand for payment.
    @endif
@endsection
