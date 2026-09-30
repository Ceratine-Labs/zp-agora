{{--
    The one cashup a press acts on, as hidden fields — site, day, till and
    shift, read back by the same CashupScope — and where to come back to.

    Props: $scope (CashupScope, naming one cashup), $from ('banking' | 'cashups' | 'phone')
--}}
@foreach ($scope->query() as $name => $value)
    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
@endforeach
<input type="hidden" name="from" value="{{ $from ?? 'banking' }}">
