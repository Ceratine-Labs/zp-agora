<x-app-shell :title="$code">
    <x-page-head eyebrow="Setup · Email" :title="$code">
        <x-slot:actions><a class="btn" href="{{ route('app.setup.email-templates.index') }}">All templates</a></x-slot:actions>
    </x-page-head>
    @if (session('status'))<x-notice tone="info" title="Saved">{{ session('status') }}</x-notice>@endif
    @if ($errors->any())<x-notice tone="warn" title="Template not saved"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-notice>@endif
    <x-email::template-editor :template="$template" :source="$source" :scope-name="$scopeName" :may-edit="$mayEdit" :definition="$definition" :branch="$branch" :code="$code" />
    <x-card title="Sample preview">
        <iframe name="email-sample" title="Email sample preview" sandbox="" referrerpolicy="no-referrer" width="100%" height="380" srcdoc="<p>Choose Preview sample to render the current content with sample values.</p>"></iframe>
    </x-card>
    <x-card title="Version history" :sub="$history->count().' saved versions'" collapsible>
        @forelse ($history as $version)
            <details><summary>Version {{ $version->Version }} · {{ $version->IsActive ? 'Active' : 'Inactive' }} · {{ $version->CreatedAt }}</summary>
                <p>Subject: {{ $version->Subject }}</p>
                <label for="history-html-{{ $version->Version }}">Saved HTML</label><textarea id="history-html-{{ $version->Version }}" rows="5" readonly>{{ $version->BodyHtml }}</textarea>
                <pre>{{ $version->BodyText }}</pre>
            </details>
        @empty<p class="field-help">The first save will create version 1.</p>@endforelse
    </x-card>

</x-app-shell>
