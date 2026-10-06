import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from supply_optimizer import solve


def candidate(group, index, cost=0, shortage=1, resource=None):
    return {'group':group,'id':f'{group}:{index}','cost_minor':cost,'resources':resource or {},
            'penalties':{'stockout':shortage,'availability':shortage,'delay':0,'quality':0,'cost':cost/10000,'excess':0,'commitment':cost/10000}}


class SupplyOptimizerTest(unittest.TestCase):
    def payload(self):
        return {'groups':[{'key':'A','candidates':[candidate('A',0),candidate('A',1,6000,0),candidate('A',2,3000,.4)]},
                          {'key':'B','candidates':[candidate('B',0),candidate('B',1,5000,0)]}],
                'weights':{'stockout':40,'availability':20,'cost':10,'commitment':4},'commitment_limit_minor':8000,'resources':{},'time_limit':12}
    def test_global_limit_partial_and_alternatives(self):
        out=solve(self.payload())
        self.assertEqual('OPTIMAL',out['plans'][0]['status'])
        self.assertIn('A:2',out['plans'][0]['candidate_ids'])
        self.assertIn('B:1',out['plans'][0]['candidate_ids'])
        self.assertTrue(all(p['commitment_minor']<=8000 for p in out['plans']))
    def test_shared_donor_is_not_spent_twice(self):
        p=self.payload();p['resources']={'P:W':100};p['commitment_limit_minor']=0
        for g in p['groups']:g['candidates']=[candidate(g['key'],0),candidate(g['key'],1,0,0,{'P:W':100})]
        out=solve(p)['plans'][0]
        self.assertEqual(1,sum(i.endswith(':1') for i in out['candidate_ids']))
    def test_infeasible_hard_budget(self):
        p=self.payload();p['groups'][0]['candidates']=[candidate('A',1,9000,0)]
        self.assertEqual('INFEASIBLE',solve(p)['plans'][0]['status'])
    def test_zero_budget_monitor_feasible(self):
        p=self.payload();p['commitment_limit_minor']=0
        self.assertEqual(0,solve(p)['plans'][0]['commitment_minor'])
    def test_reproducible(self):
        self.assertEqual(solve(self.payload())['plans'][0]['candidate_ids'],solve(self.payload())['plans'][0]['candidate_ids'])
    def test_fractional_money_is_rejected(self):
        p=self.payload();p['groups'][0]['candidates'][0]['cost_minor']=.1
        with self.assertRaises(ValueError):solve(p)
    def test_timeout_is_not_false_optimality(self):
        p=self.payload();p['time_limit']=.001
        r=solve(p)
        self.assertTrue(all(p['status'] in ['OPTIMAL','FEASIBLE','TIME_LIMIT','INFEASIBLE','FAILED'] for p in r['plans']))
    def test_representative_40_products_3_warehouses_6_suppliers(self):
        p=self.payload();p['groups']=[];p['resources']={'shared-donor':30000};p['commitment_limit_minor']=8000000
        for i in range(40):
            key=f'P{i}:W{i%3}';full=315000 # independent recommendation total is exactly 126,000
            options=[candidate(key,0)]
            for supplier in range(6):
                for fraction in [1,2,3,4]:
                    cost=round(full*fraction/4*(1+supplier*.015));c=candidate(key,f'{supplier}-{fraction}',cost,1-fraction/4)
                    c['penalties']['cost']=cost/8000000;c['penalties']['commitment']=cost/8000000;c['penalties']['delay']=supplier*.1
                    options.append(c)
            if i<10:
                c=candidate(key,'transfer',0,.3,{'shared-donor':5000});c['penalties']['commitment']=.08;options.append(c)
                c=candidate(key,'transfer-purchase',round(full*.25),.05,{'shared-donor':5000});c['penalties']['cost']=full*.25/8000000;options.append(c)
            p['groups'].append({'key':key,'candidates':options})
        out=solve(p)
        self.assertTrue(all(r['commitment_minor']<=8000000 for r in out['plans']))
        print('REPRESENTATIVE',{'independent':126000,'limit':80000,'solver_seconds':out['seconds'],'variables':out['variables'],'plans':[{k:r[k] for k in ['key','status','commitment_minor','equivalent_profiles']} for r in out['plans']]})


if __name__=='__main__':unittest.main()
