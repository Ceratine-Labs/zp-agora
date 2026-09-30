# executed inside book.py - builds 'Read Me' and 'Findings'
def R(v): return f'R{abs(v):,.0f}'
wk = g
nlines = len(wk); ninv = int((wk.Scenario != 'OK').sum())
bsum = wk.groupby('Bucket').agg(n=('R', 'size'), v=('ValueAtRisk', 'sum'))
bn = lambda b_: int(bsum.n.get(b_, 0)); bv = lambda b_: float(bsum.v.get(b_, 0))
st_site = wk[wk.Bucket == 'Storeroom loss'].groupby('BranchName').ValueAtRisk.sum().sort_values(ascending=False)
below = cb[cb.Break < 0]; above = cb[cb.Break > 0]
rep = below.groupby('Repair').agg(n=('Value', 'size'), v=('Value', 'sum'))
bl_site = below.groupby('BranchName').agg(n=('Value', 'size'), v=('Value', 'sum')).sort_values('v')
wk_below = CBW[CBW.Break < 0]
s7 = bb[bb.Test.str.startswith('S7')]; s8 = bb[bb.Test.str.startswith('S8')]; s11 = bb[bb.Test.str.startswith('S11')]
br_site = bb[bb.Test.str.match(r'S(7|8) ')].groupby('BranchName').agg(n=('Value', 'size'), v=('Value', 'sum')).sort_values('v')
s4a = isf[isf.Test.str.startswith('S4a')]; s4b = isf[isf.Test.str.startswith('S4b')]
la_site = s4a.groupby('BranchName').agg(n=('Value', 'size'), v=('Value', 'sum')).sort_values('v', ascending=False)
pot = irp[irp.Code == 'V7']
paper_site = wk[wk.Bucket == 'Paperwork - recon not confirmed'].groupby('BranchName').ValueAtRisk.agg(['size', 'sum']).sort_values('sum', ascending=False)
over_site = wk[wk.Bucket == 'Storeroom over / invoices'].groupby('BranchName').ValueAtRisk.sum().sort_values(ascending=False)
ncs = wk[wk.Scenario == 'NC'].groupby('BranchName').size(); lines_s = wk.groupby('BranchName').size()
aura_mis = int(wk.Evidence.str.contains('Aura stk 01').sum())
e1_week = wk[wk.Scenario == 'E1']
per_site = p.groupby('BranchName').agg(site=('S_value', 'sum'), staff=('R_orig_value', 'sum'), bal=('R_value', 'sum'))
top = lambda s_, k=3: ', '.join(f'{SHORT.get(i_, i_)} {R(v_)}' for i_, v_ in s_.head(k).items())

