import { useAuthStore } from '../store/authStore'
import { useWorkspaceStore } from '../store/workspaceStore'
import { availableWorkProfiles, suggestedWorkProfile } from '../config/everydayWorkspace'
import { readSession, sessionKey, writeSession } from '../lib/sessionWorkspace'

export function useWorkFocus(){
 const {user,role,permissions}=useAuthStore(),focus=useWorkspaceStore(s=>s.workFocus),setFocus=useWorkspaceStore(s=>s.setWorkFocus)
 const available=availableWorkProfiles(permissions,user?.commercial?.capabilities)
 const key=`${user?.company_id}:${user?.id}`
 const storageKey=sessionKey(key,'daily.profile')
 const preferred=focus?.key===key?focus.profile:readSession(sessionStorage,storageKey,suggestedWorkProfile(role,permissions))
 return {profile:available.includes(preferred)?preferred:available[0],available,setProfile:profile=>{writeSession(sessionStorage,storageKey,profile);setFocus(key,profile)}}
}
