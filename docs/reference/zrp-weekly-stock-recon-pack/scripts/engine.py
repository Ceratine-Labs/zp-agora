"""ZRP Stock Recon Diagnostic Engine - Convenience + Baobab Inn (Pilot).
Joins shift recon, whole-site stock counts, purchases and issue multiples, and classifies
every counted recon item into a scenario with a likely cause and an action."""
import pandas as pd, numpy as np, glob, re

import os
HERE = os.path.dirname(os.path.abspath(__file__))
U = os.environ.get('ZRP_INPUT', '/mnt/user-data/uploads')          # folder holding the week's exports
MASTER = os.path.join(HERE, '..', 'reference', 'issue_multiples.xlsx')  # replaced at run time if an updated multiples file is uploaded
TOL_MULT = 0.10          # over within 10% of a whole issue multiple
MATCH_PCT = 0.15         # recon and count agree within 15% of the larger
VIRTUAL_AREAS = {'Lotto/Electricity/Airtime'}
EXCL_CATS = {'FUEL', 'VV', 'VVONL', 'LOTTO', 'BETTING', 'DSTV'}

def _norm(s):
    return re.sub(r'[^a-z0-9]', '', str(s).lower())

def find(key, required=True):
    """Newest file in the input folder whose name contains the key (case, spaces and punctuation ignored)."""
    k = _norm(key)
    hits = [f for f in glob.glob(os.path.join(U, '**', '*'), recursive=True)
            if os.path.isfile(f) and f.lower().endswith(('.xls', '.xlsx', '.csv')) and k in _norm(os.path.basename(f))]
    if not hits:
        if required:
            raise FileNotFoundError(f'Missing report: no file in {U} has "{key}" in its name. See reference/required_reports.md.')
        return None
    return sorted(hits, key=lambda f: (os.path.getmtime(f), os.path.basename(f)))[-1]

def L(key):
    f = find(key)
    return pd.read_csv(f) if f.lower().endswith('.csv') else pd.read_excel(f)

def code(s):
    s = str(s).strip()
    s = re.sub(r'^PROD', '', s)
    s = s[:-2] if s.endswith('.0') else s
    return s.upper()

def wk(d):
    d = pd.to_datetime(d)
    return (d - pd.to_timedelta(d.dt.weekday, 'D')).dt.normalize()

