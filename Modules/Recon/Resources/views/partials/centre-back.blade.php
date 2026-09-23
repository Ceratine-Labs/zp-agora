{{--
    The way back to the recon centre, carried by a form inside one of its
    tabs: which tab, the site, the period the clerk chose, and the run on the
    Auto tab. ReconController::centreBack() builds it and toCentre() reads it.

    Expects: $centre (array|null). Renders nothing off the centre, so a form
    shared with a standalone page redirects exactly as it always has.
--}}
@foreach ($centre ?? [] as $name => $value)
    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
@endforeach
