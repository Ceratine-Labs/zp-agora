import json,csv,hashlib,collections,pathlib,datetime,shutil
import argparse
root=pathlib.Path(__file__).resolve().parents[3]
parser=argparse.ArgumentParser(description='Compare captured Agora case outputs with the saved customer master book.')
parser.add_argument('--input-dir',type=pathlib.Path,default=root/'storage/app/weekly-workbook-evidence/2026-10-26')
parser.add_argument('--output-dir',type=pathlib.Path,default=pathlib.Path(__file__).resolve().parent)
args=parser.parse_args();p=args.input_dir;out=args.output_dir;out.mkdir(parents=True,exist_ok=True)
w=json.loads((p/'workbook.json').read_text());book=pathlib.Path(w['path']);rows=[];counts=collections.Counter()
actual={b:json.loads((p/f'actual-cases-{b}.json').read_text()) for b in [23,13]}
sitebybranch={23:'Engen Bethlehem One Plus',13:'Baobab Convenience Centre'}
def bookrows(sheet):
 data=w['sheets'][sheet]['values']; result=[]
 for i,r in enumerate(data[4:],5):
  if all(v is None for v in r):break
  result.append((i,dict(zip(data[3],r))))
 return result
def add(sheet,row,site,area,code,field,expected,actual,why='',kind='case'):
 tolerance=0.000001 if field=='Unit cost' else 0.011
 same=expected==actual
 if expected is not None and actual is not None:
  try:same=abs(float(expected)-float(actual))<=tolerance
  except (TypeError,ValueError):pass
 if not same and not why:why='Current source result differs from the saved September workbook. Original exports are unavailable; source changes versus extraction differences cannot be conclusively separated.'
 result='Match' if same else 'Difference'
 counts[sheet+' / '+result]+=1
 rows.append(dict(sheet=sheet,workbook_row=row,site=site,area=area,code=code,field=field,expected='' if expected is None else expected,actual='' if actual is None else actual,result=result,explanation=f'Matches within {tolerance} numeric tolerance (text exact).' if same else why,comparison_kind=kind))
