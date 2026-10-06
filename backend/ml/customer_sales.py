"""Dependency-free diagnostic for V8 personal cadence. Never contacts customers.
Only completed dates supplied by the canonical adapter; no synthetic training.
"""
import json, sys, statistics
from datetime import date

def analyze(payload):
    rows=[]
    for customer in payload.get('customers', []):
        dates=sorted({date.fromisoformat(d) for d in customer.get('dates', [])})
        gaps=[(b-a).days for a,b in zip(dates,dates[1:])]
        median=statistics.median(gaps) if gaps else None
        rows.append({'id':customer['id'],'completed_intervals':len(gaps),'median_days':median,
                     'mad_days':statistics.median(abs(g-median) for g in gaps) if gaps else None,
                     'eligible':len(dates)>=payload.get('minimum_purchases',6),
                     'qualification':'Timing diagnostics only; PHP baseline owns operational signal rules.'})
    return {'algorithm':'personal_median_mad','customers':rows,'trained_model':False}

if __name__=='__main__':
    print(json.dumps(analyze(json.load(sys.stdin)),allow_nan=False))
