<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Email sample preview</title></head>
<body>
    <h1>{{ $rendered->subject }}</h1>
    {!! $rendered->html !!}
    <hr><h2>Plain text</h2><pre>{{ $rendered->text }}</pre>
</body></html>
