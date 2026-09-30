"""ZRP weekly stock recon run - convenience sites and Baobab Inn.

Usage:
    python run_weekly.py --input /mnt/user-data/uploads --out /mnt/user-data/outputs [--week-from 2026-09-14] [--asof 2026-09-23]

Reads the week's PumpIT reports from --input (see reference/required_reports.md), builds the weekly recon line list
from the Stock Recon Detail and the stock take registers, diagnoses every recon line,
and writes:
    ZRP_Stock_Recon_Master_Book_<week>.xlsx   the working book (Findings, Scorecard, registers)
    summary_<week>.json                       headline figures for the written brief
"""
import argparse, os, sys, json, warnings
import pandas as pd, numpy as np

warnings.filterwarnings('ignore')
HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import engine, weekly, cutoff
from engine import code
from playbook import BUCKET

ap = argparse.ArgumentParser()
ap.add_argument('--input', default='/mnt/user-data/uploads')
ap.add_argument('--out', default='/mnt/user-data/outputs')
ap.add_argument('--week-from', default=None, help='Monday of the week under review (default: the last full week in the Stock Recon Detail)')
ap.add_argument('--asof', default=None, help='Date the book is prepared (default: today)')
A = ap.parse_args()

engine.U = A.input
os.makedirs(A.out, exist_ok=True)
ASOF = pd.Timestamp(A.asof) if A.asof else pd.Timestamp.today().normalize()

# ------------------------------------------------------------------ issue multiples (uploaded copy wins over the bundled one)
for f in [engine.find('Issue_Multiple', required=False), engine.find('Multiples', required=False)]:
    if f and f.lower().endswith('.xlsx') and 'Proposed Multiples' in pd.ExcelFile(f).sheet_names:
        engine.MASTER = f
        break
print('Issue multiples:', os.path.basename(engine.MASTER))

# ------------------------------------------------------------------ shift recon (Stock Recon Detail), cut at each site's last complete day
REC = engine.L('Stock_Recon_Detail')
REC['_D'] = pd.to_datetime(REC.TransactionDate).dt.normalize()
if A.week_from:
    WF = pd.Timestamp(A.week_from)
else:
    # the last full Monday-Sunday week before the latest recon day in the extract
    last_day = REC._D.max()
    WF = last_day - pd.Timedelta(days=last_day.weekday() + 7) if last_day.weekday() != 6 else last_day - pd.Timedelta(days=6)
WF = WF - pd.Timedelta(days=WF.weekday())
WT = WF + pd.Timedelta(days=6)
weekly.WEEK_FROM, weekly.WEEK_TO = WF, WT
print(f'Week under review: {WF:%d %b} - {WT:%d %b %Y}')
if REC._D.min() > WF - pd.Timedelta(days=14):
    print(f'WARNING: the Stock Recon Detail starts {REC._D.min():%d %b}; export from {WF - pd.Timedelta(days=21):%d %b} so every stock take has its previous count.')
CUT, DAYS = cutoff.last_complete(REC)
held = REC[REC._D > REC.BranchName.map(CUT)]
HELD = held.groupby('BranchName')._D.agg(['min', 'max']).reset_index()
REC = REC[REC._D <= REC.BranchName.map(CUT)].drop(columns='_D').copy()
_L = engine.L
engine.L = lambda key: REC.copy() if key == 'Stock_Recon_Detail' else _L(key)

# the week's balanced shift lines (what the Balancing Screen shows)
BAL = REC.copy()
BAL['Code'] = BAL.POSCode.map(code)
BAL['Date'] = pd.to_datetime(BAL.TransactionDate).dt.normalize()
BAL = BAL[(BAL.Date >= WF) & (BAL.Date <= WT)].rename(columns={'TransactionDate': 'Transactiondate'})

# ------------------------------------------------------------------ period engine (count-to-count windows, shift tests)
r, st, pu, cost = engine.load()
w = engine.windows(r, st, pu, cost)
p = engine.period(w)
t = engine.shift_tests(r)
c = engine.compliance(r, st)
x = engine.cross_site(r, w)
ird, irp = engine.issue_on_receipt(r, pu, st)

# ------------------------------------------------------------------ stock master (last stock take date)
smf = engine.find('ALL_SITES_STOCK')
SM = pd.read_excel(smf)
SM['Code'] = SM.ProductCode.map(code)
SM['LST'] = pd.to_datetime(SM.LastStockTakeDate, errors='coerce')
SM.loc[SM.LST < pd.Timestamp('2000-01-01'), 'LST'] = pd.NaT

