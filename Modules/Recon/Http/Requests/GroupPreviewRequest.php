<?php

namespace Modules\Recon\Http\Requests;

/**
 * The master controller's form: a period, and no site.
 *
 * That absence is the whole feature. The recon clerks run the same area for
 * twenty-six branches one at a time, and the only thing that changes between
 * those twenty-six presses is the site — so the site comes off the form and
 * the group runs every one they may reconcile.
 *
 * Everything else is a preview's form exactly, inherited rather than restated:
 * the readings a run is given have to mean the same thing whether one site or
 * all of them were asked for, and two copies of twelve option rules is how
 * that stops being true.
 */
class GroupPreviewRequest extends PreviewRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->sharedRules();
    }
}
