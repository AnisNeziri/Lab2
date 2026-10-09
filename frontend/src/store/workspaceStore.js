import { create } from 'zustand'
import { apiRequest } from '../api/client'

let sequence=0
export const useWorkspaceStore=create((set,get)=>({
  userKey:null,document:null,loading:false,saving:false,error:'',
  async load(user) {
    const key=user?`${user.company_id}:${user.id}`:null, ticket=++sequence
    if(!key){set({userKey:null,document:null,loading:false,saving:false,error:''});return}
    const saved=user.preferences?.workspace
    set({userKey:key,document:saved?.user_id===user.id&&saved?.company_id===user.company_id?saved:null,loading:true,error:''})
    try{const document=await apiRequest('/settings/workspace');if(ticket===sequence)set({document,loading:false})}
    catch(e){if(ticket===sequence)set({loading:false,error:e.message})}
  },
  async save(section,value) {
    if(get().saving)return false
    const {document,userKey}=get()
    if(!document)throw new Error('Workspace settings have not loaded. Retry before saving.')
    set({saving:true,error:''})
    try {
      const next=await apiRequest('/settings/workspace',{method:'PUT',body:JSON.stringify({revision:document.revision,[section]:value})})
      if(get().userKey===userKey)set({document:next,saving:false})
      return true
    }catch(e){if(get().userKey===userKey)set({saving:false,error:e.message});throw e}
  },
}))