# last count per item: latest count date on the WinBranch stock take detail or the stock master (duplicate product records: latest wins)
WBD = _L('Winbranch_Stock_Take')
WBD['Code'] = WBD.ProductCode.map(code); WBD['LST'] = pd.to_datetime(WBD.CountDate, dayfirst=True).dt.normalize()
lt = WBD.groupby(['BranchName', 'Code']).LST.max().to_dict()
for k, v in SM.dropna(subset=['LST']).groupby(['BranchName', 'Code']).LST.max().items():
    if k not in lt or v > lt[k]:
        lt[k] = v
# optional: PumpIT "Stock Recon Items with last stock take date" - an in-week date wins over a later one
lsf = engine.find('Last_Stock_Take', required=False)
if lsf:
    LS = pd.read_csv(lsf) if lsf.lower().endswith('.csv') else pd.read_excel(lsf)
    if 'LastStockTake' not in LS.columns:                     # exported with a title block above the header
        LS = pd.read_excel(lsf, header=2)
    LS = LS[LS.BranchName.notna()].copy(); LS['Code'] = LS.POSCode.map(code); LS['LST'] = pd.to_datetime(LS.LastStockTake, errors='coerce')
    for k, v in LS.dropna(subset=['LST']).groupby(['BranchName', 'Code']).LST.max().items():
        cur = lt.get(k)
        if cur is None or (WF <= v <= WT) or (not (WF <= cur <= WT) and v > cur):
            lt[k] = v
    print('Last stock take dates:', os.path.basename(lsf))
else:
    print('WARNING: "Stock Recon Items with last stock take date" not uploaded - items counted with no variance will show as not counted.')

# the weekly recon line list, built from the PumpIT reports
import weeklines
STK = weeklines.stock_takes(WBD, _L('stk_01'), _L('Stock_take_VAR'))
LINES = weeklines.build(REC, STK, WF, WT)
SHEET = LINES
print(f'Weekly recon lines: {len(LINES):,}')

# ------------------------------------------------------------------ weekly diagnosis (column T)
g = weekly.run(LINES, (BAL,), BAL, lt)
IORD = g.attrs.get('ior_daily'); g.attrs = {}
if IORD is None: IORD = pd.DataFrame(columns=['Site', 'Code', 'Date', 'Received', 'Issued', 'Result'])
g['Bucket'] = g.Scenario.map(BUCKET).fillna('Balanced')

# chain breaks inside the week (balancing screen)
sh = BAL.sort_values(['BranchName', 'AreaNo', 'StockItemNo', 'Date', 'ShiftNo']).copy()
gg = sh.groupby(['BranchName', 'AreaNo', 'StockItemNo'])
sh['PrevCloseStaff'] = gg.QtyClose_Original.shift(1); sh['PrevCloseBal'] = gg.QtyClose_Amended.shift(1)
sh = sh[sh.PrevCloseStaff.notna()]
sh['Break'] = sh.QtyOpen_Original - sh.PrevCloseStaff
CBW = sh[sh.Break.abs() > 1e-6].copy()
CBW['Value'] = CBW.Break * CBW.RandPU

# ------------------------------------------------------------------ recipe consumption vs recon POS
rmf = engine.find('Raw_Material_Consumption', required=False)          # WinBranch sites
pcf = engine.find('ACT_against_THEO', required=False)                  # Pilot (Baobab Inn)
_cons = []
if rmf:
    rm = pd.read_excel(rmf)
    u = rm[rm.TYPE.astype(str).str.lower() == 'u'].copy()
    u['Code'] = u.CODE.map(code); u['Date'] = pd.to_datetime(u.DATE).dt.normalize()
    _cons.append(u.groupby(['BranchName', 'Code', 'Date']).QTY.sum().rename('Cons').reset_index())
if pcf:
    pc = pd.read_excel(pcf)
    pc['Code'] = pc.ProductCode.map(code); pc['Date'] = pd.to_datetime(pc.StockDate).dt.normalize()
    _cons.append(pc.groupby(['BranchName', 'Code', 'Date']).TheoreticalConsumptionQty.sum().rename('Cons').reset_index())
