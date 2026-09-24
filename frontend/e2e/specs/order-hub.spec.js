import {test,expect} from '@playwright/test'
import {login} from '../helpers/auth.mjs'

async function api(page,path,data){
  const headers={Authorization:`Bearer ${await page.evaluate(()=>localStorage.getItem('api_token'))}`}
  const r=data===undefined?await page.request.get(`/api${path}`,{headers}):await page.request.post(`/api${path}`,{headers,data})
  expect(r.ok(),await r.text()).toBeTruthy();return r.json()
}
async function fixture(page){
  const tag=`HUB-${Date.now()}-${Math.floor(Math.random()*1000)}`,first=x=>(x.data||x)[0]
  const category=first(await api(page,'/categories')),warehouse=first(await api(page,'/warehouses'))
  const product=await api(page,'/products',{name:tag,sku:tag,category_id:category.id,default_warehouse_id:warehouse.id,unit:'pcs',quantity:20,min_quantity:1,purchase_price:2,selling_price:5,price:5})
  const customer=await api(page,'/customers',{name:`Customer ${tag}`,email:`${tag.toLowerCase()}@example.test`})
  const channel=await api(page,'/order-hub/channels',{name:`Channel ${tag}`,type:'api',enabled:true,currency:'EUR',allow_guest:false,acceptance:'review',oversale_policy:'accept_backorder',price_tolerance:0})
  return {product,customer,channel,tag}
}
test('Order Hub creates, confirms, reserves, links WMS and exposes safe tracking',async({page})=>{
  const errors=[];page.on('pageerror',e=>errors.push(e.message));await login(page);const f=await fixture(page)
  await page.goto('/order-hub?new=1')
  await page.getByRole('combobox',{name:'Find customer',exact:true}).fill(f.customer.email)
  await page.getByRole('listbox',{name:'Find customer'}).getByRole('option').filter({hasText:f.customer.name}).click()
  await page.getByRole('combobox',{name:'Add a product',exact:true}).fill(f.product.sku)
  await page.getByRole('listbox',{name:'Add a product'}).getByRole('option').filter({hasText:f.product.name}).click()
  await page.getByLabel('Quantity',{exact:true}).fill('3')
  await page.getByRole('button',{name:'Review order',exact:true}).click()
  await page.getByRole('button',{name:'Save order',exact:true}).click();await expect(page).toHaveURL(/intake=\d+/)
  await page.getByRole('button',{name:'Confirm & reserve',exact:true}).click();await expect(page.getByRole('button',{name:'Mark ready',exact:true})).toBeVisible()
  await page.getByRole('button',{name:'Create tracking link',exact:true}).click();const tracking=page.getByRole('link',{name:/\/order-tracking\//});await expect(tracking).toBeVisible()
  const href=await tracking.getAttribute('href');const intake=await api(page,`/order-hub/intakes/${new URL(page.url()).searchParams.get('intake')}`)
  expect(Number(intake.order.items[0].reserved_quantity)).toBe(3)
  await page.getByRole('link',{name:'Open pick, pack, dispatch & returns',exact:true}).click();await expect(page).toHaveURL(new RegExp(`fulfillment\\?order=${intake.order.id}`))
  await page.goto(href);await expect(page.getByText(f.product.name,{exact:true})).toBeVisible();expect(errors).toEqual([])
})
test('simple Orders journey dispatches once, opens its Daily Sale and creates a linked invoice',async({page})=>{
  const errors=[];page.on('pageerror',e=>errors.push(e.message));await login(page);const f=await fixture(page)
  const intake=await api(page,'/order-hub/orders',{idempotency_key:f.tag,customer_id:f.customer.id,order_date:new Date().toLocaleDateString('en-CA'),payment_type:'cash',items:[{product_id:f.product.id,quantity:2}]})
  const url=`/order-hub?intake=${intake.id}`
  await page.goto(url)
  await page.getByRole('button',{name:'Confirm & reserve',exact:true}).click()
  await page.getByRole('checkbox',{name:'I verified these products and quantities are ready.',exact:true}).check()
  await page.getByRole('button',{name:'Mark ready',exact:true}).click()
  await page.getByRole('button',{name:'Confirm cash received & dispatch',exact:true}).click()
  await expect(page.getByRole('link',{name:/^DS-/})).toBeVisible()
  const dispatched=await api(page,`/order-hub/intakes/${intake.id}`)
  expect(dispatched.connections.sales).toHaveLength(1)
  const stockAfterDispatch=await api(page,`/products/${f.product.id}`)
  expect(Number(stockAfterDispatch.product.quantity)).toBe(18)
  await page.getByLabel('Recipient',{exact:true}).fill('Test recipient')
  await page.getByRole('button',{name:'Confirm full delivery',exact:true}).click()
  await expect(page.getByRole('heading',{name:/Next action: View sale, invoice or return/})).toBeVisible()
  await page.getByRole('link',{name:dispatched.connections.sales[0].sale_number,exact:true}).click()
  await expect(page).toHaveURL(/daily-sales\?date=/)
  await expect(page.getByRole('link',{name:/SO-/}).first()).toBeVisible()
  await page.goto(url)
  await page.getByRole('button',{name:'Create invoice',exact:true}).click()
  await expect(page).toHaveURL(/invoices\?invoice=\d+/)
  await expect(page.getByRole('link',{name:/Source order/})).toBeVisible()
  const linked=await api(page,`/order-hub/intakes/${intake.id}`)
  expect(linked.connections.invoices).toHaveLength(1)
  expect(linked.connections.sales).toHaveLength(1)
  const stockAfterInvoice=await api(page,`/products/${f.product.id}`)
  expect(Number(stockAfterInvoice.product.quantity)).toBe(18)
  await page.goto('/order-hub')
  await page.getByRole('button',{name:'Paid',exact:true}).click()
  await expect(page.getByRole('cell',{name:dispatched.order.order_number,exact:true})).toBeVisible()
  expect(errors).toEqual([])
})

test('external mapping control and customer portal submit real customer-bound orders',async({page})=>{
  await login(page);const f=await fixture(page);await page.goto('/order-hub?channels=1')
  const row=page.locator('.fulfillment-task').filter({hasText:f.channel.name}).first();await row.getByRole('button',{name:'Edit',exact:true}).click()
  await page.getByLabel('Find existing record',{exact:true}).fill(f.product.name)
  await page.getByLabel('AIMS record',{exact:true}).selectOption(String(f.product.id));await page.getByLabel('External identifier',{exact:true}).fill('REMOTE-ITEM')
  await page.getByRole('button',{name:'Save mapping',exact:true}).click();await expect(page.getByRole('cell',{name:'REMOTE-ITEM',exact:true})).toBeVisible()
  const key=await api(page,`/order-hub/channels/${f.channel.id}/keys`,{customer_id:f.customer.id,scopes:['orders:create','orders:read','orders:cancel','catalog:read'],expires_at:new Date(Date.now()+86400000).toISOString()})
  await page.goto('/order-portal');await page.getByLabel('Customer access credential',{exact:true}).fill(key.token);await page.getByRole('button',{name:'Open my account',exact:true}).click()
  await expect(page.getByRole('heading',{name:f.customer.name,exact:true})).toBeVisible();await page.getByLabel('Search catalog',{exact:true}).fill(f.product.name)
  await page.getByRole('button',{name:'Add to order',exact:true}).click();await page.getByRole('button',{name:'Submit order for validation',exact:true}).click()
  await expect(page.getByRole('status')).toContainText('Order received');await expect(page.getByRole('heading',{name:/^SO-/})).toBeVisible()
  expect(await page.evaluate(token=>JSON.stringify(localStorage).includes(token),key.token)).toBe(false)
})
test('CSV preview, import and saved view operate through the visible controls',async({page})=>{
  await login(page);const f=await fixture(page);await page.goto('/order-hub')
  await page.getByText('Import orders from CSV',{exact:true}).click()
  await page.getByLabel('CSV file',{exact:true}).setInputFiles({name:'orders.csv',mimeType:'text/csv',buffer:Buffer.from(`external_id,customer_email,sku,quantity\n${f.tag},${f.customer.email},${f.product.sku},2\n`)})
  await expect(page.getByRole('cell',{name:f.tag,exact:true})).toBeVisible();await page.getByLabel('Import channel',{exact:true}).selectOption(String(f.channel.id));await page.getByRole('button',{name:'Confirm import',exact:true}).click()
  await expect(page.getByRole('status')).toContainText('Import completed')
  await page.getByText('Saved views & order templates',{exact:true}).click();await page.getByLabel('Saved view or template name',{exact:true}).fill(f.tag);await page.getByRole('button',{name:'Save view',exact:true}).click()
  await expect(page.getByRole('button',{name:'Open view',exact:true}).last()).toBeVisible()
})
for(const width of [390,768,1024,1366,1920])test(`Order Hub layouts at ${width}px in both themes`,async({page})=>{
  await page.setViewportSize({width,height:900});await login(page);await page.goto('/order-hub');await expect(page.getByRole('heading',{name:'Orders',exact:true})).toBeVisible()
  for(const theme of ['light','dark']){
    await page.evaluate(t=>document.documentElement.dataset.theme=t,theme)
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBeTruthy()
    await page.screenshot({path:`test-results/order-hub-${width}-${theme}.png`,fullPage:true})
  }
})
test('Albanian labels, channel rules and order export remain usable',async({page})=>{
  await login(page);const f=await fixture(page)
  const token=await page.evaluate(()=>localStorage.getItem('api_token')),headers={Authorization:`Bearer ${token}`}
  const changed=await page.request.put('/api/settings/preferences',{headers,data:{language:'sq',theme:'dark'}});expect(changed.ok()).toBeTruthy()
  try{
    await page.goto('/order-hub?channels=1');await expect(page.getByRole('heading',{name:'Porositë',exact:true})).toBeVisible()
    await page.locator('.fulfillment-task').filter({hasText:f.channel.name}).first().getByRole('button',{name:'Ndrysho',exact:true}).click()
    await page.getByText('Rregullat e çmimeve & promocionet',{exact:true}).click();await page.getByRole('button',{name:'Shto rregull çmimi',exact:true}).click()
    await page.getByLabel('Emri i rregullit',{exact:true}).fill('Promocion prove');await page.getByLabel('Vlera e zbritjes',{exact:true}).fill('10')
    const saved=page.waitForResponse(r=>r.url().endsWith(`/order-hub/channels/${f.channel.id}`)&&r.request().method()==='PUT');await page.getByRole('button',{name:'Ruaj kanalin',exact:true}).click();expect((await saved).ok()).toBeTruthy()
    await page.goto('/order-hub');await page.getByText('Eksporto & veprime në grup',{exact:true}).click()
    const download=page.waitForEvent('download');await page.getByRole('button',{name:'Eksporto XLSX',exact:true}).click();expect((await download).suggestedFilename()).toBe('AIMS-orders.xlsx')
  }finally{await page.request.put('/api/settings/preferences',{headers,data:{language:'en',theme:'light'}})}
})
