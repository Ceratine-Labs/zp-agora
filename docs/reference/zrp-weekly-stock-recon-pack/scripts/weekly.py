"""ZRP weekly (7-day) stock recon investigation engine.
Input : the weekly recon line list built from the PumpIT reports (weeklines.py)
        + evidence extracts (Omni GRVs, Aura GRVs, Aura stk 01, shift-level recon lines).
Output: one diagnosed reason per line for column T, with scenario, evidence, accountability and next step.
Sign convention: + = over, - = short. Storeroom = stock take - recon."""
import pandas as pd, numpy as np, re, csv, io
import engine
from engine import code
L = lambda key: engine.L(key)

WEEK_FROM, WEEK_TO = None, None   # set by run_weekly.py
VIRTUAL = re.compile(r'Lotto|Electricity|Airtime|Water Sales', re.I)
WEIGHED = re.compile(r'1000G|P/KG|\bKG\b|\bKg\b|Litre|\(K\)|\(L\)', re.I)

def num(s):
    return pd.to_numeric(s.astype(str).str.replace(',', '').str.strip(), errors='coerce')

def load_sheet(path='gs.csv'):
    if isinstance(path, pd.DataFrame):
        g = path.copy()
        g['SheetRow'] = np.arange(len(g)) + 1                          # line number in the weekly list
        g['ExistingT'] = ''
    elif str(path).endswith('.xlsx'):
        g = pd.read_excel(path, sheet_name='Stock Variance', header=2, dtype=str, keep_default_na=False)
        g['SheetRow'] = np.arange(len(g)) + 4
        g = g[g.BranchName.str.strip() != ''].copy()
        g['ExistingT'] = (g.get('Comment', '').astype(str).str.strip() + ' ' + g.get('Head office feedback', '').astype(str).str.strip()).str.strip()
    else:
        g = pd.read_csv(path, skiprows=2, header=0, dtype=str, keep_default_na=False)
        g = g[g['Search key'].str.strip() != ''].copy()
        g['SheetRow'] = np.arange(len(g)) + 4                        # row number in the Google Sheet
        g['ExistingT'] = g.iloc[:, 19].str.strip()
    for c, n in [('RandPU', 'PU'), ('LossQTY', 'R'), ('LossValue', 'Rv'), ('STakeQTY', 'S'), ('STakeValue', 'Sv'),
                 ('Qty Variance', 'ST'), ('Rand Variance', 'STv'), ('Qty Var previous STake.', 'P')]:
        g[n] = num(g[c])
    g['Code'] = g.POSCode.map(code)
    g['Weighed'] = g.StockItemDescription.str.contains(WEIGHED)
    return g

# ------------------------------------------------------------------ evidence
def evidence():
    ev = {}
    o = L('Omni_Supplier'); o['Code'] = o.ItemCode.map(code); o['Date'] = pd.to_datetime(o.EODDate).dt.normalize()
    o = o[['BranchName', 'Code', 'Date', 'TransactionType', 'Qty', 'DocumentNumber']].rename(columns={'DocumentNumber': 'Doc'})
    a = L('Aura_09'); a['Code'] = a.StockCode.map(code); a['Date'] = pd.to_datetime(a.TransactionDate).dt.normalize()
    a['TransactionType'] = np.where(a.TransactionType.eq('Credit note'), 'C', 'I')
    a = a[['BranchName', 'Code', 'Date', 'TransactionType', 'Quantity', 'InvoiceReference', 'ExceptionFlag']].rename(
        columns={'Quantity': 'Qty', 'InvoiceReference': 'Doc', 'ExceptionFlag': 'Flag'})
    ev['grv'] = pd.concat([o, a], ignore_index=True)
    k = L('stk_01'); k['Code'] = k.StockNo.map(code); k['D'] = pd.to_datetime(k.CountDate)
    k = k[(k.D >= WEEK_FROM) & (k.D <= WEEK_TO)]
    ev['aura'] = k.groupby(['BranchName', 'Code']).VarianceQty.sum().to_dict()
    # WinBranch counts in the week: the variance-detail export omits zero-variance counts, so also use the last stock take date
    wd = L('Winbranch_Stock_Take'); wd['Code'] = wd.ProductCode.map(code); wd['D'] = pd.to_datetime(wd.CountDate, dayfirst=True)
    wd = wd[(wd.D >= WEEK_FROM) & (wd.D <= WEEK_TO)]
    ev['wb_counted'] = set(zip(wd.BranchName, wd.Code))
    ev['wb_last'] = {}
    m = pd.read_excel(engine.MASTER, sheet_name='Proposed Multiples'); m['Code'] = m.POSCode.map(code)
    ev['mult'] = m.drop_duplicates(['BranchName', 'Code']).set_index(['BranchName', 'Code'])['Issue Multiple'].to_dict()
    return ev

