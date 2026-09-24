import {test,expect} from '@playwright/test'
import {login} from '../helpers/auth.mjs'

test('PDF evidence opens in the browser preview',async({page})=>{
  await login(page)
  const errors=[]
  page.on('console',message=>{if(message.type()==='error')errors.push(message.text())})
  const objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>']
  const stream='BT /F1 16 Tf 30 100 Td (AIMS document preview) Tj ET'
  objects.push(`<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`)
  let pdf='%PDF-1.4\n';const offsets=[0]
  for(let i=0;i<objects.length;i++){offsets.push(pdf.length);pdf+=`${i+1} 0 obj\n${objects[i]}\nendobj\n`}
  const start=pdf.length;pdf+=`xref\n0 6\n0000000000 65535 f \n${offsets.slice(1).map(n=>String(n).padStart(10,'0')+' 00000 n ').join('\n')}\ntrailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n${start}\n%%EOF`
  await page.goto('/documents?upload=1')
  await page.getByLabel('Document file',{exact:true}).setInputFiles({name:'preview.pdf',mimeType:'application/pdf',buffer:Buffer.from(pdf)})
  await page.getByRole('button',{name:'Upload',exact:true}).click()
  await expect(page).toHaveURL(/document=\d+/)
  await page.getByRole('button',{name:'Preview',exact:true}).click()
  await expect(page.getByRole('region',{name:'PDF preview'})).toBeVisible()
  await expect(page.getByRole('img',{name:'PDF page'})).toHaveAttribute('aria-busy','false')
  await expect(page.locator('.document-pdf-text')).toHaveText('AIMS document preview')
  await page.screenshot({path:'test-results/document-pdf-preview.png'})
  expect(errors.filter(e=>/sandbox|plugin|pdf/i.test(e))).toEqual([])
})

