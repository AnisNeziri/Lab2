"""V11 bounded multiple-choice MILP. No database, network or business mutations.

Optimal means optimal over the frozen, enumerated valid candidate bundles only.
Money and resource feasibility are verified again with integers after HiGHS.
"""
import json
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent / "optimizer_vendor"))
import numpy as np
import scipy
from scipy.optimize import Bounds, LinearConstraint, milp
from scipy.sparse import lil_matrix

VERSION = "supply-milp-v11.1"


def solve(payload):
    started = time.monotonic()
    groups = payload["groups"]
    if not groups or len(groups) > 120:
        raise ValueError("Select 1–120 product/warehouse scopes.")
    candidates = [c for g in groups for c in g["candidates"]]
    if not candidates or len(candidates) > 12000:
        raise ValueError("Candidate set exceeds the bounded solver limit.")
    resources = payload.get("resources") or {}
    limit = payload.get("commitment_limit_minor")
    keys = sorted(resources)
    matrix = lil_matrix((len(groups) + 1 + len(keys), len(candidates)), dtype=float)
    lower = np.full(matrix.shape[0], -np.inf)
    upper = np.full(matrix.shape[0], np.inf)
    index = 0
    for row, group in enumerate(groups):
        lower[row] = upper[row] = 1
        for c in group["candidates"]:
            matrix[row, index] = 1
            matrix[len(groups), index] = c["cost_minor"]
            for k, quantity in (c.get("resources") or {}).items():
                if k not in resources or quantity < 0:
                    raise ValueError("Invalid shared donor resource.")
                matrix[len(groups) + 1 + keys.index(k), index] = quantity
            index += 1
    if limit is not None:
        if not isinstance(limit, int) or limit < 0:
            raise ValueError("Commitment limit must be nonnegative integer currency cents.")
        upper[len(groups)] = limit
    for row, key in enumerate(keys):
        upper[len(groups) + 1 + row] = resources[key]
    for c in candidates:
        if not isinstance(c["cost_minor"], int) or c["cost_minor"] < 0:
            raise ValueError("Invalid candidate commitment.")
    weights = payload["weights"]
    profiles = [("balanced", weights),
                ("service_first", dict(weights, stockout=60, availability=30, cost=2, commitment=1)),
                ("lower_commitment", dict(weights, stockout=max(20, weights["stockout"] / 2), cost=25, commitment=30))]
    plans, seen = [], set()
    for name, policy in profiles:
        objective = np.array([sum(min(60, max(0, policy.get(k, 0))) * v / (1 if k in ("cost", "commitment") else len(groups))
                                  for k, v in c["penalties"].items())
                              + (i + 1) * 1e-9 for i, c in enumerate(candidates)])
        remaining = max(.001, min(12, payload.get("time_limit", 12)) - (time.monotonic() - started))
        result = milp(objective, integrality=np.ones(len(candidates)),
                      bounds=Bounds(0, 1), constraints=LinearConstraint(matrix.tocsr(), lower, upper),
                      options={"time_limit": remaining, "mip_rel_gap": 0, "presolve": True})
        selected = []
        if result.x is not None:
            # Reject non-integral incumbents rather than rounding away a violation.
            if any(abs(v - round(v)) > 1e-5 for v in result.x):
                raise ValueError("Solver returned a non-integral incumbent.")
            selected = [c for c, v in zip(candidates, result.x) if v > .5]
            if len(selected) != len(groups) or any(sum(c["group"] == g["key"] for c in selected) != 1 for g in groups):
                raise ValueError("Candidate exclusivity validation failed.")
            if limit is not None and sum(c["cost_minor"] for c in selected) > limit:
                raise ValueError("Commitment validation failed.")
            if any(sum((c.get("resources") or {}).get(k, 0) for c in selected) > resources[k] for k in keys):
                raise ValueError("Donor conservation validation failed.")
        status = "OPTIMAL" if result.status == 0 else ("FEASIBLE" if selected else
                 "TIME_LIMIT" if result.status == 1 else "INFEASIBLE" if result.status == 2 else "FAILED")
        signature = tuple(sorted(c["id"] for c in selected))
        if selected and signature in seen:
            next(p for p in plans if tuple(sorted(p["candidate_ids"])) == signature)["equivalent_profiles"].append(name)
            continue
        seen.add(signature)
        plans.append({"key": name, "status": status, "candidate_ids": [c["id"] for c in selected],
                      "commitment_minor": sum(c["cost_minor"] for c in selected),
                      "equivalent_profiles": [], "weights": policy,
                      "objective": float(result.fun) if result.fun is not None else None,
                      "mip_gap": float(result.mip_gap) if getattr(result, "mip_gap", None) is not None else None,
                      "message": str(result.message),
                      "qualification": "Optimality/feasibility is limited to the frozen enumerated candidate set; not an unrestricted network optimum."})
    return {"solver": "SciPy/HiGHS", "scipy_version": scipy.__version__, "version": VERSION,
            "seconds": round(time.monotonic() - started, 4), "variables": len(candidates),
            "constraints": matrix.shape[0], "plans": plans}


if __name__ == "__main__":
    try:
        print(json.dumps(solve(json.load(sys.stdin)), allow_nan=False))
    except Exception as error:
        print(json.dumps({"status": "FAILED", "error": str(error)}))
        sys.exit(1)
