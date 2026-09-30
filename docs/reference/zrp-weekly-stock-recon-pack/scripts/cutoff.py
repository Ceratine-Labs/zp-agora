"""Last complete recon day per site.
A day is complete when both shifts are captured on >= 95% of the site's recon lines,
closing counts are in (share of non-zero closings >= 50% of the site's normal share)
and POS is posted (share of non-zero POS lines >= 50% of the site's normal share).
Recon rows after the last complete day, and stock takes dated after it, are held back
until the recon is complete - they are not findings."""
import pandas as pd

LINE_PCT, CLOSE_PCT, POS_PCT = 0.95, 0.50, 0.50


def day_status(r):
    r = r.copy()
    r['D'] = pd.to_datetime(r.TransactionDate).dt.normalize()
    r['closeNZ'] = r.QtyClose_Amended.fillna(0) != 0
    r['posNZ'] = r.QtyPOS_Amended.fillna(0) != 0
    s = r.groupby(['BranchName', 'D', 'ShiftNo']).agg(n=('StockItemNo', 'size'), close=('closeNZ', 'mean'), pos=('posNZ', 'mean')).reset_index()
    norm = s.groupby('BranchName').agg(nmax=('n', 'median'), cmed=('close', 'median'), pmed=('pos', 'median'))
    s = s.join(norm, on='BranchName')
    s['ok_shift'] = (s.n >= LINE_PCT * s.nmax) & (s.close >= CLOSE_PCT * s.cmed)
    d = s.groupby(['BranchName', 'D']).agg(shifts=('ShiftNo', 'nunique'), ok=('ok_shift', 'all'), pos=('pos', 'mean'), pmed=('pmed', 'first'),
                                            lines=('n', 'min'), nmax=('nmax', 'first')).reset_index()
    d['complete'] = d.ok & (d.shifts >= 2) & (d.pos >= POS_PCT * d.pmed)
    return d


def last_complete(r):
    d = day_status(r).sort_values(['BranchName', 'D'])
    out = {}
    for b, x in d.groupby('BranchName'):
        bad = x[~x.complete]
        out[b] = (bad.D.min() - pd.Timedelta(days=1)) if len(bad) else x.D.max()
    return out, d


