<?php

use App\Support\Database\ProcedureMigration;

/**
 * Stock recon — an over smaller than one issue no longer asks for the missing
 * issue to be captured (slot 14x).
 *
 * Ryan, 14 Nov 2026, ZIG ZAG SIZE PAPER at Engen Manguzi (issued in fifties):
 * a night over of 1 read "Unrecorded issue, over left: capture the missing
 * issue on this shift". The row now reads "Over 1 left, under one issue of
 * 50: likely a till timing difference". Outcome text only: nothing proposed
 * or written changes.
 */
return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return [$this->path().'/usp_StockRecon_PreviewBalancing.sql'];
    }
};
