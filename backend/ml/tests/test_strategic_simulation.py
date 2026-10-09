"""V12 uses the existing local V11 solver; no parallel forecasting implementation."""
import copy
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from supply_optimizer import solve

def option(group, key, cost, shortage, resource=None):
    return dict(group=group,id=f'{group}:{key}',cost_minor=cost,resources=resource or {},
                penalties=dict(stockout=shortage,availability=shortage,delay=0,quality=0,cost=cost/9000000,commitment=cost/9000000,excess=0))

class StrategicSimulationSolverTest(unittest.TestCase):
    def fixture(self):
        return dict(groups=[dict(key='fabric:main',candidates=[option('fabric:main','monitor',0,1),option('fabric:main','alternative-supplier',6000000,0),option('fabric:main','transfer',0,.25,{'fabric:branch':50000})]),
                            dict(key='foam:main',candidates=[option('foam:main','monitor',0,1),option('foam:main','bridge',4000000,0)])],
                    resources={'fabric:branch':50000},weights=dict(stockout=40,availability=20,cost=10,commitment=4),commitment_limit_minor=9000000,time_limit=12)
    def test_frozen_payload_unchanged_and_repeatable(self):
        payload=self.fixture();before=copy.deepcopy(payload)
        first=solve(payload);second=solve(payload)
        self.assertEqual(before,payload)
        self.assertEqual([(r['key'],r['candidate_ids']) for r in first['plans']],[(r['key'],r['candidate_ids']) for r in second['plans']])
        self.assertTrue(all(r['commitment_minor']<=9000000 for r in first['plans']))
    def test_zero_commitment_stress_remains_zero_and_does_not_relax_limit(self):
        payload=self.fixture();payload['commitment_limit_minor']=0
        result=solve(payload)
        self.assertTrue(all(r['commitment_minor']==0 for r in result['plans']))
        self.assertTrue(any('foam:main:monitor' in r['candidate_ids'] for r in result['plans']))
    def test_supplier_loss_reuses_actual_candidates_only(self):
        payload=self.fixture();payload['groups'][0]['candidates']=payload['groups'][0]['candidates'][:1]
        result=solve(payload)
        self.assertTrue(all('fabric:main:monitor' in r['candidate_ids'] for r in result['plans']))

if __name__=='__main__':unittest.main()