rm = wb.create_sheet('Read Me', 0); rm.sheet_properties.tabColor = '0E6E6A'
rm['A1'] = 'ZRP Stock Recon Master Book - Convenience sites and Baobab Inn'; rm['A1'].font = Font(name=F, bold=True, size=16, color=NAVY)
_cutx = [f'{SHORT[b_]} {d_:%d %b}' for b_, d_ in sorted(CUT.items()) if d_ < PERIOD_TO]
rm['A2'] = f'Period {PL}, with the weekly working list for {WL}. Prepared {ASOF:%d %B %Y}. Each site runs to its last complete recon day' + (': ' + ', '.join(_cutx) + f'; all others {PERIOD_TO:%d %b}.' if _cutx else f' ({PERIOD_TO:%d %b} for all sites).'); rm['A2'].font = SF
rows_ = [
 ('HOW THIS BOOK WORKS', None),
 ('1', 'Findings - what is going on, what people are doing, what must be done. Read first.'),
 ('2', 'Site Scorecard - live: open and closed lines per site update as the Status columns are worked.'),
 ('3', 'Scenario Matrix - how every variance is read, who is accountable, what closes it, and the ERP control that prevents it.'),
 ('4', 'Control Actions - management actions that stop the leaks at source. Ops Exec owns this tab.'),
 ('5', 'Item Register - the weekly working list: every recon line with its diagnosed reason (the Reason column). Site managers and stock controllers work this tab.'),
 ('6', 'Opening vs Closing, Balancing Breaches, Issue Sheet Failures - shift-level evidence. Site managers explain each line.'),
 ('7', 'Potatoes & Buns, Count Compliance - the two standing controls.'),
 ('8', f'{PTAB}, Cross-Site Patterns, Count Recency, Recipe vs Recon - reference evidence.'),
 ('WORKING THE YELLOW COLUMNS', None),
 ('Owner', 'The person investigating the line (a name, not a role).'),
 ('Due date', 'Within 24 hours of the Tuesday meeting for priority High; end of week otherwise. Not received in 24 hours = escalate the same day.'),
 ('Status', 'Pick from the list. Every line starts Open. Close only with a finding.'),
 ('Finding', 'What actually happened, in one sentence - the evidence, not an opinion ("GRV 16334 captured 90 instead of 100", not "counting error").'),
 ('Rand charged / recovered', 'Amount charged to staff or recovered from the supplier.'),
 ('Corrected in PumpIT', 'Y once the recon, GRV or stock take is corrected in the system.'),
 ('Head office sign-off', 'Initials of the person at head office who accepted the finding.'),
 ('STATUS CODES', None)] + [(s_, '') for s_ in STATUS] + [
 ('SIGN AND VALUE CONVENTIONS', None),
 ('Recon variance', 'POS quantity - (opening + issued - closing). Negative = short, positive = over.'),
 ('Storeroom', 'Stock take - recon. Negative = a loss outside the recon. Positive = NOT a loss (stock the book does not know about).'),
 ('Values', 'Weekly register and shift tabs: recon RandPU (selling). Period items: cost. Stated on every tab.'),
 ('DATA USED', None),
 ('Weekly line list', f'Built from the PumpIT reports: every recon item on the Stock Recon Detail for {WL}, with its stock take variance for the week and the week before.'),
 ('Shift recon', f'Stock Recon Detail - Amended & Original QTY, {PL}; each site cut at its last complete recon day.'),
 ('Stock takes', 'WinBranch stock take detail, Aura stk 01 count register, Pilot daily prep sheet.'),
 ('Deliveries', 'Omni supplier transactions (WinBranch), Aura 09 GRVs, Pilot purchases.'),
 ('Masters', 'Stock recon item issue multiples; ALL SITES STOCK master (last stock take date); Raw Material Consumption (recipe explosion of sales).'),
]
rr_ = 4
for k_, v_ in rows_:
    if v_ is None:
        rr_ += 1; rm.cell(rr_, 1, k_).font = H2
    else:
        rm.cell(rr_, 1, k_).font = BB; c_ = rm.cell(rr_, 2, v_); c_.font = BF; c_.alignment = Alignment(wrap_text=True, vertical='top')
    rr_ += 1
rm.column_dimensions['A'].width = 34; rm.column_dimensions['B'].width = 120

