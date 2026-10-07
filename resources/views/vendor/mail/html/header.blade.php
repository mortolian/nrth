@props(['url'])
<tr>
<td class="header" align="center">
<a href="{{ $url }}" class="brand">
<img src="{{ asset('images/nrth-logo.png') }}" width="48" height="48" alt="{{ trim($slot) }}" class="logo">
</a>
</td>
</tr>
