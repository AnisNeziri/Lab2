import { workProfiles } from '../config/workProfiles'
export default function WorkProfilePicker({language,onChoose,disabled}) {
 const sq=language==='sq'
 return <label className="work-profile-picker">{sq?'Fillo me një hapësirë pune':'Start from a work profile'}<select aria-label={sq?'Profili i punës':'Work profile'} value="" disabled={disabled} onChange={e=>onChoose(e.target.value)}><option value="">{sq?'Zgjidh një profil…':'Choose a profile…'}</option>{Object.entries(workProfiles).map(([id,p])=><option key={id} value={id}>{p[sq?'sq':'en']}</option>)}</select><small>{sq?'Vetëm pamja; lejet nuk ndryshojnë. Rishiko dhe ruaj për ta zbatuar.':'Layout only; permissions stay unchanged. Review and save to apply.'}</small></label>
}
