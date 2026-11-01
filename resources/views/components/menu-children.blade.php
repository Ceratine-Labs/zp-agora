@props(['items', 'depth' => 2, 'artwork', 'modules'])

{{-- Group only siblings; the customer's workflow hierarchy stays intact. --}}
@foreach ($modules->groups($items) as $module)
    @if ($module['key'] !== null)
        <li class="mega-module" data-menu-module-group="{{ $module['key'] }}" data-menu-node data-menu-label="{{ $module['label'] }}" data-menu-active="{{ $module['items']->contains(fn ($item) => $item->hasAvailableItems) ? 'true' : 'false' }}">
            <h5 class="mega-module-title">
                @if (!in_array($module['key'], ['other', 'my-queue', 'operations'], true) && config('agora.landing_modules.'.$module['key']) && $module['items']->contains(fn ($item) => $item->hasAvailableItems))
                    <a href="{{ route('app.modules.show', ['module' => $module['key'], 'ws' => app(\App\Support\BranchContext::class)->workspace()]) }}" title="Open {{ $module['label'] }} menu">{{ $module['label'] }}<span aria-hidden="true">→</span></a>
                @else
                    {{ $module['label'] }}
                @endif
            </h5>
            <ul>
    @endif
    @foreach ($module['items'] as $child)
        @php
            $hint = trim((string) $child->Hint);
            $hint = strcasecmp($hint, 'live') === 0 ? '' : $hint;
        @endphp
        <li data-menu-node data-menu-label="{{ trim($child->Label.' '.$hint) }}" data-menu-active="{{ $child->hasAvailableItems ? 'true' : 'false' }}"><x-menu-branch :item="$child" :depth="$depth" :artwork="$artwork" :modules="$modules" /></li>
    @endforeach
    @if ($module['key'] !== null)
            </ul>
        </li>
    @endif
@endforeach