def shift_evidence(sh):
    """sh: shift lines with BranchName, Code, Date, ShiftNo, Open/Issued/Close/Var original+amended.
    Returns per item for the week: chain breaks (opening below previous closing), qty vanished, wrong repairs,
    overs turned into shorts, shorts increased, issues added at balancing."""
    sh = sh.sort_values(['BranchName', 'AreaNo', 'Code', 'Date', 'ShiftNo']).copy()
    g = sh.groupby(['BranchName', 'AreaNo', 'Code'])
    sh['pco'] = g.QtyClose_Original.shift(1); sh['pca'] = g.QtyClose_Amended.shift(1)
    sh['brk'] = np.where(sh.pco.notna(), sh.QtyOpen_Original - sh.pco, 0)
    sh['wrong'] = (sh.brk != 0) & ((sh.pca - sh.pco).abs() > 1e-6)
    sh['still'] = (sh.brk != 0) & ((sh.QtyOpen_Amended - sh.pca).abs() > 1e-6)
    sh['s7'] = (sh.QtyVariance_Original > 0.5) & (sh.QtyVariance_Amended < -0.5)
    sh['s8'] = (sh.QtyVariance_Original < 0) & (sh.QtyVariance_Amended < sh.QtyVariance_Original - 0.5)
    sh['s4a'] = sh.QtyIssued_Amended > sh.QtyIssued_Original + 1e-6
    sh['staff'] = sh.QtyVariance_Original + sh.brk          # staff count with the opening repaired to the previous closing
    wk = sh[(sh.Date >= WEEK_FROM) & (sh.Date <= WEEK_TO)]
    return wk.groupby(['BranchName', 'Code']).agg(
        BrkBelow=('brk', lambda x: (x < -1e-6).sum()), QtyVanished=('brk', lambda x: -x[x < 0].sum()),
        BrkAbove=('brk', lambda x: (x > 1e-6).sum()), WrongRepair=('wrong', 'sum'), StillOpen=('still', 'sum'),
        S7=('s7', 'sum'), S8=('s8', 'sum'), IssuesAdded=('s4a', 'sum'), Issued=('QtyIssued_Amended', 'sum'),
        POS=('QtyPOS_Amended', 'sum'), StaffVar=('staff', 'sum'), BalVar=('QtyVariance_Amended', 'sum')).reset_index()

# ------------------------------------------------------------------ diagnosis
ACC = {'AREA': 'Area staff on shift', 'KEY': 'Manager / key holder (storeroom)', 'GRV': 'Receiving clerk / admin (GRV & invoices)',
       'COUNT': 'Stock controller (count & posting)', 'SYS': 'IT / master data', 'MGMT': 'Site manager (balancing & recon sheet)', 'NONE': 'None'}

