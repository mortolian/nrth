{{-- One <p> per source line so DomPDF can paginate between lines; heading uses page-break-after: avoid. --}}
@if(filled($content))
    <div class="section section-prose">
        <h3>{{ $title }}</h3>
        <div class="pdf-prose">
            @foreach(preg_split('/\r\n|\r|\n/', (string) $content) as $line)
                @if(trim((string) $line) === '')
                    <div class="pdf-prose-break"></div>
                @else
                    <p class="pdf-prose-p">{{ trim((string) $line) }}</p>
                @endif
            @endforeach
        </div>
    </div>
@endif