# ---------------------------------------------------------------- load
def load():
    r = L('Stock_Recon_Detail')
    r = r[~r.AreaDescription.isin(VIRTUAL_AREAS)].copy()
    r['Code'] = r.POSCode.map(code)
    r['Date'] = pd.to_datetime(r.TransactionDate).dt.normalize()
    r['Week'] = wk(r.Date)
    # neutralise fat-finger issue captures (e.g. 123456) in the staff-count basis; they are reported separately as S4
    mis = (r.QtyIssued_Original > 5 * r.QtyIssued_Amended.clip(lower=1)) & (r.QtyIssued_Original > 20)
    r['MisKeyed'] = mis
    r.loc[mis, 'QtyVariance_Original'] = r.loc[mis, 'QtyVariance_Original'] + (r.loc[mis, 'QtyIssued_Original'] - r.loc[mis, 'QtyIssued_Amended'])
    # chain breaks: staff opening that does not equal the previous shift's staff closing is a capture error, not a variance.
    # Balancing repairs it; remove it from the staff-count basis and report it separately (S10).
    r = r.sort_values(['BranchName', 'AreaNo', 'StockItemNo', 'Date', 'ShiftNo'])
    gg = r.groupby(['BranchName', 'AreaNo', 'StockItemNo'])
    r['PrevCloseOrig'] = gg.QtyClose_Original.shift(1)
    r['PrevCloseAmended'] = gg.QtyClose_Amended.shift(1)
    brk = r.QtyOpen_Original - r.PrevCloseOrig
    r['ChainBreak'] = np.where(r.PrevCloseOrig.notna() & (brk.abs() > 1e-6), brk, 0.0)
    # correct repair (Andries, 23 Sep 2026): the OPENING is amended to the previous closing; the closing count stands
    r['BreakRepair'] = np.select([
        r.ChainBreak == 0,
        (r.QtyOpen_Amended - r.PrevCloseAmended).abs() > 1e-6,
        ((r.PrevCloseAmended - r.PrevCloseOrig).abs() > 1e-6) & ((r.QtyOpen_Amended - r.QtyOpen_Original).abs() <= 1e-6),
        ((r.QtyOpen_Amended - r.QtyOpen_Original).abs() > 1e-6) & ((r.PrevCloseAmended - r.PrevCloseOrig).abs() <= 1e-6)],
        ['', 'STILL BROKEN after balancing', 'WRONG - previous closing amended to match the opening', 'Correct - opening amended to previous closing'],
        'Both opening and previous closing amended')
    first_adj = np.where(r.PrevCloseOrig.isna(), r.QtyOpen_Original - r.QtyOpen_Amended, 0.0)   # first shift in the extract
    r['QtyVariance_Original'] = r.QtyVariance_Original + r.ChainBreak + first_adj

    m = pd.read_excel(MASTER, sheet_name='Proposed Multiples')
    m['Code'] = m.POSCode.map(code)
    m = m[['BranchName', 'Code', 'Issue Multiple', 'Issue Unit', 'Enforcement']].drop_duplicates(['BranchName', 'Code'])
    r = r.merge(m, on=['BranchName', 'Code'], how='left')
    r['Mult'] = r['Issue Multiple'].fillna(0)
    r['Weighed'] = r['Issue Unit'].eq('Kilogram / litre') | r.StockItemDescription.str.contains(r'\b(KG|Kg|kg)\b|\(K\)|\(L\)', regex=True)

    # whole-site counts: WinBranch S/TAKE + Pilot daily prep sheet
    w = L('Winbranch_Stock_Take')
    w = w[w.PostedAs == 'S/TAKE'].copy()
    w['Date'] = pd.to_datetime(w.CountDate, dayfirst=True)
    w['Code'] = w.ProductCode.map(code)
    st = w.groupby(['BranchName', 'Code', 'Date']).agg(
        CountVarQty=('VarianceQty', 'sum'), CountVarCost=('VarianceAtCost', 'sum'),
        BookQty=('BookQty', 'first'), CountedQty=('CountedQty', 'last'), Cost=('LatestCost', 'last'),
        Reversed=('ChurnFlag', lambda x: x.notna().any()), Gap=('DaysSincePreviousCount', 'first'),
        CountLines=('CountRef', 'size')).reset_index()
    st['System'] = 'WinBranch'

    p = L('Stock_take_VAR')
    p = p[p.ProductCode.astype(str) != '-1'].copy()
    p['Date'] = pd.to_datetime(p.StockDate)
    p['Code'] = p.ProductCode.map(code)
    pst = p.groupby(['BranchName', 'Code', 'Date']).agg(
        CountVarQty=('VarianceQty', 'sum'), CountVarCost=('VarianceValue', 'sum'),
        BookQty=('ExpectedClosingQty', 'sum'), CountedQty=('CountedClosingQty', 'sum'),
        Cost=('UnitCost', 'last')).reset_index()
    pst['Reversed'] = False; pst['Gap'] = 1; pst['CountLines'] = 1; pst['System'] = 'Pilot'

    # Aura QSR whole-site counts (stk 01 register) - all stock locations of the item summed
    a = L('stk_01')
    a['Date'] = pd.to_datetime(a.CountDate); a['Code'] = a.StockNo.map(code)
    a['Gap'] = (a.Date - pd.to_datetime(a.PrevCountDate)).dt.days
    ast = a.groupby(['BranchName', 'Code', 'Date']).agg(
        CountVarQty=('VarianceQty', 'sum'), CountVarCost=('VarianceValue', 'sum'),
        BookQty=('ExpectedQty', 'sum'), CountedQty=('CountedQty', 'sum'),
        Cost=('ActualUnitCostPaid', 'last'), Gap=('Gap', 'max'), CountLines=('StockNo', 'size')).reset_index()
    ast['Reversed'] = False; ast['System'] = 'Aura'
    st = pd.concat([st, pst, ast], ignore_index=True)

    # purchases / credits: Omni (WinBranch) + Pilot purchases
    o = L('Omni_Supplier')
    o = o[~o.Category.astype(str).str.strip().isin(EXCL_CATS)].copy()
    o['Date'] = pd.to_datetime(o.EODDate).dt.normalize()
    o['Code'] = o.ItemCode.map(code)
    o['Type'] = o.TransactionType.str.upper().map({'I': 'Invoice', 'C': 'Credit'})
    pu = o[['BranchName', 'Code', 'Date', 'Type', 'Qty', 'LineTotalAmountExcl', 'BusinessPartnerName', 'DocumentNumber']].rename(
        columns={'LineTotalAmountExcl': 'ValueExcl', 'BusinessPartnerName': 'Supplier'})
    pp = L('Purchases_per')
    pp['Date'] = pd.to_datetime(pp.PurchaseDate); pp['Code'] = pp.ProductCode.map(code); pp['Type'] = 'Invoice'
    pu = pd.concat([pu, pp[['BranchName', 'Code', 'Date', 'Type', 'QtyPurchased', 'PurchasesExclVat', 'SupplierName', 'SupplierInvoice']].rename(
        columns={'QtyPurchased': 'Qty', 'PurchasesExclVat': 'ValueExcl', 'SupplierName': 'Supplier', 'SupplierInvoice': 'DocumentNumber'})])
    g = L('Aura_09')
    g['Date'] = pd.to_datetime(g.TransactionDate).dt.normalize(); g['Code'] = g.StockCode.map(code)
    g['Type'] = np.where(g.TransactionType.eq('Credit note'), 'Credit', 'Invoice')
    pu = pd.concat([pu, g[['BranchName', 'Code', 'Date', 'Type', 'Quantity', 'LineTotalExclVat', 'Supplier', 'InvoiceReference', 'ExceptionFlag']].rename(
        columns={'Quantity': 'Qty', 'LineTotalExclVat': 'ValueExcl', 'InvoiceReference': 'DocumentNumber', 'ExceptionFlag': 'GrvFlag'})])
    pu['Supplier'] = pu.Supplier.astype(str).str.strip()

    # cost per unit fallback from DET sales
    d = L('DET_Sales'); d['Code'] = d.ProductCode.map(code)
    d = d[d.SalesQty > 0]
    cost = (d.CostOfSales / d.SalesQty).groupby([d.BranchName, d.Code]).mean().rename('DetCost').reset_index()
    return r, st, pu, cost