def diagnose(x, ev, she):
    PU = x.PU if pd.notna(x.PU) else 0
    R = x.R if pd.notna(x.R) else 0.0
    S, P = x.S, x.P
    z = 0.2 if x.Weighed else 0.5
    close = lambda a, b: abs(a - b) <= max(z, 0.15 * max(abs(a), abs(b)))
    mult = ev['mult'].get((x.BranchName, x.Code), 0) or 0
    kmult = lambda q: (round(q / mult) if mult >= 2 and not x.Weighed and q > 0 and round(q / mult) >= 1 and abs(q - round(q / mult) * mult) <= 0.1 * mult else 0)
    evd, code_, reason, acc, step = [], '', '', 'NONE', ''

    # shift-level evidence (where the balancing screen is loaded)
    se = she.get((x.BranchName, x.Code))
    if se is not None:
        if se.BrkBelow: evd.append(f'{int(se.BrkBelow)} shift(s) opened BELOW the previous closing ({se.QtyVanished:g} vanished between shifts)')
        if se.WrongRepair: evd.append(f'{int(se.WrongRepair)} break(s) "fixed" by changing the previous closing')
        if se.StillOpen: evd.append(f'{int(se.StillOpen)} break(s) still open after balancing')
        if se.S7: evd.append(f'{int(se.S7)} over(s) turned into shorts by balancing')
        if se.S8: evd.append(f'{int(se.S8)} short(s) increased by balancing')
        if se.IssuesAdded: evd.append(f'{int(se.IssuesAdded)} issue(s) added at balancing (not written up on shift)')
    # GRV evidence
    gr = ev['grv'][(ev['grv'].BranchName == x.BranchName) & (ev['grv'].Code == x.Code)]
    gw = gr[(gr.Date >= WEEK_FROM) & (gr.Date <= WEEK_TO)]
    gl = gr[(gr.Date > WEEK_TO) & (gr.Date <= WEEK_TO + pd.Timedelta(days=3)) & (gr.TransactionType.str.upper() == 'I')]
    if len(gw): evd.append(f'GRVs in week: {gw[gw.TransactionType.str.upper()=="I"].Qty.sum():g} received' + (f', {-gw[gw.TransactionType.str.upper()=="C"].Qty.sum():g} credited' if (gw.TransactionType.str.upper()=="C").any() else ''))
    if 'Flag' in gw and gw.Flag.notna().any(): evd.append('GRV exception: ' + '; '.join(sorted(set(gw.Flag.dropna()))))

    txt = x.STakeQTY.strip()
    notcounted_note = ''
    lst_ = ev['wb_last'].get((x.BranchName, x.Code))
    if x.Posname == 'WINBRANCH' and pd.isna(S) and txt == 'No Count' and lst_ is not None and pd.notna(lst_) and WEEK_FROM <= lst_ < WEEK_TO + pd.Timedelta(days=1):
        S = 0.0; txt = '0'
        evd.append(f'counted {lst_:%d %b} with no variance (last stock take date) - "No Count" corrected: the export has no line for a nil variance')
    if x.Posname == 'WINBRANCH' and pd.notna(S) and S == 0 and (x.BranchName, x.Code) not in ev['wb_counted']:
        lst = ev['wb_last'].get((x.BranchName, x.Code))
        if lst is None or pd.isna(lst) or not (WEEK_FROM <= lst < WEEK_TO + pd.Timedelta(days=1)):
            S = np.nan
            if lst is not None and pd.notna(lst) and lst > WEEK_TO:
                notcounted_note = f' Counted again {lst:%d %b}; a nil-variance count inside the week does not appear in the WinBranch stock take detail, so the week\'s count cannot be proven.'
            else:
                notcounted_note = (f' Last stock take {lst:%d %b %Y}' if lst is not None and pd.notna(lst) else ' No stock take on record') + ' - a 0 is not a count.'
    if x.Posname == 'AURA' and (x.BranchName, x.Code) in ev.get('aura', {}):
        reg = round(ev['aura'][(x.BranchName, x.Code)], 3)
        if pd.isna(S) or abs(reg - S) > z:
            evd.append(f'Aura stk 01 count register shows {reg:+g} for the week; the weekly list shows {x.STakeQTY.strip()} - the register is used')
            S = reg; txt = str(reg)
    if VIRTUAL.search(x.AreaDescription) or re.search(r'AIRTIME|ELECTRIC|LOTTO|VOUCHER|REFILL WATER', x.StockItemDescription, re.I):
        code_, acc = 'V', 'SYS'
        reason = 'Virtual / non-stock line (airtime, electricity, lotto, refill water) - not physical stock; remove from the recon and stock count.'
        if pd.notna(S) and abs(S) * max(PU, 1) > 100000: reason += f' Stock take quantity {S:,.0f} is a capture error and must be reversed.'
        step = 'Remove the line from the recon item list; reverse any stock-take posting.'
    elif txt == 'Auto-Consumption':
        code_, acc = 'AC', 'MGMT'
        reason = f'Production item auto-consumed by recipe - no physical stock take. Recon {R:+g} can only be proven against the production sheet and issues.'
        step = 'Compare production captured vs issued raw material for each day; check that the production screen was completed every shift.'
    elif pd.isna(S):
        code_ = 'NC'
        namos = ' The shop runs on NAMOS - no NAMOS stock take is linked to the recon.' if x.Posname == 'NAMOS' else ''
        if R < -z:
            acc, reason = 'COUNT', f'NOT COUNTED this week - recon short {R:g} ({R*PU:,.0f}) cannot be split between recon point and storeroom. Monday count rule not followed.'
            step = 'Count on Monday; until counted, treat the recon short as the area staff\'s.'
        elif R > z:
            acc, reason = 'COUNT', f'NOT COUNTED this week - recon over {R:g}' + (f' = {kmult(R)} x pack of {mult:g}: an issue not written up.' if kmult(R) else ' - most likely an issue not written up.') + ' Count to confirm.'
            step = 'Find the missing issue sheet; count on Monday.'
        else:
            acc, reason = 'COUNT', 'NOT COUNTED this week - recon balanced, but the item must still be counted on Monday.'
            step = 'Count on Monday.'
        reason += namos + notcounted_note
    elif abs(S) > 50 * max(abs(R), 1) and abs(S) * max(PU, 1) > 5000 and not (pd.notna(P) and close(S, -P)):
        code_, acc = 'E2', 'COUNT'
        reason = f'Count capture error - stock take variance {S:,.0f} is out of all proportion to the recon ({R:g}). Wrong unit (case vs each) or wrong code.'
        step = 'Recount and correct the posting before it hits GP.'
    else:
        ST = S - R
        x = x.copy(); x['ST'] = ST
        # primary scenario from recon (R) vs whole-site count (S)
        if abs(R) <= z and abs(S) <= z: code_ = 'OK'
        elif R < -z and S < -z: code_ = 'A1' if close(R, S) else ('A2' if S < R else 'A3')
        elif abs(R) <= z and S < -z: code_ = 'B1'
        elif R > z and S < -z: code_ = 'B2'
        elif R < -z and abs(S) <= z: code_ = 'C1'
        elif R > z and abs(S) <= z: code_ = 'C2'
        elif R > z and S > z: code_ = 'D1' if close(R, S) else 'D4'
        elif abs(R) <= z and S > z: code_ = 'D2'
        elif R < -z and S > z: code_ = 'D3'
        base = {
            'OK': ('NONE', 'Balanced - recon and stock take agree.', ''),
            'A1': ('AREA', f'Confirmed loss at the recon point: recon short {R:g} and the whole-site count short {S:g} agree. The stock went missing in the staff member\'s possession.', 'Charge the shift(s) that carry the short; check the till for voids and no-sales on those shifts.'),
            'A2': ('KEY', f'Loss at the recon point ({R:g}) PLUS a storeroom loss of {ST:g}. The storeroom part left without an issue sheet and without a sale.', 'Charge the area short; the storeroom loss goes to the key holder - check who opened the storeroom and the issue book.'),
            'A3': ('MGMT', f'Recon short {R:g} is bigger than the site short {S:g} - {S-R:g} of the recon short is paperwork (issue written but not handed over, or closing counted low).', 'Check the issue sheets against what was physically handed over before charging staff.'),
            'B1': ('KEY', f'Storeroom loss {S:g}: the recon balances but the site is short. Stock left the storeroom with no issue and no sale.', 'Key-holder accountability: check deliveries against invoices (short delivery / GRV captured twice) and the storeroom key log.'),
            'B2': ('KEY', f'Stock moved to the recon point without an issue sheet (recon over {R:g}) AND the site is still short {S:g} - storeroom control failure.', 'Capture the missing issue; investigate the storeroom loss with the key holder.'),
            'C1': ('MGMT', f'Recon short {R:g} is NOT supported by the stock take (site {S:g}) - issue written but not handed over, captured twice, or closing counted low. Not a loss.', 'Do not charge staff. Correct the issue or closing on the recon.'),
            'C2': ('MGMT', f'Issue not written up: recon over {R:g}, whole site balanced. Paperwork failure, not a loss.', 'Capture the missing issue on the shift it happened; warn the issuer.'),
            'D1': ('GRV', f'Over at the recon point and on site by the same amount ({R:g} / {S:g}): the receipt was under-captured (weight/unit/free stock) or the till sold this code for another product.', 'Check the GRVs for this item against the supplier invoice; check the till for mis-rings of a sibling product.'),
            'D2': ('GRV', f'Storeroom OVER {S:g} with the recon balanced - this is NOT a loss: an invoice not captured, captured short or captured late, a credit note raised but stock kept, or last week\'s count wrong.', 'Match every delivery of the item to a captured GRV; check credit notes; check last week\'s count.'),
            'D3': ('MGMT', f'Recon short {R:g} but the site is over {S:g}: issue over-recorded with stock still in the storeroom, and/or a receipt under-captured.', 'Check the issue sheets and the GRVs; do not charge staff until resolved.'),
            'D4': ('GRV', f'Over at the recon point ({R:g}) and on site ({S:g}) by different amounts: an issue not written up plus a receipt under-captured.', 'Find the missing issue sheet and the uncaptured invoice.'),
        }[code_]
        acc, reason, step = base
        # previous-week overlay: reversals and repeats
        if pd.notna(P) and abs(S) > z and abs(P) > z:
            if S * P < 0 and close(S, -P) and abs(R) > z:
                reason += (f' The stock take ({S:+g}) reverses last week\'s {P:+g}: the site figure is a correction of last week\'s posting, '
                           'not a new loss or gain - last week was posted without investigation.')
            elif S * P < 0 and close(S, -P):
                code_, acc = 'RV', 'COUNT'
                reason = (f'REVERSAL: last week\'s stock take {P:+g} is reversed this week {S:+g}. Nothing was lost or gained - last week\'s count or '
                          f'invoice posting was wrong and was posted without investigation.')
                step = 'Find what was wrong last week (count, late or duplicate invoice) and why it was posted without investigation.'
            elif S * P < 0:
                reason += f' Partly reverses last week\'s {P:+g} - check last week\'s count and invoices.'
            elif S < 0 and P < 0:
                reason += f' SHORT TWO WEEKS RUNNING (last week {P:g}) - a trend, not timing.'
                if acc in ('GRV', 'NONE'): acc = 'KEY'
            elif S > 0 and P > 0:
                reason += f' OVER TWO WEEKS RUNNING (last week {P:+g}) - invoices are consistently not captured or captured short.'
        # pack-size evidence
        if kmult(R): evd.append(f'recon over = {kmult(R)} x pack of {mult:g} (missed issue sheet)')
        if S < 0 and kmult(-S): evd.append(f'site short = {kmult(-S)} x pack of {mult:g} (whole pack gone)')
        if S > 0 and kmult(S): evd.append(f'site over = {kmult(S)} x pack of {mult:g} (a pack received but not captured)')
        if code_ in ('C1', 'D3', 'A3') and S == 0 and R < -z:
            evd.append('stock take exactly nil while the recon is short - confirm with an independent surprise recount (the count may have been matched to the book)')
        if len(gl) and S > z:
            hit = gl[(gl.Qty - S).abs() <= max(z, 0.15 * S)]
            if len(hit): evd.append(f'delivery of {hit.Qty.iloc[0]:g} captured late on {hit.Date.iloc[0]:%d %b} - stock was on hand at the count')
        if len(gw) and S < -z:
            gi = gw[gw.TransactionType.str.upper() == 'I']
            if len(gi) and ((gi.Qty + S).abs() <= max(z, 0.15 * abs(S))).any(): evd.append('site short equals one delivery - short delivery or GRV captured twice')
    # no POS sales linked to a recon item that is being used (recipe / PLU link fault)
    if se is not None and code_ not in ('V', 'AC') and R < -z and (pd.isna(S) or S < -z) and se.POS <= 0.1 * abs(se.StaffVar) and se.StaffVar < -max(3, mult):
        code_, acc = 'E1', 'SYS'
        reason = (f'No till sales linked: staff counts fell by {-se.StaffVar:g} during the week but the POS quantity on the recon is {se.POS:g}. '
                  'The selling code or recipe is not reducing this stock item, so the recon (and the stock take book) show a false short.')
        step = 'Link the selling PLU / recipe to this stock item; re-run the recon; do not charge anyone until it balances.'
    # Steers potatoes and buns: issued on receipt, never held in a storeroom
    io = ev.get('ior', {}).get((x.BranchName, x.Code))
    if io is not None:
        evd.append(f'issue-on-receipt: {io["ok"]} of {io["n"]} deliveries issued as received; received {io["grv"]:g}, issued {io["iss"]:g}')
        if io['n'] and io['ok'] < io['n'] and pd.notna(S) and S < -z and code_ not in ('E1',):
            code_, acc = 'IR', 'KEY'
            reason = (f'Steers issue-on-receipt item NOT issued as received ({io["ok"]} of {io["n"]} deliveries): {io["grv"]:g} received, {io["iss"]:g} issued to the floor. '
                      f'The site is short {S:g} while the recon shows {R:+g} - the stock went missing between delivery and the kitchen floor, outside the recon.')
            step = 'Issue every delivery in full on the day it is received (issue = GRV). Key holder to account for the difference; check for duplicate GRVs and weigh pockets on receipt.'
    # recon capture error: a week's recon variance far larger than the site count and of high value
    if code_ not in ('V', 'AC', 'NC', 'E2', 'E1', 'IR') and abs(R) > 5 * max(abs(S) if pd.notna(S) else 0, z) and abs(R * PU) >= 5000:
        code_, acc = 'E3', 'MGMT'
        reason = (f'Recon capture error: recon {R:+g} ({R*PU:,.0f}) in one week while the whole-site count shows {S:+g}. The recon figure is not real - '
                  'an opening not carried from the previous closing, a missed shift, or a quantity keyed wrongly.')
        step = 'Open the balancing screen for the week: correct the OPENING to the previous closing, capture any missing shift, re-run.'
    # chain-break evidence overrides accountability for the area line
    if se is not None and se.BrkBelow and code_ not in ('V', 'OK'):
        reason += f' OPENING/CLOSING MANIPULATION: {int(se.BrkBelow)} shift(s) opened below the previous closing this week.'
        acc = 'MGMT'
        step = (step + ' ' if step else '') + 'Amend the OPENING to the previous closing (never the closing) and re-run the recon.'
    val = max(abs(R * PU), abs((S if pd.notna(S) else 0) * PU), abs((x.ST if pd.notna(x.ST) else 0) * PU))
    if code_ == 'V' or (code_ == 'E2' and pd.notna(S) and abs(S) * max(PU, 1) > 100000): val = abs(R * PU)
    S_used = S if pd.notna(S) else np.nan
    return pd.Series(dict(S_used=S_used, ST_used=(S_used - R) if pd.notna(S_used) else np.nan, Scenario=code_, Reason=reason, Evidence=' | '.join(evd), Accountable=ACC[acc], NextStep=step, ValueAtRisk=round(val, 2)))

