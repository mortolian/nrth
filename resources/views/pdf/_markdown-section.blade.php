{{-- Safe markdown HTML for DomPDF; content must already be rendered HTML (escaped). --}}
@if(filled($html))
    <div class="section section-prose">
        <h3>{{ $title }}</h3>
        <div class="pdf-prose pdf-markdown">
            {!! $html !!}
        </div>
    </div>
@endif