def near_mult(q, mult, tol=TOL_MULT):
    """q (positive) within tol of k*mult for k>=1"""
    if mult is None or mult < 2 or q <= 0: return 0
    k = round(q / mult)
    return k if k >= 1 and abs(q - k * mult) <= tol * mult else 0

# ---------------------------------------------------------------- shift tests
def shift_tests(r):
    r = r.sort_values(['BranchName', 'AreaNo', 'StockItemNo', 'Date', 'ShiftNo']).copy()
    rows = []
    g = r.groupby(['BranchName', 'AreaNo', 'StockItemNo'], sort=False)
    r['NextOrigVar'] = g.QtyVariance_Original.shift(-1)
    # Balancing audit (Andries, 23 Sep 2026): counts are taken around the shift change, so a sale either side of the count
    # moves quantity between adjacent shifts. Balancing moves an OVER onto a neighbouring SHORT by editing the closing count.
    # An over may be reduced to nil but never become a short; a short may be reduced but never increased.
    r['LastInSeries'] = g.Date.transform('max').eq(r.Date) & g.ShiftNo.transform('last').eq(r.ShiftNo)
    for _, x in r.iterrows():
        mult = x.Mult if not x.Weighed else 0
        va, vo = x.QtyVariance_Amended, x.QtyVariance_Original
        base = dict(BranchName=x.BranchName, Area=x.AreaDescription, Code=x.Code, Item=x.StockItemDescription,
                    Date=x.Date, Shift=x.ShiftNo, Multiple=mult, IssuedOrig=x.QtyIssued_Original,
                    IssuedAmended=x.QtyIssued_Amended, VarOrig=vo, VarAmended=va, RandPU=x.RandPU)
        k = near_mult(va, mult)
        if k:
            rows.append({**base, 'Test': 'S1 Missed issue sheet', 'Detail': f'Over {va:g} = {k} x pack of {mult:g}; no issue captured for it'})
        if abs(x.ChainBreak) > 1e-6:
            b_ = {**base, 'VarOrig': x.ChainBreak, 'VarAmended': x.ChainBreak, 'Repair': x.BreakRepair}
            if x.ChainBreak < 0:
                rows.append({**b_, 'Test': 'S10a Chain break - opening BELOW previous closing (stock vanished between shifts)',
                             'Detail': f'Previous shift closed on {x.PrevCloseOrig:g}, this shift opened on {x.QtyOpen_Original:g}: {-x.ChainBreak:g} disappeared with nobody accountable. Repair: {x.BreakRepair}'})
            else:
                rows.append({**b_, 'Test': 'S10b Chain break - opening ABOVE previous closing',
                             'Detail': f'Previous shift closed on {x.PrevCloseOrig:g}, this shift opened on {x.QtyOpen_Original:g}: {x.ChainBreak:g} appeared (can hide an over or offset a short). Repair: {x.BreakRepair}'})
            if x.BreakRepair.startswith(('WRONG', 'STILL', 'Both')):
                rows.append({**b_, 'Test': 'S12 Chain break repaired the wrong way' if not x.BreakRepair.startswith('STILL') else 'S13 Chain break still open after balancing',
                             'Detail': x.BreakRepair + ' - the opening must be amended to the previous closing; the closing count stands'})
        if x.LastInSeries and abs(x.QtyClose_Original - x.QtyClose_Amended) > 1e-6:
            rows.append({**base, 'Test': 'S11 Latest closing count amended',
                         'Detail': f'Latest count {x.QtyClose_Original:g} changed to {x.QtyClose_Amended:g} - balancing must work backwards from the latest count, which stands'})
        ks = near_mult(-va, mult)
        if ks:
            rows.append({**base, 'Test': 'S3 Full pack short', 'Detail': f'Short {va:g} = {ks} x pack of {mult:g}: pack issued on paper but not handed over, or a full pack is missing'})
        if x.QtyIssued_Original != x.QtyIssued_Amended:
            big = x.QtyIssued_Original > 5 * max(x.QtyIssued_Amended, 1) and x.QtyIssued_Original > 20
            if big: tname = 'S4 Issue mis-keyed'
            elif x.QtyIssued_Amended > x.QtyIssued_Original: tname = 'S4a Missed issue captured at balancing'
            else: tname = 'S4b Issue reduced at balancing'
            rows.append({**base, 'Test': tname, 'Detail': f'Issue captured {x.QtyIssued_Original:g}, amended to {x.QtyIssued_Amended:g}'
                         + (' - the over was an issue not written up on the shift; paperwork failure even though it now balances' if tname.startswith('S4a') else
                            ' - issue over-captured or captured twice' if tname.startswith('S4b') else '')})
        if mult >= 2 and x.QtyIssued_Amended > 0 and (x.QtyIssued_Amended % mult) > 1e-6:
            rows.append({**base, 'Test': 'S5 Issue not in pack multiple', 'Detail': f'Issued {x.QtyIssued_Amended:g}, pack is {mult:g}'})
        if vo > 0.5 and va < -0.5:
            rows.append({**base, 'Test': 'S7 Balancing breach: over turned into short', 'Detail': f'Staff over {vo:g} became short {va:g} after balancing - breaks the rule that an over is never made a short'})
        elif va < vo - 0.5 and vo < 0:
            rows.append({**base, 'Test': 'S8 Balancing increased a short', 'Detail': f'Short {vo:g} made worse to {va:g} by balancing'})
        nv = x.NextOrigVar
        if vo < -1 and pd.notna(nv) and nv > 0 and abs(nv + vo) <= max(1, 0.15 * abs(vo)):
            rows.append({**base, 'Test': 'S6 Timing at shift change (short then over next shift)', 'Detail': f'Short {vo:g} offset by over {nv:g} next shift - sale either side of the count; legitimately balanced'})
    return pd.DataFrame(rows)

