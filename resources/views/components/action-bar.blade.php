{{--
    The press that commits, above the rows it commits.

    Every reconcile screen put its primary action in a footer UNDER the table.
    On a month of ABSA that is four hundred rows below the first thing the
    reader looks at, and the users said so. This is the answer, and it is a
    shared component rather than four copies because "where does the commit
    live" is one decision for the whole application.

    STICKY, not merely moved. Moving the button up reads correctly on first
    paint and loses it again the moment anybody scrolls — and scrolling is
    exactly what somebody does before they decide they are finished. Pinned
    under the chrome, it is in the same place at row 1 and at row 400.

    THE COUNT IS LIVE, and that is the point of `for`. Give it the id of the
    table it acts on and `table-tools.js` recomputes the number on every tick
    and every filter, from the boxes that are actually still in the submission.
    A button that says "Reconcile 138 batches" while a filter has left 12 of
    them submittable is worse than a button with no number on it at all.

    Props:
      for     the id of the <x-table> whose ticks this bar commits. Omit it on
              a bar whose action takes no selection.
      sticky  false to leave it in the flow — for a bar inside a panel that
              scrolls on its own, where pinning it would pin it to the window.
      note    slot: the sentence explaining what the press does. It reads
              beside the button rather than under the table, because that is
              where the decision is now made.

    The button says its own count through three data attributes:

      <button data-count-verb="Reconcile" data-count-noun="batch"
              data-count-plural="batches">Reconcile 138 batches</button>

    The server still renders the correct text, so the bar is right with no
    JavaScript at all; the attributes only let it stay right afterwards.
--}}
@props([
    'for' => null,
    'sticky' => true,
])

<div {{ $attributes->merge(['class' => 'action-bar'.($sticky ? ' is-sticky' : '')]) }}
     @if ($for) data-action-bar="{{ $for }}" @endif>

    {{ $slot }}

    @if ($for)
        {{-- Says WHY the count changed. The number alone leaves somebody
             wondering whether the screen lost their selection. --}}
        <span class="action-bar-scope" data-action-bar-scope hidden></span>
    @endif

    @isset($note)
        <p class="action-bar-note">{{ $note }}</p>
    @endisset
</div>