SCEN = {'OK': 'Balanced', 'A1': 'Confirmed loss at recon point', 'A2': 'Recon point + storeroom loss', 'A3': 'Recon short bigger than site loss',
        'B1': 'Storeroom loss', 'B2': 'Uncaptured issue + storeroom loss', 'C1': 'Recon short not supported by count', 'C2': 'Issue not written up',
        'D1': 'Receipt under-captured / mis-ring', 'D2': 'Storeroom over - invoice not captured / late', 'D3': 'Issue over-recorded / receipt under-captured',
        'D4': 'Missed issue + receipt under-captured', 'RV': 'Stock-take reversal vs last week', 'NC': 'Not counted', 'AC': 'Auto-consumption (production)',
        'E2': 'Count capture error', 'E3': 'Recon capture error', 'E1': 'No till sales linked (recipe / PLU)', 'IR': 'Potatoes / buns not issued on receipt', 'V': 'Virtual / non-stock line'}

def issue_on_receipt_week(bal, ev):
    keys = {'V7', 'B1', 'SBBB'}
    b = bal[bal.AreaDescription.eq('Steers BOH') & bal.Code.isin(keys) & (bal.Date >= WEEK_FROM) & (bal.Date <= WEEK_TO)]
    iss = b.groupby(['BranchName', 'Code', 'Date']).QtyIssued_Amended.sum()
    gr = ev['grv']; gr = gr[gr.Code.isin(keys) & (gr.Date >= WEEK_FROM) & (gr.Date <= WEEK_TO) & (gr.TransactionType.str.upper() == 'I')]
    out, daily = {}, []
    for (br, c), x in b.groupby(['BranchName', 'Code']):
        g_ = gr[(gr.BranchName == br) & (gr.Code == c)].groupby('Date').Qty.sum()
        ok = 0
        for d, q in g_.items():
            got = iss.get((br, c, d), 0) or iss.get((br, c, d + pd.Timedelta(days=1)), 0)
            good = abs(got - q) <= max(0.01 * q, 0.5); ok += good
            daily.append(dict(Site=br, Code=c, Date=d, Received=q, Issued=got, Result='Issued as received' if good else ('Not issued' if got == 0 else f'Issued {got:g} of {q:g}')))
        out[(br, c)] = dict(n=len(g_), ok=int(ok), grv=float(g_.sum()), iss=float(x.QtyIssued_Amended.sum()))
    return out, pd.DataFrame(daily)

def run(sheet='gs.csv', shift_frames=(), bal=None, last_take=None):
    g = load_sheet(sheet)
    ev = evidence()
    if last_take is not None: ev['wb_last'] = last_take
    if bal is not None:
        ev['ior'], ev['ior_daily'] = issue_on_receipt_week(bal, ev)
    she = {}
    for f in shift_frames:
        e = shift_evidence(f)
        she.update({(b, c): r_ for (b, c), r_ in zip(zip(e.BranchName, e.Code), e.itertuples())})
    out = g.apply(lambda x: diagnose(x, ev, she), axis=1)
    g = pd.concat([g, out], axis=1)
    g['ScenarioName'] = g.Scenario.map(SCEN)
    g['Priority'] = np.select([g.Scenario.isin(['OK']), g.ValueAtRisk >= 500, g.ValueAtRisk >= 100], ['-', 'High', 'Medium'], 'Low')
    g.loc[g.Scenario.isin(['RV', 'E2', 'E3']) & (g.ValueAtRisk >= 100), 'Priority'] = 'High'
    g.loc[g.Reason.str.contains('MANIPULATION'), 'Priority'] = 'High'
    g.attrs['ior_daily'] = ev.get('ior_daily')
    return g