# ---------------------------------------------------------------- window reconciliation
CLASSES = {
 'A1': ('Confirmed loss at recon point', 'Staff at the area are accountable - site count confirms the recon short.'),
 'A2': ('Loss at recon point AND in storeroom', 'Charge the area short; storeroom excess = key-holder/unrecorded issue - management accountable.'),
 'A3': ('Recon short larger than site loss', 'Part of the area short is paperwork (issue over-recorded / closing count low). Check issue sheets before charging.'),
 'B1': ('Storeroom loss (recon balanced)', 'Stock left the storeroom with no issue and no sale - key holder, receiving short, or unrecorded issue stolen at counter.'),
 'B2': ('Uncaptured issue + storeroom loss', 'Area received stock without paperwork (recon over) and site is still short - storeroom control failure.'),
 'C1': ('Recon short NOT supported by site count', 'Do not charge staff yet: issue written but not handed over, captured twice, or area closing count wrong.'),
 'C2': ('Uncaptured issue (site balances)', 'Stock moved to the area without an issue sheet. Paperwork fault, not a loss.'),
 'D1': ('Over at area AND site (equal)', 'Receipt under-captured (weight/UOM/free stock) or POS rung under this code for another product.'),
 'D2': ('Storeroom over (recon balanced)', 'GRV under-captured, credit note raised but stock kept, or previous count wrong.'),
 'D3': ('Area short, site over', 'Issue over-recorded with stock still in storeroom, and/or receipt under-captured.'),
 'D4': ('Over at area and site (unequal)', 'Mix of uncaptured issue and under-captured receipt.'),
 'A4': ('Real loss hidden by balancing', 'Staff counts showed the short and the site count confirms it, but balancing wrote it off. Reverse the balancing and charge the short.'),
 'C3': ('Missed issue hidden by balancing', 'Staff counts showed an over (uncaptured issue), balancing zeroed it. Paperwork fault - capture the issue, do not balance it away.'),
 'E1': ('Stock moving with no POS sales linked', 'Selling PLU / recipe is not depleting this stock item: staff counts go down, POS shows nothing, book never reduces so the site count shows a false short. Fix the link; do not charge anyone.'),
 'E2': ('Count / unit-of-measure capture error', 'Site count variance is more than 10x the item throughput - a count captured in the wrong unit (case vs each, g vs kg), on the wrong code, or a recipe that is not depleting the item. Recount and check the recipe before posting.'),
 'OK': ('Balanced', 'No action.'),
}

def classify(R, S, z, m):
    if abs(R) <= z and abs(S) <= z: return 'OK'
    if R < -z and S < -z:
        if abs(S - R) <= m: return 'A1'
        return 'A2' if S < R else 'A3'
    if abs(R) <= z and S < -z: return 'B1'
    if R > z and S < -z: return 'B2'
    if R < -z and abs(S) <= z: return 'C1'
    if R > z and abs(S) <= z: return 'C2'
    if R > z and S > z: return 'D1' if abs(S - R) <= m else 'D4'
    if abs(R) <= z and S > z: return 'D2'
    if R < -z and S > z: return 'D3'
    return 'OK'

