import { apiRequest, authenticatedFetch, buildApiUrl } from './client'
export const documents = (filters) => apiRequest(buildApiUrl('/documents',filters))
export const config = () => apiRequest('/documents/config')
export const configure = (data) => apiRequest('/documents/config',{method:'PUT',body:JSON.stringify(data)})
export const detail = (id) => apiRequest(`/documents/${id}`)
export const update = (id,data) => apiRequest(`/documents/${id}`,{method:'PUT',body:JSON.stringify(data)})
export const action = (id,name,data={}) => apiRequest(`/documents/${id}/${name}`,{method:'POST',body:JSON.stringify(data)})
export const entities = (type,q='') => apiRequest(buildApiUrl(`/document-entities/${type}`,{q}))
export const requirements = (type,id) => apiRequest(`/document-requirements/${type}/${id}`)
export function upload(file,data,id,onProgress) {
  return new Promise((resolve,reject)=>{
    const xhr=new XMLHttpRequest();xhr.open('POST',buildApiUrl(id?`/documents/${id}/versions`:'/documents'))
    xhr.setRequestHeader('Authorization',`Bearer ${localStorage.getItem('api_token')}`);xhr.setRequestHeader('Accept','application/json')
    xhr.upload.onprogress=(e)=>{if(e.lengthComputable)onProgress?.(Math.round(e.loaded/e.total*100))}
    xhr.onerror=()=>reject(new Error('Upload interrupted. Check the document list before retrying.'))
    xhr.onload=()=>{let result;try{result=JSON.parse(xhr.responseText)}catch{reject(new Error('The upload could not be completed.'));return}if(xhr.status>=200&&xhr.status<300)resolve(result);else{const error=new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Upload failed');error.duplicate=result.duplicate?result.documents:null;reject(error)}}
    const form=new FormData();form.append('file',file);Object.entries(data).forEach(([k,v])=>{if(v!==null&&v!==undefined&&v!=='') {if(Array.isArray(v))v.forEach(x=>form.append(`${k}[]`,x));else form.append(k,typeof v==='boolean'?(v?'1':'0'):v)}});xhr.send(form)
  })
}
export async function file(id,version,preview=false){const r=await authenticatedFetch(buildApiUrl(`/documents/${id}/versions/${version}/file`,{preview:preview?'1':undefined}));if(!r.ok){const d=await r.json().catch(()=>({}));throw new Error(d.message||'Document cannot be downloaded.')}return URL.createObjectURL(await r.blob())}
