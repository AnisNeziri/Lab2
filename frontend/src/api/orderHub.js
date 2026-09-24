import { apiRequest, authenticatedFetch, buildApiUrl } from './client'
const request=(path,method='GET',data)=>apiRequest(`/order-hub${path}`,{method,...(data?{body:JSON.stringify(data)}:{})})
export const orderHub={
  list:filters=>apiRequest(buildApiUrl('/order-hub',filters)),
  channels:()=>request('/channels'),
  lookups:(kind,search='')=>apiRequest(buildApiUrl('/order-hub/lookups',{kind,search})),
  channel:(data,id)=>request(`/channels${id?`/${id}`:''}`,id?'PUT':'POST',data),
  show:id=>request(`/intakes/${id}`),
  create:(channel,data)=>request(channel?`/channels/${channel}/orders`:'/orders','POST',data),
  resolve:(id,data)=>request(`/intakes/${id}`,'PUT',data),
  action:(id,action,data={})=>request(`/intakes/${id}/${action}`,'POST',{idempotency_key:crypto.randomUUID(),...data}),
  mappings:id=>request(`/channels/${id}/mappings`),
  map:(id,data)=>request(`/channels/${id}/mappings`,'POST',data),
  keys:id=>request(`/channels/${id}/keys`),
  key:(id,data)=>request(`/channels/${id}/keys`,'POST',data),
  revoke:(id,key)=>request(`/channels/${id}/keys/${key}`,'DELETE'),
  report:filters=>apiRequest(buildApiUrl('/order-hub/report',filters)),
  presets:()=>request('/presets'),
  savePreset:data=>request('/presets','POST',data),
  deletePreset:id=>request(`/presets/${id}`,'DELETE'),
  preview:file=>{const data=new FormData();data.append('file',file);return apiRequest('/order-hub/imports/preview',{method:'POST',body:data})},
  import:(channel,orders)=>request(`/channels/${channel}/imports`,'POST',{orders}),
  bulk:data=>request('/bulk','POST',{idempotency_key:crypto.randomUUID(),...data}),
  rules:()=>apiRequest('/approval-rules'),
  saveRule:data=>apiRequest('/approval-rules',{method:'PUT',body:JSON.stringify(data)}),
  export:async(filters,format,language)=>{
    const response=await authenticatedFetch(buildApiUrl('/order-hub/export',{...filters,format,language}))
    if(!response.ok){const data=await response.json();throw new Error(data.message||'Export failed')}
    const url=URL.createObjectURL(await response.blob()),a=document.createElement('a');a.href=url;a.download=`AIMS-orders.${format}`;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000)
  },
}