def windows(r, st, pu, cost):
    DATA_START = r.Date.min()
    # item-level recon per day (all areas at site summed: the site count covers every area)
    rd = r.groupby(['BranchName', 'Code', 'Date']).agg(
        R=('QtyVariance_Amended', 'sum'), Ro=('QtyVariance_Original', 'sum'), POS=('QtyPOS_Amended', 'sum'),
        Issued=('QtyIssued_Amended', 'sum'), RandPU=('RandPU', 'max')).reset_index()
    meta = r.groupby(['BranchName', 'Code']).agg(Item=('StockItemDescription', 'first'), Area=('AreaDescription', lambda x: ', '.join(sorted(set(x)))),
        Location=('Location', 'first'), Mult=('Mult', 'max'), Weighed=('Weighed', 'max')).reset_index()
    keys = set(map(tuple, meta[['BranchName', 'Code']].values))
    HIB = rd.groupby('BranchName').Date.max()
    stc = st[[k in keys for k in map(tuple, st[['BranchName', 'Code']].values)]].sort_values(['BranchName', 'Code', 'Date'])
    out = []
    for (b, c), cs in stc.groupby(['BranchName', 'Code']):
        prev = None
        rx = rd[(rd.BranchName == b) & (rd.Code == c)]
        px = pu[(pu.BranchName == b) & (pu.Code == c)]
        for _, e in cs.iterrows():
            if e.Date > HIB[b]:
                continue          # count after the last recon day loaded - recon side incomplete
            if prev is None:
                gap = max(int(e.Gap), 1) if pd.notna(e.Gap) else 7
                start = e.Date - pd.Timedelta(days=int(gap))
            else:
                start = prev
            partial = start < DATE_START_SENTINEL(DATA_START)
            win = rx[(rx.Date > start) & (rx.Date <= e.Date)]
            pwin = px[(px.Date > start) & (px.Date <= e.Date)]
            out.append(dict(BranchName=b, Code=c, CountDate=e.Date, WindowStart=start + pd.Timedelta(days=1), Days=(e.Date - start).days,
                PartialRecon=partial, System=e.System, R=win.R.sum(), R_orig=win.Ro.sum(), POS=win.POS.sum(), Issued=win.Issued.sum(),
                S=e.CountVarQty, S_cost=e.CountVarCost, Book=e.BookQty, Counted=e.CountedQty, Cost=e.Cost, Reversed=e.Reversed,
                RepeatCount=e.CountLines > 1, RandPU=win.RandPU.max() if len(win) else np.nan,
                PurchQty=pwin[pwin.Type == 'Invoice'].Qty.sum(), CreditQty=pwin[pwin.Type == 'Credit'].Qty.sum(),
                Suppliers='; '.join(sorted(set(pwin.Supplier)))[:80]))
            prev = e.Date
    w = pd.DataFrame(out).merge(meta, on=['BranchName', 'Code'], how='left').merge(cost, on=['BranchName', 'Code'], how='left')
    w['UnitCost'] = w.Cost.where(w.Cost > 0, w.DetCost)
    w['UnitCost'] = w.UnitCost.fillna(w.RandPU / 1.15 * 0.7)
    base = np.where(w.Weighed, 0.2, 1.0)
    w['z'] = np.maximum(base, 0.02 * w.POS.abs())
    w['m'] = np.maximum(w.z, MATCH_PCT * np.maximum(w.R.abs(), w.S.abs()))
    w['Class'] = [classify(R, S, z, m) for R, S, z, m in zip(w.R, w.S, w.z, w.m)]
    w['mo'] = np.maximum(w.z, MATCH_PCT * np.maximum(w.R_orig.abs(), w.S.abs()))
    w['ClassOrig'] = [classify(R, S, z, m) for R, S, z, m in zip(w.R_orig, w.S, w.z, w.mo)]
    w.loc[(w.ClassOrig == 'A1') & (w.Class != 'A1'), 'Class'] = 'A4'
    w.loc[(w.ClassOrig == 'C2') & (w.Class == 'OK'), 'Class'] = 'C3'
    w.loc[(w.POS <= 0.2 * w.R_orig.abs()) & (w.R_orig < -np.maximum(3, w.Mult)) & (w.Days >= 2), 'Class'] = 'E1'
    w.loc[(w.S.abs() > 10 * np.maximum.reduce([w.POS.abs(), w.Issued.abs(), w.Mult, np.ones(len(w))])) & (w.S.abs() > 20), 'Class'] = 'E2'
    w['S_E1'] = np.where(w.Class == 'E1', w.S, 0)
    w['Storeroom'] = np.select([w.Class.eq('A2') | (w.Class.eq('A4') & (w.S < w.R_orig)), w.Class.isin(['B1', 'B2', 'D2', 'D3'])], [w.S - np.where(w.Class.eq('A4'), w.R_orig, w.R), w.S], 0)
    w['R_value'] = w.R * w.UnitCost
    w['S_value'] = w.S * w.UnitCost
    # enrichment flags
    fl = []
    for _, x in w.iterrows():
        f = []
        mult = 0 if x.Weighed else x.Mult
        if near_mult(x.R, mult): f.append(f'Recon over = {near_mult(x.R, mult)} x pack ({mult:g}) - missed issue sheet')
        if near_mult(-x.S, mult): f.append(f'Site short = {near_mult(-x.S, mult)} x pack ({mult:g}) - a whole pack missing')
        if near_mult(x.S, mult): f.append(f'Site over = {near_mult(x.S, mult)} x pack ({mult:g}) - a pack received but not captured')
        if x.Book < 0: f.append(f'Book was negative ({x.Book:g}) at count - GRV not captured or captured under another code')
        if x.CreditQty < 0 and x.S > 0: f.append(f'Credit note of {-x.CreditQty:g} in window and site over - stock credited but not returned?')
        if x.PurchQty > 0 and x.S > 0 and abs(x.S) >= 0.05 * x.PurchQty and x.Weighed: f.append(f'Weighed item over {x.S / x.PurchQty:.0%} of purchases - check receiving weights')
        if x.PurchQty > 0 and x.S < 0 and abs(abs(x.S) - x.PurchQty) <= 0.1 * x.PurchQty: f.append('Site short = the receipt in the window - delivery short or GRV captured twice')
        if x.Reversed: f.append('Count reversed at next count - count reliability')
        if x.RepeatCount: f.append('Counted more than once that day')
        if abs(x.R_orig - x.R) > max(1, 0.5 * abs(x.R_orig)): f.append(f'Balancing changed recon from {x.R_orig:g} to {x.R:g}')
        if x.PartialRecon: f.append('Window starts before recon data - part of the recon is missing')
        fl.append(' | '.join(f))
    w['Flags'] = fl
    w['Scenario'] = w.Class.map(lambda k: CLASSES[k][0]); w['Action'] = w.Class.map(lambda k: CLASSES[k][1])
    return w