for branch,site in sitebybranch.items():
 a=actual[branch]
 for sheet,reg,mapping in [
 ('Item Register','Item Register',{'Scenario':'Scenario','Recon var qty (7 days)':'ReconQty','Stock take var qty':'CountQty','Value at risk (R, selling)':'ValueAtRiskSelling'}),
 ('Period Items 1-21 Sep','Period Items',{'Scenario':'Scenario','Site counts':'CountRows','Recon var qty - balanced':'ReconQty','Site count var qty':'CountQty','Unit cost':'UnitCost','Site count var R':'CountValueCost','Recon var R - balanced':'ReconValueCost','Storeroom R':'StoreroomValueCost'}),
 ]:
  for i,r in bookrows(sheet):
   if r.get('Site')!=site:continue
   candidates=[x for x in a if x['RegisterName']==reg and x['POSCode']==str(r['Code']).strip() and (reg!='Item Register' or x['AreaDescription'].strip()==r['Area'].strip())]
   if branch==13 and str(r['Code']) not in ['V7','B1','SBBB']:continue
   x=candidates[0] if len(candidates)==1 else None
   for e,z in mapping.items():
    why=''
    if x is None:why='No unique current case in the scoped snapshot. This is an unresolved comparison, not a pass.'
    elif reg=='Item Register' and x['CountQty'] is None and r['Stock take var qty']==0:
     why='Saved workbook proves a September nil count from the then-current stock-master date. The current snapshot has no dated variance/count proof for that week; a later master date cannot recreate that historical proof.'
    elif e=='Scenario' and x and reg=='Item Register' and str(r['Scenario'])!=x['Scenario']:
     why=f"Classification inputs changed: saved recon/count {r['Recon var qty (7 days)']}/{r['Stock take var qty']}; current {x['ReconQty']}/{x['CountQty']}. See quantity comparisons; not evidence of whole-workbook equivalence."
    elif e in ['Unit cost','Site count var R','Recon var R - balanced','Storeroom R']:
     why='Count-window quantity and last-window cost comparisons are separate. Current source unit costs may differ from the saved export; Agora retains four decimal places for cost amounts. Refer to adjacent quantity and unit-cost rows.'
    add(sheet,i,site,r['Area'],r['Code'],e,r[e],x[z] if x else None,why)
 for i,r in bookrows('Recipe vs Recon'):
  if r.get('Site')!=site:continue
  x=next((x for x in a if x['RegisterName']=='Recipe vs Recon' and x['POSCode']==str(r['Code'])),None)
  if branch==13:continue
  d=json.loads(x['EvidenceJson']) if x else {}
  for key,z in [('Recipe consumption (WinBranch raw material / Pilot theoretical)','Recipe'),('Recon POS quantity','POS'),('Gap (recon - recipe)','Gap'),('Days recipe used, recon POS nil','NoPOS'),('Days differ >10%','DifferDays')]:
   add('Recipe vs Recon',i,site,r['Area'],r['Code'],key,r[key],d.get(z),'Current signed source events are compared day by day. Missing recipe days remain unavailable, whereas the historical engine filled missing days with zero. Quantities or daily flags that differ remain a comparison difference.')
 for sheet in ['Opening vs Closing','Balancing Breaches','Issue Sheet Failures']:
  for i,r in bookrows(sheet):
   if r.get('Site')!=site or (branch==13 and str(r['Code']) not in ['V7','B1','SBBB']):continue
   scenario=None if sheet=='Opening vs Closing' else r['Test'].split(' ')[0]
   candidates=[x for x in a if x['RegisterName']==sheet and x['POSCode']==str(r['Code']) and x['AreaDescription']==r['Area'] and x['EvidenceDate']==str(r['Date'])[:10] and (scenario is None or x['Scenario']==scenario) and str(json.loads(x['EvidenceJson']).get('ShiftNo'))==str(r['Shift'])]
   x=candidates[0] if len(candidates)==1 else None
   add(sheet,i,site,r['Area'],r['Code'],'case exists',True,bool(x),'Saved control is absent in the current extract-derived snapshot. No historical equivalence asserted.')
   if x:
    d=json.loads(x['EvidenceJson']);mapping={'Difference':'ChainBreak'} if sheet=='Opening vs Closing' else ({'Variance - staff count':'StaffVariance','Variance - after balancing':'BalancedVariance'} if sheet=='Balancing Breaches' else {'Issued - captured on shift':'OriginalIssued','Issued - after balancing':'QtyIssued'})
    for k,z in mapping.items():add(sheet,i,site,r['Area'],r['Code'],k,r[k],d.get(z))
 for i,r in bookrows('Potatoes & Buns'):
  if branch!=13 or r.get('Site')!=site or str(r.get('Code')) not in ['B1','SBBB','V7']:continue
  x=next(x for x in a if x['RegisterName']=='Potatoes & Buns' and x['POSCode']==r['Code']);d=json.loads(x['EvidenceJson']);deliveries=[v for v in d['Deliveries'] if v['Received']!=0]
  for k,z in [('Received (GRV)','Received'),('Issued to floor','Issued')]:add('Potatoes & Buns',i,site,'Steers BOH',r['Code'],k,r[k],d[z])
  add('Potatoes & Buns',i,site,'Steers BOH',r['Code'],'Received not issued',r['Received not issued'],x['ReconQty'])
  add('Potatoes & Buns',i,site,'Steers BOH',r['Code'],'Deliveries issued as received',r['Deliveries issued as received'],f"{sum(v['Matched'] for v in deliveries)} of {len(deliveries)} deliveries")
 if branch==13:
  data=w['sheets']['Potatoes & Buns']['values']
  for i,r in enumerate(data[16:],17):
   if r[0]!=site:continue
   x=next((x for x in a if x['RegisterName']=='Potatoes & Buns' and x['POSCode']==r[2]),None)
   d=next((d for d in json.loads(x['EvidenceJson'])['Deliveries'] if d['EvidenceDate']==str(r[4])[:10]),{}) if x else {}
   add('Potatoes & Buns delivery detail',i,site,r[1],r[2],str(r[4])[:10]+' received',r[5],d.get('Received'))
   add('Potatoes & Buns delivery detail',i,site,r[1],r[2],str(r[4])[:10]+' issue compared',r[7],d.get('MatchedIssued'))
   add('Potatoes & Buns delivery detail',i,site,r[1],r[2],str(r[4])[:10]+' without delivery',r[8].startswith('ISSUED WITHOUT'),bool(d.get('IssueWithoutDelivery')))
 if branch==23 and (p/f'actual-overview-{branch}.json').exists():
  sets=json.loads((p/f'actual-overview-{branch}.json').read_text());score=sets[0][0];regs={r['RegisterName']:r for r in sets[2]}
  for i,r in bookrows('Site Scorecard'):
   if r.get('Site')!=site:continue
   for e,z in [('Week 14-20 Sep: lines to investigate','LinesToInvestigate'),('Week: value at risk (R)','ValueAtRiskSelling'),('Week: storeroom loss (R)','StoreroomExposureSelling'),('Week: loss at recon point (R)','ReconPointExposureSelling')]:
    add('Site Scorecard',i,site,'All','',e,r[e],score[z],'Current classifications and evidence differ from saved September data. Monetary columns are selling-price exposure; shared count mappings and virtual lines are excluded, not recoverable charges.',kind='scorecard')
 for i,r in bookrows('Control Actions'):
  if r.get('Site') not in [site,'All sites','All WinBranch sites'] or branch!=23:continue
  cat={'Recon control':'Opening vs Closing','Balancing':'Balancing Breaches','Issue sheets':'Issue Sheet Failures'}.get(r['Area'])
  policy={35:'namos-count',36:'nil-count-export',37:'virtual-lines',38:'pack-validation',39:'opening-lock',40:'staff-identity',41:'count-reasons'}.get(r['No.'])
  x=next((v for v in a if v['RegisterName']=='Control Actions' and v['POSCode']==(policy or cat)),None)
  add('Control Actions',i,site,r['Area'],r['No.'],'assigned action',r['Action required'],x['NextAction'] if x else None,
      'Agora creates management actions from current supporting register cases with the responsible role and an owner/due-date/approval workflow. This does not reproduce the exact saved narrative or its assigned deadlines.' if x else 'The saved system/ERP action is not represented as a dedicated actionable case. This remains an implementation gap.',kind='management action')
