import json,re,zipfile,xml.etree.ElementTree as ET
from pathlib import Path
root=Path('C:/Users/anisi/.codex/recovery/dc16-20261102/storage/evidence');art=json.loads((root/'print-artifacts.json').read_text());checks=[]
for case,locales in art.items():
 for lang,meta in locales.items():
  p=root/'print'/f'{case}-{lang}.txt'
  if not p.exists():continue
  try:text=p.read_text(encoding='utf-8')
  except UnicodeDecodeError:text=p.read_text(encoding='cp1252')
  pages=[x for x in text.split('\f') if x.strip()];token=f'{float(meta["total"]):,.2f}'
  header='Sasia' if lang=='sq' else 'Qty'
  checks.append({'case':case,'locale':lang,'pages':len(pages),'expected_total':meta['total'],'total_present':token in text,'repeated_header_each_page':all(header in page for page in pages),'invoice_number_each_page':all(meta['number'] in page for page in pages),'basis':'Extracted server-PDF text plus saved first/last long-SQ visual review. Does not prove browser/native printing.'})
workbooks=[]
for id,total in [(2,1631.63),(8,9279.08),(66,-17.13)]:
 with zipfile.ZipFile(root/'print'/f'export-{id}.xlsx') as z:
  xml=ET.fromstring(z.read('xl/worksheets/sheet1.xml'));values=[]
  for v in xml.iter('{http://schemas.openxmlformats.org/spreadsheetml/2006/main}v'):
   try:values.append(float(v.text))
   except (TypeError,ValueError):pass
  workbooks.append({'invoice_id':id,'expected_total':total,'total_present':any(abs(v-total)<0.00001 for v in values),'basis':'Read-only XLSX numeric-cell check; credit-note export correctly uses signed negative amount. No formula engine/layout certification.'})
ui={'goals':[{'goal':'Owner dashboard to overdue Customer360','status':'PASS','evidence':'primary-command-center.jpg','observed':'AR61947.93/cash210623.39 agree with independent oracles; follows customer7 source link.'},{'goal':'Contact and opportunity handover to Sales','status':'PASS','evidence':['customer-contact-saved.jpg','opportunity-saved.jpg','sales-assigned-opportunity.jpg'],'observed':'Contact saved; qualified EUR1500 opportunity assignedSales and visible in salesperson My opportunities.'},{'goal':'Fractional customer quotation to order','status':'PASS','evidence':'quotation-converted.jpg','observed':'12.5m at EUR4.92; separate sent/accept/conversion confirmations, draft order643.'},{'goal':'Order confirmation with warehouse-specific shortage','status':'PARTIAL','evidence':'order-backorder-message.jpg','observed':'Correct zero reservation/backorder12.5; misleading success toast/Ready to fulfill wording registered DC16-UI-002.'},{'goal':'Warehouse barcode lookup at mobile width390','status':'PASS','evidence':'warehouse-mobile-scan.jpg','observed':'DC16BC4 resolves421.825m, per-location quantities and no payroll/accounting clutter.'},{'goal':'Role workspaces','status':'PARTIAL','evidence':'ui-role-workspaces.json','observed':'Seven business roles signed in; detailed Owner/Sales/Warehouse goals, remaining role navigation only.'},{'goal':'EN/SQ light/dark and tablet','status':'PARTIAL','evidence':'control-dashboard.jpg','observed':'English/light and Albanian/dark settings and rendered views reviewed. Full tablet/keyboard sweep not completed.'},{'goal':'Actual browser Print invoice action','status':'PARTIAL','evidence':'export-checks.json','observed':'75-line invoice filter, detail, Print invoice, Albanian HTML preview loaded with9279.08total; Print enabled. Clicking Print timed out in in-app browser Input.dispatchMouseEvent; no controllable print/save dialog appeared in available surfaces. Owned test tab closed cleanly.','manual_remaining':'Open the issued invoice in a full browser, Print invoice→Print→Save as PDF; inspect A4 pages/headers/totals. Repeat in isolated Electron with a configured printer for native output.'}], 'first_time_human_acceptance':'NOT TESTED; automated review is not human user acceptance.'}
(root/'output-review.json').write_text(json.dumps({'pdf_text_checks':checks,'xlsx_numeric_checks':workbooks,'native_print':'NOT TESTED'},indent=2),encoding='utf-8')
(root/'ui-findings.json').write_text(json.dumps(ui,indent=2),encoding='utf-8')
print(json.dumps({'pdf_checks':len(checks),'pdf_totals_present':sum(x['total_present'] for x in checks),'headers_each_page':sum(x['repeated_header_each_page'] for x in checks),'xlsx_checks':workbooks}))
