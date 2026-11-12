<?php

/*
|--------------------------------------------------------------------------
| Partners
|--------------------------------------------------------------------------
|
| Setup → Trading rules → Suppliers and Account customers (T024). Both
| masters are PumpIT's (BRN_Suppliers, BRN_Customers), read through
| agora.vw_Partner* views; an edit or a new partner is written back to them.
|
*/

return [

    'name' => 'Partners',

    /*
    | THE PUMPIT WRITE SWITCH — a named write (CLAUDE.md rule 1).
    |
    | Deliberately a literal and NOT an environment variable, exactly as
    | people.write_mode and utilities.write_mode are: Agora starting to write
    | the production ERP is a commit with a name on it.
    |
    |   'live'  a supplier edit writes [PumpIT].dbo.BRN_Suppliers SupplierName
    |           (every site carrying the code), ContactPerson, Department,
    |           isActive, IsCash, IsStock (one site); New supplier inserts the
    |           code at every site; a customer edit writes BRN_Customers
    |           CustomerName, CustomerContact, Department, JobTitle, TelNoA,
    |           TelNoB; New customer inserts one row; Put back reverses an
    |           edit — every value recorded first in agora.PartnersWrite
    |   'off'   the screens are read-only, no write procedure is called, and
    |           the Edit, New and Put back controls are not drawn
    |
    | 'live' on Ryan's standing rule of 12 Nov 2026, "agora is replacing
    | pumpit, always write back to pumpit with confirmation" (PM decision
    | 01a1cecf-8e08).
    */
    'write_mode' => 'live',

    // Every write carries a reason this long, at least. The procedures
    // enforce it; the forms only say so.
    'reason_min' => 10,

    // The palette's hits per search — usp_Partners_Search caps it at 50.
    'search_limit' => 12,

];
