<?php

namespace Modules\StockRecon\Http\Requests;

class GroupPreviewRequest extends BalancePreviewRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['branch_id'], $rules['area_no']);
        $rules['branches'] = ['required', 'array', 'min:1', 'max:100'];
        $rules['branches.*'] = ['required', 'integer', 'min:1', 'distinct'];

        return $rules;
    }
}
