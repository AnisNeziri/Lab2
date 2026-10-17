import {test,expect} from '@playwright/test'
import {spawnSync} from 'node:child_process'
import {fileURLToPath} from 'node:url'

test('DC1 synthetic company stages, reviews, resumes, restores and locks an opening',async({page},testInfo)=>{
  test.setTimeout(240000)
  const database=fileURLToPath(new URL('../../../backend/database/e2e.sqlite',import.meta.url))
  const fixture=mode=>spawnSync('php',[fileURLToPath(new URL('../fixtures/onboarding.php',import.meta.url)),...(mode?[mode]:[])],{env:{...process.env,APP_ENV:'e2e',DB_CONNECTION:'sqlite',DB_DATABASE:database,DB_URL:'',CACHE_STORE:'array',SESSION_DRIVER:'array',QUEUE_CONNECTION:'sync',MAIL_MAILER:'array',BROADCAST_CONNECTION:'log'},encoding:'utf8'})
  const seed=fixture();expect(seed.status,seed.stderr||seed.stdout).toBe(0);const targets=JSON.parse(seed.stdout)
  const before=JSON.parse(fixture('verify').stdout),errors=[];page.on('pageerror',e=>errors.push(e.message))
  await page.goto('/login');await page.getByLabel('Email address').fill('opening-owner@aims.test');await page.getByLabel('Password',{exact:true}).fill('password');await page.getByRole('button',{name:'Enter workspace'}).click();await expect(page).toHaveURL(/dashboard/)
  await page.goto('/onboarding');await page.getByRole('button',{name:'New opening',exact:true}).click()
  for(const [label,value] of Object.entries({'Opening name':'Synthetic DC1 company opening','Source period':'Synthetic reviewed statements at cutoff','Cutover date':'2026-10-01','Company timezone':'Europe/Tirane','Inventory value':'4025.05','Customer receivables':'3700','Customer advances':'500','Supplier payables':'25000','Supplier advances':'6000','Cash':'235.50','Bank':'10000','Opening equity':'-1539.45'}))await page.getByLabel(label,{exact:true}).fill(value)
  await page.getByRole('button',{name:'Add unit',exact:true}).click();await page.getByLabel('Source quantity',{exact:true}).nth(0).fill('48')
  await page.getByRole('button',{name:'Add unit',exact:true}).click();await page.getByLabel('Source quantity',{exact:true}).nth(1).fill('1550.5')
  await page.getByRole('button',{name:'Create staging session',exact:true}).click();await expect(page).toHaveURL(/session=\d+/)
  const session=new URL(page.url()).searchParams.get('session'),step=async index=>page.locator('.opening-steps button').nth(index).click()
  const productHeader='external_ref,sku,name,unit,barcode,category,purchase_price,selling_price,conversion_unit,conversion_factor\n'
  let products=productHeader+'P001,MIL-04,Milano 04 ,meters,,Fabric,2.50,5.00,roll,50\nP002,NEW-02,New cloth pack,pcs,,Fabric,3.10,6.00,box,12\n'
  for(let i=3;i<=30;i++)products+=`P${String(i).padStart(3,'0')},NEW-${i},Extra synthetic product ${i},pcs,,Fixtures,2.50,5.00,,\n`
  const sources={
    products,
    customers:'external_ref,name,business_name,email,phone,tax_number,address\nC001,Reviewed customer duplicate,,customer@example.test,,,\n',
    suppliers:'external_ref,name,email,phone,address\nS001,Synthetic supplier,supplier@example.test,,\n',
    warehouses:'external_ref,code,name,address\nW001,MAIN,Main warehouse,\nW002,EAST,East warehouse,\n',
    inventory:'external_ref,product_ref,warehouse_ref,location_ref,quantity,unit,unit_cost,value,stock_state,reference\nI001,P001,W001,,1250.500,m,2.50,3126.25,available,Reviewed count A\nI002,P001,W002,,6,roll,2.50,750.00,available,Reviewed count B\nI003,P002,W001,,4,paleta,,148.80,available,Reviewed packing list\n',
    receivables:'external_ref,customer_ref,amount,currency,reference,due_date\nAR001,C001,3700,EUR,Reviewed AR statement,2026-10-31\n',
    customer_advances:'external_ref,customer_ref,amount,currency,reference\nCA001,C001,500,EUR,Reviewed customer advance\n',
    payables:'external_ref,supplier_ref,amount,currency,reference,due_date\nAP001,S001,25000,EUR,Reviewed AP statement,2026-11-01\n',
    supplier_advances:'external_ref,supplier_ref,amount,currency,reference\nSA001,S001,6000,EUR,Reviewed supplier advance\n',
    cash_bank:'external_ref,name,type,currency,amount,reference\nB001,Opening bank,bank,EUR,10000,Reviewed bank statement\nCASH001,Opening cash,cashbox,EUR,235.50,Reviewed cash count\n',
  }
  for(const [domain,csv] of Object.entries(sources)){
    await page.getByRole('combobox',{name:'Domain',exact:true}).selectOption(domain)
    await page.getByLabel('Source file',{exact:true}).first().setInputFiles({name:`synthetic-${domain}.csv`,mimeType:'text/csv',buffer:Buffer.from(csv)})
    const response=page.waitForResponse(r=>r.url().endsWith(`/onboarding/${session}/sources`)&&r.request().method()==='POST')
    await page.getByRole('button',{name:'Upload to staging',exact:true}).click();expect((await response).ok()).toBeTruthy();await expect(page.getByRole('status').filter({hasText:'Working…'})).toHaveCount(0)
  }
  expect(JSON.parse(fixture('verify').stdout)).toEqual(before)
  const validate=async()=>{await step(2);const r=page.waitForResponse(x=>x.url().endsWith(`/onboarding/${session}/validate`));await page.getByRole('button',{name:'Validate without posting',exact:true}).click();expect((await r).ok()).toBeTruthy()}
  await validate();await expect(page.getByText('Resolve source rows: Needs identity review',{exact:true})).toBeVisible()
  const edit=async(domain,reference,callback)=>{
    await step(1);await page.getByRole('combobox',{name:'Domain',exact:true}).selectOption(domain)
    const row=page.getByRole('row').filter({hasText:reference});await expect(row).toBeVisible();await row.getByRole('button',{name:'Review row',exact:true}).click()
    const dialog=page.getByRole('dialog').first();await callback(dialog)
    const response=page.waitForResponse(r=>r.url().endsWith(`/onboarding/${session}/rows`)&&r.request().method()==='PATCH')
    await dialog.getByRole('button',{name:'Save',exact:true}).click();expect((await response).ok()).toBeTruthy();await expect(page.getByRole('dialog')).toHaveCount(0)
  }
  await edit('products','P001',async dialog=>{await dialog.getByRole('combobox',{name:'Duplicate review',exact:true}).selectOption('use_existing');await dialog.getByRole('combobox',{name:'Existing record',exact:true}).selectOption(String(targets.product_id))})
  await edit('customers','C001',async dialog=>{await dialog.getByRole('combobox',{name:'Duplicate review',exact:true}).selectOption('use_existing');await dialog.getByRole('combobox',{name:'Existing record',exact:true}).selectOption(String(targets.customer_id))})
  await edit('inventory','I003',async dialog=>{await dialog.getByLabel('Base unit',{exact:true}).fill('box');await dialog.getByLabel('Cost per base unit',{exact:true}).fill('3.10')})
  await validate();await expect(page.getByText('All source controls reconcile.',{exact:true})).toBeVisible();expect(JSON.parse(fixture('verify').stdout)).toEqual(before)
  const phrase='synthetic-browser-opening-passphrase'
  const approve=async()=>{
    await step(3);await page.getByLabel('Backup passphrase',{exact:true}).fill(phrase)
    await page.getByRole('button',{name:'Create pre-cutover backup',exact:true}).click();await expect(page.getByRole('button',{name:'Download pre-cutover backup',exact:true})).toBeVisible()
    await page.getByLabel('I verified the source totals and opening equity.',{exact:true}).check();await page.getByLabel('I reviewed all warnings and excluded rows.',{exact:true}).check()
    await page.getByRole('button',{name:'Record review',exact:true}).click();await page.getByLabel('I approve this reviewed opening dataset.',{exact:true}).check();await expect(page.getByRole('button',{name:'Approve opening',exact:true})).toBeEnabled({timeout:30000})
    await page.getByRole('button',{name:'Approve opening',exact:true}).click();await expect(page.locator('.opening-summary')).toContainText('Approved')
  }
  await approve()
  await edit('products','P002',async dialog=>{
    await dialog.getByLabel('Name',{exact:true}).fill('Reviewed synthetic pack')
    await page.keyboard.press('Escape');await expect(page.getByRole('dialog',{name:'Discard unsaved changes?'})).toBeVisible();await page.getByRole('button',{name:'Keep Editing',exact:true}).click()
  })
  await step(4);await page.getByLabel('I confirm posting the approved opening.',{exact:true}).check();await expect(page.getByRole('button',{name:'Post the next batch',exact:true})).toBeDisabled()
  await validate();await approve();await step(4)
  await page.getByRole('button',{name:'Post the next batch',exact:true}).click();await expect(page.locator('.opening-summary')).toContainText('Posting')
  await page.reload();await step(4);await page.getByLabel('I confirm posting the approved opening.',{exact:true}).check();await page.getByRole('button',{name:'Post the next batch',exact:true}).click();await expect(page.locator('.opening-summary')).toContainText('Posted')
  const posted=JSON.parse(fixture('verify').stdout);expect(posted.stock_movements).toBe(3);expect(posted.purchase_orders).toBe(0);expect(posted.invoices).toBe(0);expect(posted.customer_debt_transactions).toBe(2)
  await step(5);await page.getByRole('button',{name:'Verify posted balances',exact:true}).click();await expect(page.locator('.opening-summary')).toContainText('Verified')
  await page.getByLabel('Backup passphrase',{exact:true}).fill(phrase);await page.getByRole('button',{name:'Create post-cutover backup',exact:true}).click();await expect(page.getByRole('button',{name:'Download post-cutover backup',exact:true})).toBeVisible()
  await page.getByLabel('Backup passphrase',{exact:true}).fill(phrase);const restore=page.waitForResponse(r=>r.url().endsWith('/restore-verify'),{timeout:60000});await page.getByRole('button',{name:'Restore into an independent database',exact:true}).click();expect((await restore).ok()).toBeTruthy();await expect(page.getByText('Independent restore passed',{exact:true})).toBeVisible({timeout:60000})
  await page.getByLabel('I confirm the verified opening is complete.',{exact:true}).check();await page.getByRole('button',{name:'Lock opening',exact:true}).click();await expect(page.getByText('Opening locked. Use normal corrections and reversals for later changes.',{exact:true})).toBeVisible()
  const download=page.waitForEvent('download');await page.getByRole('button',{name:'Download evidence summary',exact:true}).click();expect((await download).suggestedFilename()).toContain('evidence')
  for(const [language,theme] of [['en','light'],['en','dark'],['sq','light'],['sq','dark']]){
    await page.evaluate(async pref=>window.__aimsCertification.useSettingsStore.getState().applyPreferences(pref),{language,theme})
    for(const [width,height] of [[1920,1080],[1366,768],[768,1024],[390,844]]){
      await page.setViewportSize({width,height});expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2)).toBeTruthy()
      await page.screenshot({path:testInfo.outputPath(`opening-${language}-${theme}-${width}.png`),fullPage:true})
    }
  }
  expect(errors).toEqual([])
})
