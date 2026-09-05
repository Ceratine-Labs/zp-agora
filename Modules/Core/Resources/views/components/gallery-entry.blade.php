{{--
    One entry in the component gallery: what the component is, the variants,
    and its props — read from the same catalogue that writes
    docs/components.md, so the page and the doc cannot disagree.

    Dev-only. It is used by /dev/components and nothing else, and it lives in
    the Core module rather than the shared library for that reason.

    `name` is the catalogue key. The slot is the live rendering.
--}}
@props(['name'])

{{-- A static array, memoised — no query, no container lookup, and no prop to
     thread through thirty call sites. --}}
@php($spec = \Modules\Core\Support\ComponentCatalogue::all()[$name] ?? null)

<x-card id="c-{{ \Illuminate\Support\Str::slug($name) }}"
        :title="'<'.$name.'>'"
        :sub="$spec['summary'] ?? null"
        flush>
    <x-slot:actions>
        @if ($spec && $spec['mockup'])<span class="asat mono">{{ $spec['mockup'] }}</span>@endif
        @if ($spec && $spec['js'])<x-chip tone="neutral" :dot="false">{{ $spec['js'] }}</x-chip>@endif
    </x-slot:actions>

    {{ $slot }}

    @if ($spec && ($spec['notes'] || $spec['props']))
        <div class="gal-variant">
            @if ($spec['notes'])
                <p class="eyebrow">Why it is like this</p>
                <p style="margin: 6px 0 12px; color: var(--ink-2); max-width: 88ch;">{!! e($spec['notes']) !!}</p>
            @endif

            @if ($spec['props'])
                <p class="eyebrow">Props</p>
                <table class="gal-props">
                    <thead>
                        <tr><th>Prop</th><th>Type</th><th>Default</th><th>What it does</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($spec['props'] as $prop)
                            <tr>
                                <td>{{ $prop[0] }}</td>
                                <td class="gal-type">{{ $prop[1] }}</td>
                                <td class="gal-type">{{ $prop[2] }}</td>
                                <td>{{ $prop[3] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif
</x-card>