def period(w):
    """Net every count window per item over the whole period - count errors that reverse at the next count cancel out."""
    g = w.groupby(['BranchName', 'Code']).agg(Item=('Item', 'first'), Area=('Area', 'first'), Counts=('S', 'size'),
        R=('R', 'sum'), R_orig=('R_orig', 'sum'), POS=('POS', 'sum'), Issued=('Issued', 'sum'), S=('S', 'sum'),
        UnitCost=('UnitCost', 'last'), Mult=('Mult', 'max'), Weighed=('Weighed', 'max'), PurchQty=('PurchQty', 'sum'),
        Reversals=('Reversed', 'sum'), S_E1=('S_E1', 'sum'), WindowClasses=('Class', lambda x: ' '.join(x))).reset_index()
    base = np.where(g.Weighed, 0.2, 1.0)
    g['z'] = np.maximum(base, 0.02 * g.POS.abs())
    g['m'] = np.maximum(g.z, MATCH_PCT * np.maximum(g.R.abs(), g.S.abs()))
    g['Class'] = [classify(R, S, z, m) for R, S, z, m in zip(g.R, g.S, g.z, g.m)]
    g['mo'] = np.maximum(g.z, MATCH_PCT * np.maximum(g.R_orig.abs(), g.S.abs()))
    g['ClassOrig'] = [classify(R, S, z, m) for R, S, z, m in zip(g.R_orig, g.S, g.z, g.mo)]
    g.loc[(g.ClassOrig == 'A1') & (g.Class != 'A1'), 'Class'] = 'A4'
    g.loc[(g.ClassOrig == 'C2') & (g.Class == 'OK'), 'Class'] = 'C3'
    nolink = w[w.Class == 'E1'].groupby(['BranchName', 'Code']).size().rename('E1n').reset_index()
    g = g.merge(nolink, on=['BranchName', 'Code'], how='left')
    g.loc[((g.POS <= 0.1 * g.R_orig.abs()) & (g.R_orig < -3)) | ((g.S < 0) & (g.S_E1 <= 0.5 * g.S)), 'Class'] = 'E1'
    g.loc[(g.S.abs() > 10 * np.maximum.reduce([g.POS.abs(), g.Issued.abs(), g.Mult, np.ones(len(g))])) & (g.S.abs() > 20), 'Class'] = 'E2'
    g['R_value'] = g.R * g.UnitCost; g['S_value'] = g.S * g.UnitCost; g['R_orig_value'] = g.R_orig * g.UnitCost
    g['Storeroom_value'] = np.select([g.Class.eq('A2'), g.Class.eq('A4') & (g.S < g.R_orig), g.Class.isin(['B1', 'B2', 'D2', 'D3'])],
                                     [g.S - g.R, g.S - g.R_orig, g.S], 0) * g.UnitCost
    g['Scenario'] = g.Class.map(lambda k: CLASSES[k][0]); g['Action'] = g.Class.map(lambda k: CLASSES[k][1])
    return g

