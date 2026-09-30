"""Builds the weekly recon line list from the PumpIT source reports - replaces the old
"PumpIt Stock Recon Problem Items" Google Sheet (Stock Variance tab).

One line per site / recon area / stock item on the Stock Recon Detail in the week:
    LossQTY   = recon variance for the week (balanced / amended)
    STakeQTY  = whole-site stock take variance in the week for the item's count system
                WinBranch  -> 1. Winbranch Stock Take Detail (S/TAKE lines, count date in the week)
                AURA       -> 1. Aura stk 01 stock take register (count date in the week)
                PILOT      -> Stock take VAR per product per day DETAIL (sum of the daily counts in the week)
                NAMOS      -> no stock take link: 'No Count'
                no count line in the week -> 'No Count' (the last stock take date is checked later)
    Qty Variance = STakeQTY - LossQTY (storeroom)
    Qty Var previous STake. = the same stock take variance for the previous week
Sign convention: + = over, - = short."""
import pandas as pd, numpy as np
from engine import code


def _st(df, date_col, code_col, var_col):
    d = df.copy()
    d['D'] = pd.to_datetime(d[date_col], dayfirst=(date_col == 'CountDate' and 'CountRef' in d)).dt.normalize()
    d['C'] = d[code_col].map(code)
    return d[['BranchName', 'C', 'D', var_col]].rename(columns={var_col: 'V'})


def stock_takes(wb, aura, pilot):
    """Whole-site count variances per branch / code / day, by count system."""
    w = wb[wb.PostedAs == 'S/TAKE']
    p = pilot[pilot.ProductCode.astype(str) != '-1']
    return {'WINBRANCH': _st(w, 'CountDate', 'ProductCode', 'VarianceQty'),
            'AURA': _st(aura, 'CountDate', 'StockNo', 'VarianceQty'),
            'PILOT': _st(p, 'StockDate', 'ProductCode', 'VarianceQty')}


def build(rec, st, wf, wt):
    """rec: Stock Recon Detail rows (TransactionDate, Location, Qty*_Amended ...). Returns the sheet-format frame."""
    r = rec.copy()
    r['D'] = pd.to_datetime(r.TransactionDate).dt.normalize()
    wk = r[(r.D >= wf) & (r.D <= wt)].sort_values(['BranchName', 'AreaNo', 'StockItemNo', 'D', 'ShiftNo'])
    g = wk.groupby(['BranchName', 'AreaNo', 'StockItemNo'], sort=True).agg(
        Posname=('Location', 'first'), AreaDescription=('AreaDescription', 'first'), POSCode=('POSCode', 'first'),
        StockItemDescription=('StockItemDescription', 'first'), RandPU=('RandPU', 'last'),
        LossQTY=('QtyVariance_Amended', 'sum')).reset_index()
    g['C'] = g.POSCode.map(code)
    pf, pt = wf - pd.Timedelta(days=7), wf - pd.Timedelta(days=1)

    def var(pos, b, c, f, t):
        s = st.get(str(pos).upper())
        if s is None:
            return None
        x = s[(s.BranchName == b) & (s.C == c) & (s.D >= f) & (s.D <= t)]
        return float(x.V.sum()) if len(x) else None

    S = [var(p_, b, c, wf, wt) for p_, b, c in zip(g.Posname, g.BranchName, g.C)]
    P = [var(p_, b, c, pf, pt) for p_, b, c in zip(g.Posname, g.BranchName, g.C)]
    g['LossQTY'] = g.LossQTY.round(4)
    g['S'] = S
    f2 = lambda v: '' if v is None else f'{v:.4f}'.rstrip('0').rstrip('.')
    out = pd.DataFrame({
        'BranchName': g.BranchName, 'Posname': g.Posname, 'AreaNo': g.AreaNo.astype(str), 'AreaDescription': g.AreaDescription,
        'StockItemNo': g.StockItemNo.astype(str), 'POSCode': g.POSCode.astype(str), 'StockItemDescription': g.StockItemDescription,
        'RandPU': g.RandPU.map(lambda v: f'{v:.2f}'), 'LossQTY': g.LossQTY.map(f2), 'LossValue': (g.LossQTY * g.RandPU).map(f2),
        'STakeQTY': [('No Count' if v is None else f2(v)) for v in S],
        'STakeValue': [('No Count' if v is None else f2(v * pu)) for v, pu in zip(S, g.RandPU)],
        'Qty Variance': [f2((v or 0) - l) for v, l in zip(S, g.LossQTY)],
        'Rand Variance': [f2(((v or 0) - l) * pu) for v, l, pu in zip(S, g.LossQTY, g.RandPU)],
        'Qty Var previous STake.': [f2(v) for v in P],
        'Comment': '', 'Head office feedback': '',
    })
    return out.reset_index(drop=True)
