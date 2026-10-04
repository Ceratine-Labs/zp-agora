{{--
    A landing page whose epic has not been built.

    Deliberately says what is coming and who owns it rather than pretending to
    be a dashboard with nothing on it. `agora.User.LandingRoute` points a
    branch manager here and an executive at the other one, so this is the first
    screen those people see — an empty page with no explanation would read
    as a broken deploy. The "not built yet" notice stays on the page; why they
    land here sits behind the info button beside the title.
--}}
<x-app-shell :title="$title">
    <x-page-head :eyebrow="$eyebrow" :title="$title">
        <x-slot:info>
            <p>{{ $blurb }}</p>
            <p>You land here because your account's landing page is set to it. Head office can point you
               somewhere else without a release.</p>
        </x-slot:info>
    </x-page-head>

    <x-notice tone="info" :title="$title.' is not built yet ('.$owner.')'">
        <p>{{ $what }}</p>
        <p>Until then, everything Agora can do today is on the menu above.</p>
    </x-notice>
</x-app-shell>
