@props(['template', 'source', 'scopeName', 'mayEdit', 'definition', 'branch', 'code'])
    <x-card :title="$scopeName" :sub="$template ? 'Version '.$template->Version : 'New override · starts from the group default'">
        @if (!$mayEdit)<p class="field-help">This is your group fallback. Create an override for your branch to customise it.</p>@endif
        <form id="email-template-form" method="POST" action="{{ route('app.setup.email-templates.save', compact('branch', 'code')) }}">
            @csrf
            <input type="hidden" name="Version" value="{{ old('Version', $template?->Version ?? 0) }}">
            <x-field name="Subject" label="Subject" :value="old('Subject', $source->Subject)" />
            <div class="field field-wide"><label for="email-html">HTML body</label><textarea id="email-html" name="BodyHtml" rows="12">{{ old('BodyHtml', $source->BodyHtml) }}</textarea></div>
            <p class="field-help">Use paragraphs, headings, lists, tables and links. Links accept an HTTP/HTTPS address or a URL variable. Images, scripts, styles and other attributes are refused.</p>
            <div class="field field-wide"><label for="email-text">Plain text body</label><textarea id="email-text" name="BodyText" rows="8">{{ old('BodyText', $source->BodyText) }}</textarea></div>
            <x-field name="IsActive" label="Availability" type="bool" :value="old('IsActive', $template?->IsActive ?? true)" placeholder="Active" help="An inactive branch override uses the group default. If no active default or override exists, sending this code is refused." />
            <div class="form-actions">
                @if ($mayEdit) @can('email.templates.edit')<button class="btn-primary" type="submit">Save template</button>@endcan @endif
                <button class="btn" type="submit" formaction="{{ route('app.setup.email-templates.preview', compact('branch', 'code')) }}" formtarget="email-sample">Preview sample</button>
            </div>
        </form>
    </x-card>
    <x-card title="Available variables" sub="Choose a field, then a variable to insert it at the cursor. Preview uses fictional sample values.">
        <div class="form-actions">
            @foreach ($definition->variables as $name => $variable)
                <x-chip as="button" :dot="false" :data-template-variable="$name" :title="'Sample: '.$variable['sample']">{{ '{'.'{'.$name.'}'.'}' }}</x-chip>
            @endforeach
        </div>
    </x-card>
    @verbatim
    <script>
        (() => {
            const form = document.getElementById('email-template-form');
            let target = form.elements.Subject;
            ['Subject', 'BodyHtml', 'BodyText'].forEach(name => {
                form.elements[name].addEventListener('focus', () => { target = form.elements[name]; });
            });
            document.querySelectorAll('[data-template-variable]').forEach(button => {
                button.addEventListener('click', () => {
                    target.setRangeText('{{' + button.dataset.templateVariable + '}}', target.selectionStart, target.selectionEnd, 'end');
                    target.focus();
                    target.dispatchEvent(new Event('input', { bubbles: true }));
                });
            });
        })();
    </script>
    @endverbatim
