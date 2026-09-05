{{--
    A landing page whose epic has not been built.

    Deliberately says what is coming and who owns it rather than pretending to
    be a dashboard with nothing on it. `agora.Role.LandingRoute` points a
    branch manager here and an executive at the other one, so this is the first
    screen those two roles see — an empty page with no explanation would read
    as a broken deploy.
--}}
<x-app-shell :title="$title">
    <x-page-head :eyebrow="$eyebrow" :title="$title" :blurb="$blurb" />

    <x-notice tone="info" :title="$title.' is not built yet ('.$owner.')'">
        <p>{{ $what }}</p>
        <p>Your role lands here because <code>agora.Role.LandingRoute</code> says so, and that is
           data rather than code — head office can point a role somewhere else without a release.
           Until then, everything Agora can do today is on the menu above.</p>
    </x-notice>
</x-app-shell>
