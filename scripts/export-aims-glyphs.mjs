// Deterministic editable SVG export, using the same authored vectors as React.
import {glyphPaths} from '../frontend/src/components/icons/glyphPaths.js'
import {writeFileSync} from 'node:fs'
const path=new URL('../frontend/src/components/icons/aims-glyphs.svg',import.meta.url)
const symbols=Object.entries(glyphPaths).flatMap(([name,definition])=>[['',definition.paths],...(definition.small?[['-small',definition.small]]:[])].map(([suffix,paths])=>`  <symbol id="${name}${suffix}" viewBox="0 0 24 24" stroke-width="${suffix?1.6:1.7}">\n${paths.map(d=>`    <path d="${d}"/>`).join('\n')}\n  </symbol>`))
writeFileSync(path,`<svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">\n  <!-- AIMS Glyphs: Open Rail. Editable source export; not loaded at runtime. -->\n${symbols.join('\n')}\n</svg>\n`)
console.log(`Exported ${Object.keys(glyphPaths).length} AIMS glyphs and their small-size variants.`)
