import unittest
from datetime import date, timedelta
from supplier_forecast import run, predict

class SupplierForecastTest(unittest.TestCase):
    def rows(self,n=70):
        rows=[]
        for i in range(n):
            start=date(2023,1,1)+timedelta(days=i*30)
            lead=3+i%6
            rows.append(dict(order_id=i+1,ordered_at=str(start),completion_date=str(start+timedelta(days=lead)),label_known_at=str(start+timedelta(days=lead)),complete=True,ml_eligible=True,lead_days=lead,promised_days=lead,line_count=1+i%4))
        return rows
    def test_insufficient_data_and_partial_targets(self):
        rows=self.rows(50)
        for r in rows[:20]:r['complete']=False
        self.assertEqual(run(dict(rows=rows,cutoff='2030-01-01'))['status'],'insufficient_data')
    def test_future_labels_rejected(self):
        with self.assertRaises(ValueError):run(dict(rows=self.rows(),cutoff='2023-02-01'))
    def test_time_ordered_comparison_and_baseline(self):
        rows=self.rows();result=run(dict(rows=rows,cutoff='2030-01-01'))
        self.assertEqual(result['status'],'ready')
        first=result['windows'][0]['ordered_at']
        self.assertTrue(all(r['label_known_at']<first for r in rows if r['order_id'] in result['validation_training_order_ids']))
        self.assertEqual(result['comparison']['baseline']['samples'],result['comparison']['challenger']['samples'])
        self.assertLess(result['comparison']['challenger']['mae'],result['comparison']['baseline']['mae'])
    def test_incumbent_only_compared_after_its_training_cutoff(self):
        rows=self.rows();incumbent=run(dict(rows=rows[:45],cutoff=rows[44]['label_known_at']))['artifact']
        result=run(dict(rows=rows,cutoff='2030-01-01',incumbent=incumbent))
        self.assertTrue(all(r['ordered_at']>incumbent['training_cutoff'] for r in result['windows']))
        self.assertEqual(result['comparison']['incumbent']['samples'],result['comparison']['challenger']['samples'])
    def test_prediction_finite_and_repeatable(self):
        rows=self.rows();a=run(dict(rows=rows,cutoff='2030-01-01'))['artifact']
        self.assertEqual(predict(a,rows[0]),predict(a,rows[0]));self.assertGreaterEqual(predict(a,rows[0]),0)

if __name__=='__main__':unittest.main()
