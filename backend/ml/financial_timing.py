"""Local, interpretable payment-timing challenger. No network or money writes."""
import json
import sys
from datetime import datetime
from statistics import median


def analyze(payload):
    cutoff = datetime.fromisoformat(payload['cutoff'].replace('Z', '+00:00'))
    minimum = max(5, int(payload.get('minimum_samples', 5)))
    customers = {}
    for customer in payload.get('customers', []):
        samples = []
        seen = set()
        for sample in customer.get('samples', []):
            known = datetime.fromisoformat(sample['known_at'].replace('Z', '+00:00'))
            if known > cutoff or sample['obligation_id'] in seen:
                continue
            paid = datetime.fromisoformat(sample['paid_date']).date()
            due = datetime.fromisoformat(sample['due_date']).date()
            if paid > cutoff.date():
                continue
            samples.append((paid - due).days)
            seen.add(sample['obligation_id'])
        customers[str(customer['id'])] = {
            'samples': len(samples), 'eligible': len(samples) >= minimum,
            'median_delay_days': median(samples) if samples else None,
            'method': 'historical_median' if len(samples) >= minimum else 'due_date_baseline',
            'range': None,  # Not a calibrated predictive interval.
        }
    return {'version': 'financial-timing-v7.1', 'customers': customers,
            'qualification': 'Historical statistics, not a formal creditworthiness score.'}


if __name__ == '__main__':
    json.dump(analyze(json.load(sys.stdin)), sys.stdout)
