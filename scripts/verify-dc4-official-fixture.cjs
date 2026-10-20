// Read-only reproduction of the downloaded ATK BN24 calculator, independent of AIMS PHP formulas.
const fs=require('node:fs'),vm=require('node:vm'),crypto=require('node:crypto'),path=require('node:path')
const file=path.resolve('output/dc4-official/bn24.html'),html=fs.readFileSync(file,'utf8')
const source=[...html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)].map(m=>m[1]).find(s=>s.includes('var paga0deri250Perqinjda'))
if(!source)throw new Error('Reviewed ATK calculator script is missing; do not infer the fixture.')
const values=new Map(),controls=new Map()
for(const tag of html.match(/<input\b[^>]*>/gi)||[]){const id=tag.match(/\bid=["']([^"']+)["']/i)?.[1],value=tag.match(/\bvalue=["']([^"']*)["']/i)?.[1];if(id)values.set('#'+id,value||'0')}
function $(selector){if(typeof selector==='function')return;const key=String(selector);if(!controls.has(key)){const control={val(value){if(value===undefined)return values.get(key)||'0';values.set(key,String(value));return control}};for(const method of ['on','keypress','keydown','change','each','addClass','removeClass','attr','prop','ready'])control[method]=()=>control;controls.set(key,control)}return controls.get(key)}
const sandbox={$ ,document:{},Intl,Math,parseFloat,parseInt,Number,console:{log(){}}}
vm.createContext(sandbox);vm.runInContext(source,sandbox,{timeout:1000})
for(const [key,value] of Object.entries({emptype:'1',pagaBruto:'1000',perqindjaPuntorit:'5',perqindjaPunedhenesit:'5'}))values.set('#'+key,value)
const functions=Object.keys(sandbox).filter(k=>/^update/.test(k)&&typeof sandbox[k]==='function')
for(let pass=0;pass<3;pass++)for(const name of functions)sandbox[name]()
const read=id=>Number(String(values.get('#'+id)).replaceAll(',','')).toFixed(2)
const result={source:'https://crmm.atk-ks.org/sq/Public/CalculatorDetail?id=21',download_sha256:crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex'),method:'Executed published inline BN24 calculation functions with isolated input controls; no AIMS calculator code used.',inputs:{gross:'1000.00',employer:'primary',employee_pension_rate:'5',employer_pension_rate:'5'},outputs:{employee_pension:read('totKontributiPuntorit'),employer_pension:read('totKontributiPunedhenesit'),taxable:read('pagaQeTatohet'),tax:read('totaliTatimitBruto'),net:read('pagaNeto')}}
if(JSON.stringify(result.outputs)!==JSON.stringify({employee_pension:'50.00',employer_pension:'50.00',taxable:'950.00',tax:'66.00',net:'884.00'}))throw new Error(JSON.stringify(result))
fs.writeFileSync('output/dc4-official/authoritative-fixture.json',JSON.stringify(result,null,2)+'\n');process.stdout.write(JSON.stringify(result,null,2)+'\n')
