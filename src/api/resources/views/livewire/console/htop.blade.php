@php($m = fn (?float $p) => \App\Livewire\Console\Htop::meter($p))
<pre class="term">@if ($server === null)<span class="t-mute">No servers yet: go servers, then Add server.</span>@elseif ($snapshot === null)<span class="t-b">{{ $server->slug }}</span> <span class="t-mute">hasn't been probed yet · probe {{ $server->slug }}</span>@else<span class="t-b">{{ $server->slug }}</span> <span class="{{ $server->status?->value === 'online' ? 't-ok' : 't-warn' }}">● {{ $server->status?->value }}</span> <span class="t-mute">· probed {{ $snapshot->captured_at?->diffForHumans(short: true) }} · load {{ $snapshot->load_1 }} {{ $snapshot->load_5 }} {{ $snapshot->load_15 }}</span>
<span class="t-mute">cpu </span>[<span class="t-a">{{ $m($snapshot->cpu_usage)['fill'] }}</span>{{ $m($snapshot->cpu_usage)['rest'] }}] <span class="t-b">{{ round((float) $snapshot->cpu_usage) }}%</span>
<span class="t-mute">mem </span>[<span class="t-v">{{ $m($snapshot->memoryUsedPercent())['fill'] }}</span>{{ $m($snapshot->memoryUsedPercent())['rest'] }}] {{ \App\Support\Format::bytes(($snapshot->mem_total ?? 0) - ($snapshot->mem_available ?? 0)) }} / {{ \App\Support\Format::bytes($snapshot->mem_total ?? 0) }}
@if ($disk)<span class="t-mute">{{ str_pad(\Illuminate\Support\Str::limit($disk['mount'], 4, ''), 4) }}</span>[<span class="t-warn">{{ $m($disk['size'] > 0 ? 100 * $disk['used'] / $disk['size'] : 0)['fill'] }}</span>{{ $m($disk['size'] > 0 ? 100 * $disk['used'] / $disk['size'] : 0)['rest'] }}] {{ \App\Support\Format::bytes($disk['available']) }} free
@endif
<span class="t-mute">  CPU%  PROCESS          CONTAINER</span>
@foreach ($cpu as $process)<span class="{{ $process['cpu'] >= 50 ? 't-warn' : '' }}">{{ str_pad(number_format($process['cpu'], 1), 6, ' ', STR_PAD_LEFT) }}  {{ str_pad(\Illuminate\Support\Str::limit($process['name'], 15, ''), 15) }}  </span><span class="t-mute">{{ $process['container'] ?? '—' }}</span>
@endforeach
<span class="t-mute">   MEM  PROCESS          CONTAINER</span>
@foreach ($mem as $process){{ str_pad(\App\Support\Format::bytes($process['rss']), 6, ' ', STR_PAD_LEFT) }}  {{ str_pad(\Illuminate\Support\Str::limit($process['name'], 15, ''), 15) }}  <span class="t-mute">{{ $process['container'] ?? '—' }}</span>
@endforeach
<span class="t-mute">STACKS</span> @foreach ($snapshot->stacks ?? [] as $stack)<span class="{{ str_starts_with((string) ($stack['Status'] ?? ''), 'running') ? 't-ok' : 't-faint' }}">{{ $stack['Name'] ?? '?' }}</span> @endforeach
@endif</pre>
