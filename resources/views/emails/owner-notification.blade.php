<x-mail::message>
# {{ $title }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
<x-mail::button :url="$actionUrl">
{{ $actionLabel }}
</x-mail::button>

L'équipe {{ config('app.name') }}
</x-mail::message>
