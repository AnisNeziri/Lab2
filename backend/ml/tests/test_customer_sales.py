import importlib.util, unittest
from pathlib import Path
spec=importlib.util.spec_from_file_location('customer_sales',Path(__file__).parents[1]/'customer_sales.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
class CustomerSalesTest(unittest.TestCase):
 def test_sparse_history_has_no_prediction(self):
  r=module.analyze({'customers':[{'id':1,'dates':['2026-01-01','2026-02-01']}]})['customers'][0]
  self.assertFalse(r['eligible']);self.assertEqual(r['completed_intervals'],1)
 def test_duplicate_days_do_not_invent_frequency(self):
  r=module.analyze({'customers':[{'id':1,'dates':['2026-01-01','2026-01-01','2026-02-01']}]})['customers'][0]
  self.assertEqual(r['completed_intervals'],1);self.assertEqual(r['median_days'],31)
 def test_actual_intervals_and_no_training_claim(self):
  r=module.analyze({'customers':[{'id':1,'dates':['2026-01-01','2026-01-26','2026-02-20','2026-03-17','2026-04-11','2026-05-06']}]})
  self.assertEqual(r['customers'][0]['median_days'],25);self.assertTrue(r['customers'][0]['eligible']);self.assertFalse(r['trained_model'])
if __name__=='__main__':unittest.main()
