<?php

namespace Modules\StockRecon\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\StockRecon\Services\StockReconTutorialContent;

class StockReconTutorialController extends Controller
{
    public function __invoke(Request $request, StockReconTutorialContent $tutorial): View
    {
        $content = $tutorial->all();
        $requested = $request->query('lesson');
        $ids = array_column($content['lessons'], 'id');
        $lesson = is_string($requested) && in_array($requested, $ids, true)
            ? $requested
            : $ids[0];

        return view('stockrecon::tutorial', compact('content', 'lesson'));
    }
}
