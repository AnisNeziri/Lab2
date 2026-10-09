// Shared reads, bounded concurrency and background refresh without clearing data.
export function createDashboardResources(fetcher) {
  const records = new Map(), queue = [], controllers = new Set()
  let active = 0, disposed = false
  const pump = () => {
    while (!disposed && active < 4 && queue.length) {
      const task = queue.shift()
      active++
      task.run().finally(() => { active--; pump() })
    }
  }
  const record = key => {
    if (!records.has(key)) records.set(key, {state:{data:null,error:'',loading:false},listeners:new Set(),promise:null,at:0})
    return records.get(key)
  }
  const publish = r => r.listeners.forEach(fn => fn({...r.state}))
  return {
    state: key => ({...record(key).state}),
    subscribe(key, fn) { const r=record(key); r.listeners.add(fn); return () => r.listeners.delete(fn) },
    load(key, force=false) {
      if (disposed) return Promise.resolve(null)
      const r=record(key)
      if (r.promise) return r.promise
      if (!force && r.at && Date.now()-r.at<30000) return Promise.resolve(r.state.data)
      r.state={...r.state,loading:true,error:''}; publish(r)
      r.promise=new Promise(resolve => queue.push({
        cancel: () => { r.promise=null; resolve(null) },
        run: async () => {
          const controller=new AbortController(); controllers.add(controller)
          try {
            const data=await fetcher(key,{signal:controller.signal})
            if (!disposed) { r.state={data,error:'',loading:false};r.at=Date.now();publish(r) }
            resolve(data)
          } catch(e) {
            if (!disposed) { r.state={...r.state,error:e.message,loading:false};publish(r) }
            resolve(null)
          } finally { controllers.delete(controller);r.promise=null }
        },
      }))
      pump();return r.promise
    },
    refresh() { records.forEach((r,key) => { if(r.listeners.size) this.load(key,true) }) },
    dispose() {
      disposed=true
      queue.splice(0).forEach(task=>task.cancel())
      controllers.forEach(controller=>controller.abort())
      records.forEach(r=>r.listeners.clear())
    },
  }
}
