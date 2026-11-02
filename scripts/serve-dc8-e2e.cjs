const {spawnSync}=require('node:child_process'),path=require('node:path'),{pathToFileURL}=require('node:url')
const vite=path.resolve(process.cwd(),'node_modules/vite/bin/vite.js')
const build=spawnSync(process.execPath,[vite,'build','--mode','certification','--outDir','dist-dc8'],{stdio:'inherit',windowsHide:true});if(build.status!==0)process.exit(build.status??1)
;(async()=>{
 const {preview}=await import(pathToFileURL(path.resolve('node_modules/vite/dist/node/index.js')).href)
 const csp="default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://*.tile.openstreetmap.org https://*.basemaps.cartocdn.com; font-src 'self' data:; connect-src 'self' ws://localhost:4181; worker-src 'self' blob:; frame-src 'self' blob:; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'"
 const server=await preview({mode:'certification',build:{outDir:'dist-dc8'},preview:{host:'127.0.0.1',port:4181,strictPort:true,headers:{'Content-Security-Policy':csp},proxy:{'/api':{target:'http://127.0.0.1:8018',changeOrigin:true}}}})
 for(const signal of ['SIGTERM','SIGINT'])process.on(signal,()=>{server.httpServer.close();process.exit()})
})().catch(e=>{console.error(e);process.exit(1)})
