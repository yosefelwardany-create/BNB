{{-- Guest reviews: the best published reviews of the site's homes. Real ones only. --}}
@if ($reviews->isNotEmpty() || $preview)
    <div class="wrap section">
        @include('sites.blocks.partials.heading', ['text' => $f['title']])
        @if ($reviews->isEmpty())
            <p class="muted">Published reviews of your homes will appear here.</p>
        @else
            <ul class="reviews reviews--cards">
                @foreach ($reviews as $review)
                    <li class="review">
                        @if ($review->rating)
                            <div class="review__rating" aria-label="{{ $review->rating }} out of {{ $review->rating_scale ?: 5 }}">
                                {{ str_repeat('★', (int) round($review->rating * 5 / max(1, $review->rating_scale ?: 5))) }}
                            </div>
                        @endif
                        @if ($review->title)
                            <strong>{{ $review->title }}</strong>
                        @endif
                        <p>{{ $review->public_comment }}</p>
                        @if ($review->stay_date)
                            <div class="muted small">Stayed {{ $review->stay_date->format('F Y') }}</div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