if _cons:
    cons = pd.concat(_cons, ignore_index=True).groupby(['BranchName', 'Code', 'Date']).Cons.sum().reset_index()
    cons = cons[cons.Cons != 0]
    pos = r.groupby(['BranchName', 'Code', 'Date']).agg(POS=('QtyPOS_Amended', 'sum')).reset_index()
    meta = r.groupby(['BranchName', 'Code']).agg(Item=('StockItemDescription', 'first'), Area=('AreaDescription', 'first')).reset_index()
    keys = cons[['BranchName', 'Code']].drop_duplicates().merge(meta[['BranchName', 'Code']])
    CVP = pos.merge(keys).merge(cons, on=['BranchName', 'Code', 'Date'], how='outer').merge(keys).fillna({'POS': 0, 'Cons': 0}).merge(meta, on=['BranchName', 'Code'])
    CVP = CVP[CVP.Date <= CVP.BranchName.map(CUT)]
    def _st(p_, c_):
        if abs(p_) < 1e-9 and c_ > 0: return 'recipe used, recon POS nil'
        if abs(p_ - c_) <= max(0.1 * max(abs(p_), abs(c_)), 0.5): return 'match'
        return 'both, differ >10%'
    CVP['st'] = [_st(a_, b_) for a_, b_ in zip(CVP.POS, CVP.Cons)]
else:
    print('No recipe consumption report found (Raw Material Consumption / Pilot Consumption ACT against THEO) - the Recipe vs Recon tab will be empty.')
    CVP = pd.DataFrame(columns=['BranchName', 'Code', 'Date', 'POS', 'Item', 'Area', 'Cons', 'st'])

# ------------------------------------------------------------------ build the book
PERIOD_FROM, PERIOD_TO = r.Date.min(), r.Date.max()
OUT = os.path.join(A.out, f'ZRP_Stock_Recon_Master_Book_{WF:%d%b}-{WT:%d%b%Y}.xlsx')
exec(open(os.path.join(HERE, 'book.py')).read())


# ------------------------------------------------------------------ headline figures for the written brief
_i = lambda v: int(v); _r = lambda v: round(float(v), 0)
summary = dict(
    week=WL, period=PL, prepared=f'{ASOF:%d %B %Y}', book=os.path.basename(OUT),
    cutoff={b: f'{d:%d %b}' for b, d in CUT.items()},
    held_back=[dict(site=x_.BranchName, first=f'{x_["min"]:%d %b}', last=f'{x_["max"]:%d %b}') for _, x_ in HELD.iterrows()],
    week_lines=_i(nlines), week_to_explain=_i(ninv),
    buckets={b: dict(lines=_i(bsum.n[b]), value_selling=_r(bsum.v[b])) for b in bsum.index},
    storeroom_loss_by_site={SHORT[k]: _r(v) for k, v in st_site.items()},
    not_counted={SHORT[k]: f'{_i(ncs.get(k, 0))} of {_i(lines_s[k])}' for k in lines_s.index},
    period_site_count_cost=_r(per_site.site.sum()), period_recon_balanced_cost=_r(per_site.bal.sum()),
    openings_below_previous_closing=dict(shifts=_i(len(below)), value=_r(below.Value.sum()),
        repair={k: dict(n=_i(rep.n[k]), value=_r(rep.v[k])) for k in rep.index},
        by_site={SHORT[k]: dict(n=_i(v.n), value=_r(v.v)) for k, v in bl_site.iterrows()}),
    balancing=dict(over_to_short=dict(n=_i(len(s7)), value=_r(s7.Value.sum())), short_increased=dict(n=_i(len(s8)), value=_r(s8.Value.sum())),
        latest_closing_changed=_i(len(s11)), by_site={SHORT[k]: dict(n=_i(v.n), value=_r(v.v)) for k, v in br_site.iterrows()}),
    issues_added_at_balancing=dict(n=_i(len(s4a)), value=_r(s4a.Value.sum()), by_site={SHORT[k]: _i(v.n) for k, v in la_site.iterrows()}),
    potatoes=[dict(site=SHORT[x_.BranchName], compliance=x_.Compliance, received=_r(x_.GRV), issued=_r(x_.Issued), site_count=_r(x_.SiteVar)) for _, x_ in pot.iterrows()],
    recipe=dict(items=_i(len(ca_)), item_days=_i(len(CVP)), match=_i((CVP.st == 'match').sum()) if len(CVP) else 0, items_disagree=_i(len(cvn))),
    stale_counts_over_16_days={SHORT[k]: _i(v) for k, v in stale_site.items()},
    control_actions=_i(len(ca)),
)
SJ = os.path.join(A.out, f'summary_{WF:%d%b}-{WT:%d%b%Y}.json')
json.dump(summary, open(SJ, 'w'), indent=1, default=str)
print('saved', SJ)
