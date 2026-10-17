@foreach ($lines as $line)<span class="block">@foreach ($line as [$text, $tone])<span class="{{ $tone }}">{{ $text }}</span>@endforeach&#8203;</span>@endforeach
