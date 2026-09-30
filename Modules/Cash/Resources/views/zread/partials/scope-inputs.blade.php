{{--
    The screen's scope as hidden fields, for every form that acts on it — so a
    press acts on exactly the site, period and location on the screen, read by
    the same ZreadScope on the way back in.
--}}
@foreach ($scope->query() as $name => $value)
    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
@endforeach
