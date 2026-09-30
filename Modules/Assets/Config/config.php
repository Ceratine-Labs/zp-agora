<?php

/*
|--------------------------------------------------------------------------
| Assets (T057)
|--------------------------------------------------------------------------
|
| The register, its movements and its repair job cards live in Agora's own
| database (Ryan, 27 Sep 2026, PM decision 01a0e309). The customer's four asset
| masters — ASSET_AssetGroup, ASSET_AssetType, ASSET_AssetOwner and
| ASSET_AssetMovementType — are read through agora.vw_* and never written. There
| is no PumpIT write anywhere in this module, so there is no write_mode switch:
| nothing here needs turning off.
|
*/

return [

    'name' => 'Assets',

    /*
    |--------------------------------------------------------------------------
    | Depreciation — PROVISIONAL RATES
    |--------------------------------------------------------------------------
    |
    | ***  THESE ARE NOT ZP'S RATES. ***
    |
    | Nothing in the customer's estate carries a useful life, a rate or a
    | residual: a column search across twelve databases on the live instance
    | returned no depreciation data at all (27 Sep 2026). Until ZP supply their
    | accounting policy, Ryan chose (Q3 = B, PM decision 01a0e309) to use the
    | SARS wear-and-tear periods, one per asset group, straight-line, monthly,
    | with no residual value unless ZP say otherwise.
    |
    | The periods are SARS Interpretation Note 47, Annexure A, as recalled when
    | this was written, and are to be checked against the current issue before
    | any figure off this screen is used for anything. A group mixes kinds of
    | asset, and the note gives each group ONE period: `note` says what it
    | covers badly, so the mapping can be argued with rather than trusted.
    |
    | The register says "provisional rates" on screen for as long as
    | `provisional` is true. Set it false only when ZP have confirmed or
    | replaced every line below.
    |
    | HOW IT IS APPLIED — agora.fn_Assets_Depreciation, the one place the
    | arithmetic lives:
    |   · a full month's charge in the month the asset was bought, none in the
    |     month it is disposed of or written off;
    |   · a month is charged at its month end, so "as at 27 September" has
    |     charged up to 31 August;
    |   · accumulated = (cost − residual) × months ÷ life, rounded to the cent,
    |     so the last month absorbs the rounding and the asset ends at exactly
    |     its residual;
    |   · a group with `life_years` null is not depreciated at all, and its
    |     assets show no NBV rather than a cost pretending to be one.
    |
    | Changing a period restates every asset in that group, as at every date:
    | nothing is posted, because Agora has no general ledger in v1 (plan
    | §3.16). The depreciation screen is an answer, not a journal.
    |
    */
    'depreciation' => [

        'provisional' => true,

        'basis' => 'SARS wear-and-tear periods (Interpretation Note 47, Annexure A), one per asset group, '
            .'straight-line monthly, no residual value. Provisional until ZP confirm their own policy.',

        // Percent of cost left at the end of the life. 0 unless ZP say otherwise.
        'residual_pct' => 0,

        'groups' => [
            'AE' => ['life_years' => 4, 'sars_class' => 'Compressors / pumps',
                'note' => 'Mixed group. Generators are 5 years (portable), containers 10, bush and grass cutters 2.'],
            'BE' => ['life_years' => 6, 'sars_class' => 'Kitchen equipment',
                'note' => 'Microwave ovens are 4 years; televisions and hi-fi 6.'],
            'BF' => ['life_years' => 6, 'sars_class' => 'Air-conditioners / furniture and fittings',
                'note' => 'Mixed group. Geysers (hot-water systems) 5, signage 10, fire extinguishers 5.'],
            'BJ' => ['life_years' => 6, 'sars_class' => 'Kitchen equipment',
                'note' => 'Tables and chairs in this group are furniture, also 6.'],
            'CE' => ['life_years' => 6, 'sars_class' => 'Kitchen equipment',
                'note' => 'Refrigeration and butchery equipment. Microwaves 4.'],
            'CH' => ['life_years' => 3, 'sars_class' => 'Computers (personal)',
                'note' => 'Cash registers and alarm systems are 5, software 2.'],
            'DE' => ['life_years' => 6, 'sars_class' => 'Kitchen equipment',
                'note' => 'Trays, pans and jugs are kitchenware, 5.'],
            'DF' => ['life_years' => 6, 'sars_class' => 'Furniture and fittings', 'note' => null],
            'FE' => ['life_years' => 4, 'sars_class' => 'Pumps',
                'note' => 'LOW CONFIDENCE. No IN47 line names fuel dispensers or underground tanks; "pumps" is the nearest. '
                    .'Tanks and installations almost certainly deserve a longer life. ZP to confirm first.'],
            'GE' => ['life_years' => 6, 'sars_class' => 'Kitchen equipment', 'note' => null],
            'GF' => ['life_years' => 6, 'sars_class' => 'Furniture and fittings', 'note' => null],
            'OE' => ['life_years' => 3, 'sars_class' => 'Office equipment — electronic',
                'note' => 'Safes are 25 years (portable safes); cellphones 2.'],
            'OF' => ['life_years' => 6, 'sars_class' => 'Furniture and fittings', 'note' => null],
            'OK' => ['life_years' => 6, 'sars_class' => 'Kitchen equipment',
                'note' => 'Cutlery and cookware are kitchenware, 5.'],
            'PR' => ['life_years' => null, 'sars_class' => 'Not a wear-and-tear asset',
                'note' => 'Property is not depreciated here. Buildings fall under the building allowances (s13quin, 5% '
                    .'a year on commercial buildings), and land is never depreciated. ZP to decide.'],
            'SE' => ['life_years' => 6, 'sars_class' => 'Kitchen equipment', 'note' => null],
            'SF' => ['life_years' => 6, 'sars_class' => 'Furniture and fittings', 'note' => null],
            'SFT' => ['life_years' => 6, 'sars_class' => 'Shop fittings', 'note' => null],
            'V' => ['life_years' => 4, 'sars_class' => 'Delivery vehicles / motorcycles / forklifts',
                'note' => 'Passenger cars (hatchbacks, sedans) and trailers are 5 years.'],
            'WE' => ['life_years' => 6, 'sars_class' => 'Kitchen equipment', 'note' => null],
            'WF' => ['life_years' => 6, 'sars_class' => 'Furniture and fittings', 'note' => null],
        ],
    ],

];
