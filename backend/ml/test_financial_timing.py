import unittest
from financial_timing import analyze


class FinancialTimingTest(unittest.TestCase):
    def sample(self, i, delay=10):
        return {'obligation_id': i, 'due_date': '2026-09-01',
                'paid_date': f'2026-09-{1 + delay:02}', 'known_at': '2026-09-20T12:00:00+00:00'}

    def run_model(self, samples):
        return analyze({'cutoff': '2026-10-04T12:00:00+00:00',
                        'customers': [{'id': 1, 'samples': samples}]})['customers']['1']

    def test_sparse_falls_back_without_fake_range(self):
        r = self.run_model([self.sample(1)])
        self.assertFalse(r['eligible'])
        self.assertEqual(r['method'], 'due_date_baseline')
        self.assertIsNone(r['range'])

    def test_median_is_interpretable(self):
        r = self.run_model([self.sample(i, d) for i, d in enumerate([8, 9, 10, 11, 25])])
        self.assertTrue(r['eligible'])
        self.assertEqual(r['median_delay_days'], 10)

    def test_future_labels_are_excluded(self):
        future = self.sample(99)
        future['known_at'] = '2026-11-01T00:00:00+00:00'
        r = self.run_model([self.sample(1), future])
        self.assertEqual(r['samples'], 1)

    def test_duplicates_are_not_independent_observations(self):
        self.assertEqual(self.run_model([self.sample(1)] * 20)['samples'], 1)


if __name__ == '__main__':
    unittest.main()