def DATE_START_SENTINEL(d):
    return d - pd.Timedelta(days=1)

# ---------------------------------------------------------------- compliance: recon items counted Mon-Sun
def compliance(r, st):
    act = r.groupby(['BranchName', 'Code', 'Week']).agg(Item=('StockItemDescription', 'first'), Area=('AreaDescription', 'first'),
        Moves=('QtyPOS_Amended', lambda x: (x.abs() > 0).sum())).reset_index()
    st = st.copy(); st['Week'] = wk(st.Date)
    cnt = st.groupby(['BranchName', 'Code', 'Week']).size().rename('Counts').reset_index()
    c = act.merge(cnt, on=['BranchName', 'Code', 'Week'], how='left').fillna({'Counts': 0})
    c['Counted'] = c.Counts > 0
    loc = r.groupby(['BranchName', 'Code']).Location.first().rename('Location').reset_index()
    c = c.merge(loc, on=['BranchName', 'Code'], how='left')
    c['CountData'] = np.where(c.Location.isin(['NAMOS']), 'No count extract (' + c.Location + ')', 'Yes')
    st['Monday'] = st.Date.dt.weekday == 0
    mon = st[st.Monday].groupby(['BranchName', 'Code', 'Week']).size().rename('MondayCounts').reset_index()
    c = c.merge(mon, on=['BranchName', 'Code', 'Week'], how='left').fillna({'MondayCounts': 0})
    return c

# ---------------------------------------------------------------- cross-site pattern (recipe / UOM)
def cross_site(r, w):
    """Same product (by POS code) drifting the same way at 3+ sites -> recipe / UOM / master fault, not site theft."""
    g = r.groupby(['Code', 'BranchName']).agg(Item=('StockItemDescription', 'first'), R=('QtyVariance_Amended', 'sum'),
        Ro=('QtyVariance_Original', 'sum'), POS=('QtyPOS_Amended', 'sum'), Area=('AreaDescription', 'first')).reset_index()
    g = g[g.POS > 0]; g['Pct'] = g.Ro / g.POS
    sc = w.groupby(['Code', 'BranchName']).S.sum().rename('S').reset_index()
    g = g.merge(sc, on=['Code', 'BranchName'], how='left')
    s = g.groupby('Code').agg(Item=('Item', 'first'), Area=('Area', 'first'), Sites=('BranchName', 'size'),
        ShortSites=('Pct', lambda x: (x < -0.03).sum()), OverSites=('Pct', lambda x: (x > 0.03).sum()),
        CountShortSites=('S', lambda x: (x < 0).sum()), MedianPct=('Pct', 'median'), POS=('POS', 'sum'), RecOrig=('Ro', 'sum'),
        SiteList=('BranchName', lambda x: ', '.join(x))).reset_index()
    same = np.maximum(s.ShortSites, s.OverSites)
    s = s[(s.Sites >= 3) & (same >= 3) & (same / s.Sites >= 0.75)]
    rm = s.Item.str.contains(r'^(RRM|DRM|WRM|ERM|BAM|SRM)|RFG|\(K\)', regex=True) | s.Area.str.contains('BOH|Griller|Bakery|Soft Serve|Restaurant')
    s['Direction'] = np.where(s.ShortSites >= s.OverSites, 'SHORT', 'OVER')
    s['Likely'] = np.where(rm, 'Recipe card fault (ingredient quantity or yield factor captured wrongly) - same direction at most sites', 'Pack size / UOM / PLU-link fault at master level')
    return s.sort_values('MedianPct')

# ---------------------------------------------------------------- issue-on-receipt items (Steers potatoes and buns)
ISSUE_ON_RECEIPT = {('Steers BOH', 'V7'): 'Potatoes (pocket 10 kg)', ('Steers BOH', 'B1'): 'Bun 80g', ('Steers BOH', 'SBBB'): 'Burger bun 50g'}

