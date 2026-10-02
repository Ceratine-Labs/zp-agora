{{--
    One audit trail change on a page of its own: the same fragment the row
    expands to, for a link opened directly (feature-rules §3.7: a reference
    leads somewhere).
--}}
<x-app-shell title="Audit trail · change">
    <x-page-head eyebrow="Control · Governance" title="A change Agora made in PumpIT"
                 :blurb="$change->Module.' · '.$change->RowKey">
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.reports.show', 'audit-trail') }}">Audit trail</a>
        </x-slot:actions>
    </x-page-head>
    <x-card :title="$change->Action" flush>
        @include('reports::audit.change')
    </x-card>
</x-app-shell>
