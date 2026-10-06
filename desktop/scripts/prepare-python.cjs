const fs = require('node:fs')
const path = require('node:path')
const {execFileSync} = require('node:child_process')
const desktop = path.resolve(__dirname, '..')
let source = process.env.AIMS_PYTHON_SOURCE || process.env.PYTHONHOME
if (!source) {
  try { source = path.dirname(execFileSync('python', ['-I','-c','import sys; print(sys.executable)'], {encoding:'utf8',windowsHide:true}).trim()) } catch {}
}
if (!source || !fs.existsSync(path.join(source,'python.exe'))) throw new Error('Set AIMS_PYTHON_SOURCE to a local Python 3.10+ directory to package zero-cost local forecasting.')
const destination = path.join(desktop,'resources','python')
fs.mkdirSync(destination,{recursive:true})
fs.cpSync(source,destination,{recursive:true,filter:file=>{
  const clean=value=>path.resolve(value.replace(/^\\\\\?\\/,''))
  const relative=path.relative(clean(source),clean(file)).replace(/\\/g,'/')
  if (!relative) return true
  if (/(^|\/)(site-packages|__pycache__|test|tests|idlelib|tkinter|ensurepip)(\/|$)/i.test(relative)) return false
  return /^(Lib|DLLs)(\/|$)/.test(relative)||/^(python(?:3\d*)?(?:w)?\.(?:exe|dll)|vcruntime[^/]*\.dll|LICENSE\.txt)$/i.test(relative)
}})
const optimizerVendor=path.resolve(desktop,'../backend/ml/optimizer_vendor')
if(!fs.existsSync(path.join(optimizerVendor,'scipy')))throw new Error('Install backend/ml/supply_optimizer_requirements.txt into backend/ml/optimizer_vendor before packaging.')
fs.cpSync(optimizerVendor,path.join(destination,'Lib','site-packages'),{recursive:true,filter:f=>!/[\\/](tests?|__pycache__)([\\/]|$)/i.test(f)})
execFileSync(path.join(destination,'python.exe'),['-I','-c','import sys,json,math; from scipy.optimize import milp; assert sys.version_info >= (3,10)'],{windowsHide:true})
console.log('Local Python forecasting and SciPy/HiGHS optimizer runtime bundled.')