with (out/'comparison.csv').open('w',newline='') as f:
 writer=csv.DictWriter(f,fieldnames=list(rows[0]));writer.writeheader();writer.writerows(rows)
manifest={'workbook':str(book),'workbook_sha256':w['sha256'],'created_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),'baseline_week':['2026-09-14','2026-09-20'],'control_period':['2026-09-01','2026-09-21'],'reporting_as_of':'2026-09-23','scope':sitebybranch,'limitations':['Current customer reads, not immutable September exports.','Branch 13 contains only B1, SBBB and V7; it is not a whole-site scorecard.','Original exported nil-count proofs and all-site cross-pattern baseline not replayed.','All-site policy actions are instantiated per site; the historical aggregate action list is not an identical one-for-one register.'],'results':dict(counts),'inputs':[]}
archive=root/'storage/app/weekly-workbook-evidence/2026-10-26';archive.mkdir(parents=True,exist_ok=True)
for name in ['workbook.json','current-source-23.json','current-source-13.json','actual-cases-23.json','actual-cases-13.json','actual-overview-23.json','actual-overview-13.json','actual-snapshot-23.json','actual-snapshot-13.json']:
 q=p/name
 if q.exists():

  if q.resolve()!=(archive/name).resolve():shutil.copyfile(q,archive/name)
  manifest['inputs'].append({'file':str(archive/name),'sha256':hashlib.sha256(q.read_bytes()).hexdigest(),'bytes':q.stat().st_size})
(out/'manifest.json').write_text(json.dumps(manifest,indent=2)+'\n');print(json.dumps({'comparisons':len(rows),'results':dict(counts)},indent=2))
