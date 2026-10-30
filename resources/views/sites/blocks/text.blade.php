{{-- Text: a heading and a few paragraphs. Cleaned again here, whatever was saved. --}}
<div class="wrap section prose">
    @include('sites.blocks.partials.heading', ['text' => $f['heading']])
    {!! \App\Domain\Websites\Content\RichText::clean((string) $f['body']) !!}
</div>
