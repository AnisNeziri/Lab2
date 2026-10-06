import unittest
import json
from datetime import date,timedelta
from forecast import run, features, forecast, evaluate, validate_artifact

def series(n=240, trend=False):
    start=date(2025,1,1)
    return [{'date':(start+timedelta(days=i)).isoformat(),'demand':(8+i*.12 if trend else 10)+((i%7)*.5 if trend else 0),'censored':False} for i in range(n)]

class ForecastTest(unittest.TestCase):
    def test_constant_seasonal_baseline_is_retained_and_zero_wape_is_undefined(self):
        result=run({'series':series()})
        row=next(x for x in result['results'] if x['horizon']==7)
        self.assertEqual(row['algorithm'],'seasonal_mean')
        self.assertEqual(row['evaluation']['mae'],0)
        self.assertEqual(row['total'],70)
        zeros=[dict(r,demand=0) for r in series()]
        self.assertIsNone(run({'series':zeros})['results'][0]['evaluation']['wape'])

    def test_trend_model_is_measured_against_baseline(self):
        result=run({'series':series(trend=True)})
        row=result['results'][0]
        self.assertEqual(row['algorithm'],'ridge')
        self.assertLess(row['evaluation']['mae'],row['comparison']['seasonal_mean']['mae'])
        self.assertIsNone(row['range'])
        print('SYNTHETIC TREND EVALUATION',json.dumps({'horizon':row['horizon'],'selected':row['algorithm'],'baseline':row['comparison']['seasonal_mean'],'selected_metrics':row['evaluation']}))

    def test_missing_stockout_sparse_and_fractional(self):
        self.assertEqual(run({'series':series(10)})['status'],'insufficient_data')
        censored=[dict(r,demand=None,censored=True) for r in series(40)]
        self.assertEqual(run({'series':censored})['status'],'insufficient_data')
        self.assertEqual(run({'series':censored})['quality']['unknown_days'],0)
        fractional=[dict(r,demand=.125) for r in series()]
        self.assertEqual(run({'series':fractional})['results'][0]['total'],.875)
        with self.assertRaises(ValueError):run({'series':[dict(r,censored=True) for r in series(40)]})

    def test_no_future_features_and_recursive_time_validation(self):
        rows=series()
        before=features(rows[:100],rows[100]['date'])
        rows[100]['demand']=99999
        self.assertEqual(before,features(rows[:100],rows[100]['date']))
        artifact={'version':'demand-v1','algorithm':'seasonal_mean','parameters':{}}
        self.assertEqual(forecast(rows[:100],7,artifact)[0]['quantity'],10)
        score=evaluate(rows,7,artifact,100)
        self.assertEqual(score['windows'][0]['from'],rows[100]['date'])

    def test_incumbent_kept_without_new_validation_and_artifacts_are_data_only(self):
        rows=series()
        original=run({'series':rows})['results'][0]['artifact']
        result=run({'series':rows,'incumbent':{'7':original}})['results'][0]
        self.assertTrue(result['retained_incumbent'])
        with self.assertRaises(ValueError):validate_artifact({'version':'demand-v1','algorithm':'pickle'})
        with self.assertRaises(ValueError):validate_artifact({'version':'demand-v1','algorithm':'ridge','parameters':{'weights':[float('nan')]*7}})

    def test_candidate_training_never_changes_supplied_production_and_uses_common_holdout(self):
        rows=series(trend=True)
        original=run({'series':rows[:100]})['results'][0]['artifact']
        frozen=json.dumps(original,sort_keys=True)
        result=run({'series':rows,'incumbent':{'7':original},'mode':'candidate'})['results'][0]
        self.assertFalse(result['retained_incumbent'])
        self.assertEqual(frozen,json.dumps(original,sort_keys=True))
        self.assertEqual(result['comparison']['incumbent']['windows'],result['comparison']['seasonal_mean']['windows'])
        self.assertTrue(all(w['from']>original['training_cutoff'] for w in result['evaluation']['windows']))

    def test_production_inference_freezes_matching_baseline_and_rejects_future_model(self):
        rows=series()
        model=run({'series':rows[:100]})['results'][0]['artifact']
        result=run({'series':rows,'mode':'predict','models':{'7':model}})['results'][0]
        self.assertEqual(result['daily'],result['baseline_daily'])
        self.assertEqual(len(result['daily']),7)
        with self.assertRaises(ValueError):run({'series':rows,'mode':'predict','models':{'7':dict(model,training_cutoff='2099-01-01')}})

if __name__=='__main__':unittest.main()
