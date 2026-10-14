"""Bounded startup probe for the pinned local runtime; no app or training writes."""
import time, json, os, sys
started=time.perf_counter()
import numpy, scipy
from scipy.optimize import milp
elapsed=time.perf_counter()-started
if os.name=="nt":
 import ctypes
 from ctypes import wintypes
 class Counters(ctypes.Structure):
  _fields_=[("cb",wintypes.DWORD),("PageFaultCount",wintypes.DWORD)]+[(x,ctypes.c_size_t) for x in ["PeakWorkingSetSize","WorkingSetSize","QuotaPeakPagedPoolUsage","QuotaPagedPoolUsage","QuotaPeakNonPagedPoolUsage","QuotaNonPagedPoolUsage","PagefileUsage","PeakPagefileUsage"]]
 c=Counters();c.cb=ctypes.sizeof(c)
 ctypes.windll.kernel32.GetCurrentProcess.restype=wintypes.HANDLE
 handle=ctypes.windll.kernel32.GetCurrentProcess()
 ctypes.windll.psapi.GetProcessMemoryInfo.argtypes=[wintypes.HANDLE,ctypes.POINTER(Counters),wintypes.DWORD]
 if not ctypes.windll.psapi.GetProcessMemoryInfo(handle,ctypes.byref(c),c.cb):raise RuntimeError("Cannot measure own process memory")
 peak=c.PeakWorkingSetSize
else:
 import resource
 peak=resource.getrusage(resource.RUSAGE_SELF).ru_maxrss*(1 if sys.platform=="darwin" else 1024)
print(json.dumps({"python":sys.version.split()[0],"numpy":numpy.__version__,"scipy":scipy.__version__,"import_seconds":elapsed,"cpu_seconds":time.process_time(),"peak_rss_bytes":peak}))