fd = wb.create_sheet('Findings', 1); fd.sheet_properties.tabColor = 'B3261E'
fd.column_dimensions['A'].width = 9; fd.column_dimensions['B'].width = 28
for j in range(3, 13): fd.column_dimensions[CL(j)].width = 14
row = [1]
def P(txt, font=BF, num=None, h=None):
    if num is not None: fd.cell(row[0], 1, num).font = BB
    c_ = fd.cell(row[0], 2, txt); c_.font = font; c_.alignment = Alignment(wrap_text=True, vertical='top')
    fd.merge_cells(start_row=row[0], start_column=2, end_row=row[0], end_column=12)
    fd.row_dimensions[row[0]].height = h or max(14, 12.5 * (1 + len(txt) // 165))
    row[0] += 1
def gap(): row[0] += 1
fd['A1'] = 'Stock recon findings - convenience sites and Baobab Inn'; fd['A1'].font = Font(name=F, bold=True, size=16, color=NAVY); row[0] = 2
P(f'{PL}, with the week of {WL} in detail. Values: weekly and shift figures at selling price (RandPU); period stock take figures at cost.', SF); gap()

P('1. WHAT IS GOING ON', H2)
P(f'Of {nlines:,} recon lines in the week of {WL}, {ninv:,} need an explanation. Only {bn("Loss at recon point")} lines ({R(bv("Loss at recon point"))}) are a loss confirmed in a staff member\'s hands. '
  f'The real leak is outside the recon: {bn("Storeroom loss")} storeroom-loss lines ({R(bv("Storeroom loss"))}), where the whole site is short but no issue sheet and no sale explains it. '
  f'The largest are {top(st_site, 4)}.', num='1.1')
P(f'Over {PL} the whole-site stock takes on the recon items that were counted came to {R(per_site.site.sum())} short at cost, while the recon itself (after balancing) was {R(per_site.bal.sum())} '
  f'{"over" if per_site.bal.sum() > 0 else "short"}. The recon is not catching the loss. It is either leaving the site through the storeroom, or it is being moved out of the recon by the way openings, closings and issues are captured and balanced.', num='1.2')
P(f'Stock moves between shifts without anyone carrying it. On {len(below):,} shifts ({PLs}) the opening was captured below the previous closing - {R(below.Value.sum())} of stock vanished between two shifts. '
  f'On {len(above):,} shifts it was captured above.', num='1.3')
gap()

P('2. WHAT PEOPLE ARE DOING', H2)
P(f'Openings do not equal closings, and the closing is then rewritten. Of the {len(below):,} openings below the previous closing, only {int(rep.n.get("Correct - opening amended", 0))} were corrected properly by amending the opening. '
  f'{int(rep.n.get("WRONG - previous closing changed", 0))} ({R(rep.v.get("WRONG - previous closing changed", 0))}) were "fixed" by changing the previous shift\'s closing to match, {int(rep.n.get("Both changed", 0))} had both changed, and '
  f'{int(rep.n.get("Still open after balancing", 0))} ({R(rep.v.get("Still open after balancing", 0))}) are still open. Changing a closing rewrites what the previous staff member counted: the missing stock is charged to the wrong shift or absorbed into balancing. '
  f'By site: ' + ', '.join(f'{SHORT[i_]} {int(r_.n)} ({R(r_.v)})' for i_, r_ in bl_site.iterrows()) + '.', num='2.1')
P(f'Balancing breaks the rule. {len(s7)} overs were turned into shorts ({R(s7.Value.sum())}) and {len(s8)} shorts were increased ({R(s8.Value.sum())}); on {len(s11)} shifts the latest closing count - which must stand - was changed. '
  f'By site: ' + ', '.join(f'{SHORT[i_]} {int(r_.n)} ({R(r_.v)})' for i_, r_ in br_site.iterrows()) + '.', num='2.2')
P(f'Issues are not written up on the shift. On {len(s4a)} shifts the issue was only added at balancing ({R(s4a.Value.sum())}) - ' + ', '.join(f'{SHORT[i_]} {int(r_.n)}' for i_, r_ in la_site.head(4).iterrows())
  + f'. On {len(s4b)} shifts an issue was reduced at balancing (over-captured or captured twice). The recon then balances, but the control has already failed: stock left the storeroom without paperwork.', num='2.3')
_potb = pot[pot.Verdict.str.contains('breached')]
_bun = irp[irp.Code.isin(['B1', 'SBBB'])]
_bunb = sorted({SHORT[b_] for b_ in _bun[_bun.Verdict.str.contains('breached')].BranchName}); _bung = sorted({SHORT[b_] for b_ in _bun.BranchName} - set(_bunb))
if len(_potb):
    P('Steers potatoes are held back instead of issued on receipt. ' + '; '.join(f'{SHORT[x_.BranchName]}: {x_.Compliance}, received {x_.GRV:,.0f} kg, issued {x_.Issued:,.0f} kg, site count {x_.SiteVar:+,.1f} kg ({R(x_.SiteVarR)} at cost)' for _, x_ in _potb.iterrows())
      + '. Recipes already carry yield, so a shortfall is not peel or trim: it went missing between delivery and the kitchen floor.'
      + (f' Buns are held back at {", ".join(_bunb)}.' if _bunb else '') + (f' Buns are issued correctly at {", ".join(_bung)}.' if _bung else ''), num='2.4')
else:
    P('Steers potatoes were issued as received at every site.' + (f' Buns are held back at {", ".join(_bunb)}.' if _bunb else ''), num='2.4')
stale = rc[(rc.DaysSinceCount > 16)]
stale_site = stale.groupby('BranchName').size()
_old = stale[stale.DaysSinceCount > 30]
_oldtxt = ('. Longest uncounted: ' + '; '.join(f'{SHORT[b_]} {len(x_)} items not counted for over 30 days (oldest {x_.LastCountMaster.min():%d %B %Y}, mostly {x_.AreaDescription.value_counts().index[0]})' for b_, x_ in _old.groupby('BranchName') if len(x_) >= 3)) if len(_old) else ''
_nam = wk[(wk.Posname == 'NAMOS') & (wk.Scenario == 'NC')].groupby('BranchName').size()
P(f'Items are not counted. In the week of {WL} {int(ncs.sum())} of {nlines:,} recon lines had no stock take: ' + ', '.join(f'{SHORT[i_]} {int(ncs.get(i_, 0))} of {int(lines_s[i_])}' for i_ in ncs.sort_values(ascending=False).index)
  + ''.join(f'. {SHORT[b_]}\'s {n_} shop lines run on NAMOS with no stock take linked' for b_, n_ in _nam.items())
  + f'. The stock master shows {len(stale)} WinBranch recon items last counted more than 16 days ago - ' + ', '.join(f'{SHORT[i_]} {int(v_)}' for i_, v_ in stale_site.sort_values(ascending=False).items())
  + _oldtxt + '. An item that is not counted cannot be split between the counter and the storeroom.', num='2.5')
P(f'Stock takes are posted without investigation. {bn("Reversal vs last week")} lines this week reverse last week\'s stock take, and {bn("Storeroom over / invoices")} lines ({R(bv("Storeroom over / invoices"))}) are storeroom overs - stock on hand that the book does not know about '
  f'({top(over_site, 3)}). An over is not a loss; it means an invoice was not captured, captured short or captured late, or a credit was raised on stock that was kept.', num='2.6')
gap()

P('3. WHAT IS NOT A LOSS', H2)
P(f'Paperwork: {bn("Paperwork - recon not confirmed")} lines ({R(bv("Paperwork - recon not confirmed"))}) where the recon variance is not confirmed by the stock take - issues written but not handed over, captured twice, or not written up. '
  f'Largest: ' + ', '.join(f'{SHORT[i_]} {int(r_["size"])} lines ({R(r_["sum"])})' for i_, r_ in paper_site.head(3).iterrows()) + '. Do not charge staff for these; correct the recon.', num='3.1')
_e2 = wk[wk.Scenario == 'E2']
P(f'System faults: this week {len(e1_week)} lines show stock moving with no till sales linked (' + (', '.join(f'{SHORT[b_]} {n_}' for b_, n_ in e1_week.groupby('BranchName').size().items()) or 'none') + ') - a PLU, link or recipe fault, not theft. '
  f'{len(_e2)} lines show stock take variances more than 10 times their throughput (' + (', '.join(f'{SHORT[b_]} {n_}' for b_, n_ in _e2.groupby('BranchName').size().items()) or 'none') + ') - a unit, code or count-capture fault until recounted.', num='3.2')
cvn = ca_[ca_['What it means'] != 'Recipe and recon agree']
if len(ca_):
    _big = cvn.reindex(cvn.Gap.abs().sort_values(ascending=False).index).head(3)
    P(f'Recipes and the recon do not always agree. {len(ca_)} recon items are used in recipes; on {int((CVP.st == "match").sum()):,} of {len(CVP):,} item-days the recipe consumption (raw material report) matched the recon POS. '
      f'{len(cvn)} items disagree over the period. Largest: ' + '; '.join(f'{SHORT[x_.BranchName]} {str(x_.Item).strip()} ({x_.Code}) - recipes consumed {x_.Cons:,.0f}, the recon POS saw {x_.POS:,.0f}' for _, x_ in _big.iterrows())
      + '. Where the recipe consumes more than the recon POS, the recon shows a false short; where the recon POS is higher, the stock book stays too high and the stock take shows a false short. Neither is a loss.', num='3.3')
else:
    P('Recipe consumption was not loaded this week (no Raw Material Consumption report).', num='3.3')
_nc_wrong = int(wk.Evidence.str.contains('"No Count" corrected').sum())
P(f'Data faults: the WinBranch stock take detail has no line for an item counted with no variance, so a counted-and-balanced item looks uncounted: {_nc_wrong} lines with no variance line had a last stock take date inside the week (treated as counted and balanced). '
  f'{int((wk.Scenario == "V").sum())} virtual lines (airtime, lotto, electricity, refill water) sit on the recon.', num='3.4')
gap()

P('4. DATA COMPLETENESS', H2)
P('Each site is analysed only up to its last complete recon day (both shifts captured on at least 95% of the usual recon lines, closing counts in, POS posted). Days after that, and stock takes dated on them, are held back until the recon is complete; they are not findings. '
  + ('Held back this run: ' + '; '.join(f'{SHORT[x_.BranchName]} {x_["min"]:%d %b}' + (f' to {x_["max"]:%d %b}' if x_["max"] != x_["min"] else '') for _, x_ in HELD.iterrows()) + '.' if len(HELD) else 'No days were held back this run.'), num='4.1')
_nw = [s_ for s_ in SITES if s_ not in set(BAL.BranchName)]
P('Every figure is built from the PumpIT reports (Stock Recon Detail, the three stock take registers, purchases, stock master, raw material consumption); no manual sheet is used.' + (f' No recon lines in the week for: {", ".join(_nw)}.' if _nw else ''), num='4.2')
gap()

P('5. WHAT MUST BE DONE', H2)
_oc_top = ', '.join(SHORT[i_] for i_ in bl_site.head(2).index)
_br_top = ', '.join(SHORT[i_] for i_ in br_site.head(2).index)
_la_top = ', '.join(SHORT[i_] for i_ in la_site.head(3).index)
_nc_top = ', '.join(SHORT[i_] for i_ in (ncs / lines_s).dropna().sort_values(ascending=False).head(3).index)
todo = [
 ('Now', f'Every opening equals the previous closing. Where it does not, amend the OPENING; never change a closing to match. Sites explain every line on the Opening vs Closing tab within 48 hours' + (f' - {_oc_top} first.' if _oc_top else '.')),
 ('Now', 'Reverse and explain every balancing breach (Balancing Breaches tab).' + (f' {_br_top} account for their over-to-short and increased-short shifts.' if _br_top else '')),
 ('Now', 'Steers potatoes and buns issued in full on the day of delivery, equal to the GRV.' + (f' Key holders at {", ".join(SHORT[b_] for b_ in _potb.BranchName)} account for the potato shortfall.' if len(_potb) else '')),
 ('This week', 'Work the Item Register: storeroom losses to the key holder, confirmed recon-point losses charged to the shift, paperwork corrected in PumpIT, overs matched to invoices. Close no line without a finding.'),
 ('This week', 'Issues written up on the shift.' + (f' {_la_top} managers answer for issues only captured at balancing.' if _la_top else '')),
 ('Monday', 'Every recon item counted every Monday; QSR items daily.' + (f' {_nc_top} report compliance on Tuesday.' if _nc_top else '')),
 ('This week', 'IT / master data close the system faults on the Control Actions tab (selling-code links, recipe links, E2 recounts) before the next run.'),
 ('ERP', 'Opening locked to the previous closing with a signed handover count; balancing that refuses over-to-short, short-increase, closing-to-match-opening and latest-count changes; staff ID on every recon line; GRV of potatoes and buns creates the issue; no stock take over or reversal posted without a reason code.'),
]
for k_, v_ in todo:
    fd.cell(row[0], 1, k_).font = BB; c_ = fd.cell(row[0], 2, v_); c_.font = BF; c_.alignment = Alignment(wrap_text=True, vertical='top')
    fd.merge_cells(start_row=row[0], start_column=2, end_row=row[0], end_column=12); fd.row_dimensions[row[0]].height = max(14, 12.5 * (1 + len(v_) // 165)); row[0] += 1
gap()

P('6. SITE BY SITE (live from the registers)', H2)
hdr6 = ['Site', 'Lines to investigate (week)', 'Open', '% closed', 'Storeroom loss R (week)', 'Recon-point loss R (week)', 'Not counted (week)', f'Opening below previous closing ({PLs})', 'Vanished R', 'Balancing breaches', 'Issue-sheet failures']
t6 = row[0]
for j, h in enumerate(hdr6, 2):
    c_ = fd.cell(t6, j, h); c_.fill = HDR; c_.font = HF; c_.alignment = Alignment(wrap_text=True, vertical='center')
fd.row_dimensions[t6].height = 44
src = {2: 'A', 3: 'B', 4: 'C', 5: 'E', 6: 'G', 7: 'H', 8: 'I', 9: 'J', 10: 'K', 11: 'M', 12: 'O'}
for i in range(len(SITES) + 1):
    rr_ = t6 + 1 + i
    for j, colL in src.items():
        c_ = fd.cell(rr_, j, f"='Site Scorecard'!{colL}{5 + i}"); c_.font = BB if i == len(SITES) else BF
        c_.number_format = PC if colL == 'E' else (R0 if colL in 'GHK' else '#,##0')