test('image preview closes cleanly and Albanian document controls render',async({page})=>{
  const errors=[];page.on('pageerror',error=>errors.push(error.message))
  await login(page)
  await page.goto('/documents?upload=1')
  await page.getByLabel('Document file',{exact:true}).setInputFiles({name:'preview.png',mimeType:'image/png',buffer:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jWZkAAAAASUVORK5CYII=','base64')})
  await page.getByLabel('Title (optional)',{exact:true}).fill('Preview evidence')
  await page.getByRole('button',{name:'Upload',exact:true}).click()
  await expect(page).toHaveURL(/document=\d+/)
  await page.getByRole('button',{name:'Preview',exact:true}).click()
  const dialog=page.getByRole('dialog',{name:'Document preview'})
  await expect(dialog).toBeVisible()
  await expect(dialog.locator('img')).toBeVisible()
  expect(await dialog.locator('img').evaluate(img=>img.complete&&img.naturalWidth>0)).toBeTruthy()
  await page.keyboard.press('Escape')
  await expect(dialog).toHaveCount(0)
  expect(await page.evaluate(()=>document.body.style.overflow)).not.toBe('hidden')
  await page.evaluate(async()=>{const {useSettingsStore}=await import('/src/store/settingsStore.js');useSettingsStore.getState().applyPreferences({language:'sq',theme:'dark'})})
  await expect(page.getByRole('heading',{name:'Qendra e Dokumenteve',exact:true})).toBeVisible()
  await page.getByRole('button',{name:'Ndrysho të dhënat',exact:true}).click()
  await expect(page.getByRole('combobox',{name:'Konfidencialiteti',exact:true})).toBeVisible()
  expect(errors).toEqual([])
})

async function headers(page){return {Authorization:`Bearer ${await page.evaluate(()=>localStorage.getItem('api_token'))}`}}
async function openUpload(page,title,bytes='Document center browser evidence'){
  await page.goto('/documents?upload=1')
  await page.getByLabel('Document file',{exact:true}).setInputFiles({name:'evidence.txt',mimeType:'text/plain',buffer:Buffer.from(bytes)})
  await page.getByLabel('Title (optional)',{exact:true}).fill(title)
  await page.getByRole('button',{name:'Upload',exact:true}).click()
  await expect(page).toHaveURL(/document=\d+/)
  return new URL(page.url()).searchParams.get('document')
}
test('upload, download, linked PO, versions, duplicate warning, expiry and archive',async({page})=>{
  await login(page);const id=await openUpload(page,'Browser evidence')
  await expect(page.getByRole('heading',{name:'Browser evidence',exact:true})).toBeVisible()
  const download=page.waitForEvent('download');await page.getByRole('button',{name:'Download',exact:true}).first().click();expect((await download).suggestedFilename()).toBe('evidence.txt')
  const h=await headers(page)
  const suppliers=await (await page.request.get('/api/suppliers',{headers:h})).json()
  const supplier=(suppliers.data||suppliers)[0]
  expect(supplier).toBeTruthy()
  const created=await page.request.post('/api/purchase-orders',{headers:h,data:{supplier_id:supplier.id,ordered_at:'2026-09-14',currency:'EUR',status:'draft',items:[{description:'Document link fixture',unit:'pcs',quantity:1,unit_price:10}]}})
  expect(created.ok(),await created.text()).toBeTruthy()
  const po=await created.json()
  expect(po,'Seeded PO required for document relationship certification').toBeTruthy();{const linked=await page.request.post(`/api/documents/${id}/link`,{headers:h,data:{entity_type:'purchase-order',entity_id:po.id}});expect(linked.ok()).toBeTruthy();await page.goto(`/purchase-orders?po=${po.id}`);await expect(page.getByRole('link',{name:'Browser evidence'}).first()).toBeVisible();await page.getByRole('link',{name:'Browser evidence'}).first().click()}
  await page.getByRole('button',{name:'New version',exact:true}).click()
  await page.getByLabel('Document file',{exact:true}).setInputFiles({name:'revision.txt',mimeType:'text/plain',buffer:Buffer.from('Second immutable version')})
  await page.getByLabel('What changed?',{exact:true}).fill('Revised evidence')
  await page.getByRole('button',{name:'Upload',exact:true}).click()
  await expect(page.getByText('v2 • Current',{exact:true})).toBeVisible()
  expect((await page.request.get(`/api/documents/${id}/versions/1/file`,{headers:h})).ok()).toBeTruthy()
  await page.goto('/documents?upload=1');await page.getByLabel('Document file',{exact:true}).setInputFiles({name:'copy.txt',mimeType:'text/plain',buffer:Buffer.from('Document center browser evidence')});await page.getByRole('button',{name:'Upload',exact:true}).click();await expect(page.getByText(/These exact bytes already exist/)).toBeVisible()
  await page.goto(`/documents?document=${id}`);await page.getByRole('button',{name:'Edit metadata',exact:true}).click();await page.getByLabel('Expiry date',{exact:true}).fill('2025-01-01');await page.getByRole('button',{name:'Save metadata',exact:true}).click();await expect(page.getByText('This document has expired.',{exact:true})).toBeVisible()
  await page.getByRole('button',{name:'Archive',exact:true}).click();await expect(page.getByRole('button',{name:'Restore',exact:true})).toBeVisible();await expect(page.getByText('revision.txt',{exact:false})).toBeVisible()
})
test('shared approval engine and restricted access',async({page})=>{
  await login(page);const id=await openUpload(page,'Approval evidence','Approve these bytes')
  await page.getByRole('button',{name:'Request review',exact:true}).click();await page.getByRole('button',{name:'Approve',exact:true}).click();await expect(page.getByRole('alert')).toContainText('own request')
  await page.evaluate(()=>localStorage.clear());await login(page,'manager');await page.goto(`/documents?document=${id}`);await page.getByRole('button',{name:'Approve',exact:true}).click();await expect(page.getByText('Approval: Approved',{exact:true})).toBeVisible()
  const h=await headers(page);expect((await page.request.put(`/api/documents/${id}`,{headers:h,data:{confidentiality:'restricted'}})).ok()).toBeTruthy()
  await page.evaluate(()=>localStorage.clear());await login(page,'staff');const hs=await headers(page);expect((await page.request.get(`/api/documents/${id}`,{headers:hs})).status()).toBe(404);expect((await page.request.get(`/api/documents/${id}/versions/1/file`,{headers:hs})).status()).toBe(404)
})
for(const width of [390,768,1024,1366,1920])test(`document workspace responsive ${width}`,async({page})=>{
  await page.setViewportSize({width,height:900});await login(page);await page.goto('/documents?upload=1');await expect(page.getByLabel('Document file',{exact:true})).toBeVisible()
  for(const theme of ['light','dark']){await page.evaluate(theme=>{document.documentElement.dataset.theme=theme},theme);await expect(page.getByRole('heading',{name:'Document Center',exact:true})).toBeVisible();expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1)).toBeTruthy();await page.screenshot({path:`test-results/documents-${width}-${theme}.png`,fullPage:true})}
})
test('encrypted document backup roundtrip preserves version checksum',async({page})=>{
  await login(page);const id=await openUpload(page,'Backup browser evidence','Browser backup fixture');const h=await headers(page)
  const before=await (await page.request.get(`/api/documents/${id}`,{headers:h})).json()
  const backup=await page.request.post('/api/backup/export',{headers:h,data:{modules:['documents'],passphrase:'browser-doc-secret'}});expect(backup.ok()).toBeTruthy()
  const restored=await page.request.post('/api/backup/import',{headers:h,multipart:{file:{name:'docs.aimsbackup',mimeType:'application/octet-stream',buffer:await backup.body()},passphrase:'browser-doc-secret'}});expect(restored.ok(),await restored.text()).toBeTruthy()
  await page.goto(`/documents?document=${id}`);await expect(page.getByRole('heading',{name:'Backup browser evidence',exact:true})).toBeVisible();const after=await (await page.request.get(`/api/documents/${id}`,{headers:h})).json();expect(after.versions[0].checksum).toBe(before.versions[0].checksum);expect((await page.request.get(`/api/documents/${id}/versions/1/file`,{headers:h})).ok()).toBeTruthy()
})