def issue_on_receipt(r, pu, st):
    """Rule (Andries, 23 Sep 2026): these items go straight to the kitchen floor on delivery, so the recon issue
    must equal the GRV quantity on the day of receipt (next day allowed for late deliveries). Storeroom stock is always nil,
    so the whole-site count variance must equal the recon variance."""
    keys = [(a, c) for (a, c) in ISSUE_ON_RECEIPT]
    rr = r[[ (a, c) in keys for a, c in zip(r.AreaDescription, r.Code)]]
    iss = rr.groupby(['BranchName', 'AreaDescription', 'Code', 'Date']).agg(Issued=('QtyIssued_Amended', 'sum'), IssuedOrig=('QtyIssued_Original', 'sum'),
        POS=('QtyPOS_Amended', 'sum'), R=('QtyVariance_Amended', 'sum'), R_orig=('QtyVariance_Original', 'sum')).reset_index()
    lo = r.Date.min(); hib = rr.groupby('BranchName').Date.max()
    gr = pu[(pu.Type == 'Invoice') & pu.Code.isin([c for _, c in keys]) & (pu.Date >= lo)]
    gr = gr[gr.BranchName.isin(hib.index)]
    gr = gr[gr.Date <= gr.BranchName.map(hib)]
    gd = gr.groupby(['BranchName', 'Code', 'Date']).agg(GRV=('Qty', 'sum'), Invoices=('DocumentNumber', lambda x: ', '.join(map(str, x))),
        GrvFlags=('GrvFlag', lambda x: '; '.join(sorted({str(v) for v in x if pd.notna(v)})))).reset_index()
    area = rr.groupby(['BranchName', 'Code']).AreaDescription.first().reset_index()
    gd = gd.merge(area, on=['BranchName', 'Code'], how='left')
    d = iss.merge(gd, on=['BranchName', 'AreaDescription', 'Code', 'Date'], how='outer').fillna({'Issued': 0, 'IssuedOrig': 0, 'GRV': 0, 'POS': 0, 'R': 0, 'R_orig': 0})
    d = d.sort_values(['BranchName', 'Code', 'Date'])
    out = []
    for (b, c), x in d.groupby(['BranchName', 'Code']):
        x = x.set_index('Date')
        for dt, row in x.iterrows():
            nxt = x.Issued.get(dt + pd.Timedelta(days=1), 0); prv_g = x.GRV.get(dt - pd.Timedelta(days=1), 0)
            if row.GRV > 0:
                got = row.Issued + (nxt if row.Issued == 0 else 0)
                diff = got - row.GRV
                if abs(diff) <= max(0.01 * row.GRV, 0.5): res = 'OK - issued as received'
                elif got == 0: res = 'DELIVERY NOT ISSUED to the floor'
                elif diff < 0: res = f'PART-ISSUED - {-diff:g} of {row.GRV:g} not issued'
                else: res = f'OVER-ISSUED by {diff:g} against the GRV'
            elif row.Issued > 0 and prv_g == 0:
                res = 'ISSUED WITHOUT A DELIVERY - item is being held in a storeroom'
            else:
                continue
            out.append(dict(Site=b, Area=row.AreaDescription, Code=c, Item=ISSUE_ON_RECEIPT.get((row.AreaDescription, c), c), Date=dt,
                            GRV_qty=row.GRV, Invoices=row.Invoices if pd.notna(row.Invoices) else '', Issued_qty=row.Issued, Result=res,
                            GRV_flags=row.GrvFlags if pd.notna(row.GrvFlags) else ''))
    daily = pd.DataFrame(out)
    # period: GRV vs issued vs POS vs whole-site count
    st = st[st.BranchName.isin(hib.index)]
    s_ = st[(st.Date >= lo) & (st.Date <= st.BranchName.map(hib))].groupby(['BranchName', 'Code']).agg(SiteVar=('CountVarQty', 'sum'), SiteVarR=('CountVarCost', 'sum'), Counts=('Date', 'size')).reset_index()
    per = d.groupby(['BranchName', 'AreaDescription', 'Code']).agg(GRV=('GRV', 'sum'), Issued=('Issued', 'sum'), POS=('POS', 'sum'),
        R_orig=('R_orig', 'sum'), R=('R', 'sum')).reset_index().merge(s_, on=['BranchName', 'Code'], how='left')
    per['Item'] = [ISSUE_ON_RECEIPT.get((a, c), c) for a, c in zip(per.AreaDescription, per.Code)]
    per['GRV not issued'] = per.GRV - per.Issued
    per['Days GRV = issue'] = [((daily.Site == b) & (daily.Code == c) & daily.Result.str.startswith('OK')).sum() for b, c in zip(per.BranchName, per.Code)]
    per['Deliveries'] = [((daily.Site == b) & (daily.Code == c) & (daily.GRV_qty > 0)).sum() for b, c in zip(per.BranchName, per.Code)]
    per['Compliance'] = per['Days GRV = issue'].astype(int).astype(str) + ' of ' + per.Deliveries.astype(int).astype(str) + ' deliveries'
    def verdict(x):
        v = []
        if x.Deliveries and x['Days GRV = issue'] < x.Deliveries: v.append('Rule breached: not issued as received')
        if pd.notna(x.SiteVar) and x.SiteVar < -max(1, 0.03 * x.GRV) and abs(x.R) < abs(x.SiteVar) / 3:
            v.append(f'Site count short {x.SiteVar:,.1f} while recon balances - loss sits outside the recon because the delivery was not issued')
        if pd.notna(x.SiteVar) and abs(x.SiteVar) <= max(1, 0.03 * x.GRV): v.append('Site count balanced')
        return ' | '.join(v) or 'Compliant'
    per['Verdict'] = per.apply(verdict, axis=1)
    return daily, per

if __name__ == '__main__':
    r, st, pu, cost = load()
    print(r.shape, st.shape, pu.shape)
    w = windows(r, st, pu, cost)
    print(w.Class.value_counts())
