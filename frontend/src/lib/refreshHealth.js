let health={active:false,lastSuccess:0,failures:0}
const listeners=new Set()
export const refreshHealth={
  state:()=>health,
  subscribe:fn=>{listeners.add(fn);return()=>listeners.delete(fn)},
  report(success){health={active:true,lastSuccess:success?Date.now():health.lastSuccess,failures:success?0:health.failures+1};listeners.forEach(fn=>fn())},
  stop(){health={active:false,lastSuccess:0,failures:0};listeners.forEach(fn=>fn())},
}
export function refreshState(health,visible=true,online=true,now=Date.now(),dataHealthy=true){
  if(!visible||!health.active)return 'paused'
  if(!online||health.failures>=2||!dataHealthy||health.lastSuccess&&now-health.lastSuccess>45000)return 'warning'
  return health.lastSuccess?'healthy':'connecting'
}
