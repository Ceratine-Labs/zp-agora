{{--
    The sites an every-site run leaves out, and why.

    A site with no extraction rule for the area can only refuse, and until
    23 Sep 2026 each press recorded a failed run for every one of them — 236 on
    live, all NO_CRITERIA, all Richie Motors, Bullion, Teds and Wimpy Ladysmith.
    Leaving them out stops the clutter. Naming them here keeps the fact: "no
    rule" and "nothing to reconcile" are opposite answers, and the screen that
    hid the first was finding 1.

    Expects: $unconfigured (Collection of Branch), $area; optionally $noticeStyle,
    because the form's card is padded and the group page's is flush.
--}}
@if ($unconfigured->isNotEmpty())
    <x-notice tone="info" collapsible :style="$noticeStyle ?? 'margin-bottom:16px'"
              :title="$unconfigured->count().' trading '.Str::plural('site', $unconfigured->count()).' left out — no '.$area['label'].' rules'">
        <p><strong>{{ $unconfigured->pluck('Name')->implode(', ') }}</strong>
           {{ $unconfigured->count() === 1 ? 'has' : 'have' }} no extraction rule for this area, so a
           preview could only refuse. {{ $unconfigured->count() === 1 ? 'It is' : 'They are' }} not previewed
           and no failed run is recorded.</p>
        <p class="field-help">If {{ $unconfigured->count() === 1 ? 'this site banks' : 'these sites bank' }}
           through {{ $area['label'] }}, {{ $unconfigured->count() === 1 ? 'it is' : 'they are' }} not being
           reconciled at all until a rule exists — add one on the Configuration tab.</p>
    </x-notice>
@endif
