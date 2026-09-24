import {test,expect} from '@playwright/test'
import {login} from '../helpers/auth.mjs'

async function api(page,path,data){
  const headers={Authorization:`Bearer ${await page.evaluate(()=>localStorage.getItem('api_token'))}`}
  const response=data===undefined?await page.request.get(`/api${path}`,{headers}):await page.request.post(`/api${path}`,{headers,data})
  expect(response.ok(),`${response.status()} ${await response.text()}`).toBeTruthy()
  return response.json()
}
async function fixture(page,credit=false){
  const tag=`WMS-${Date.now()}-${Math.floor(Math.random()*1000)}`
  const categories=await api(page,'/categories'),suppliers=await api(page,'/suppliers'),warehouses=await api(page,'/warehouses')
  const first=x=>(x.data||x)[0]
  const product=await api(page,'/products',{name:tag,sku:tag,category_id:first(categories).id,supplier_id:first(suppliers).id,default_warehouse_id:first(warehouses).id,unit:'pcs',quantity:20,min_quantity:1,purchase_price:2,selling_price:5,price:5})
  const customer=await api(page,'/customers',{name:`Buyer ${tag}`,credit_limit:credit?1:null})
  return {product,customer}
}
async function createOrder(page,{product,customer},quantity=4,payment='cash'){
  await page.goto('/fulfillment?new=1')
  await expect(page.getByRole('button',{name:'Save sales order',exact:true})).toBeVisible()
  await page.getByLabel('Find customer',{exact:true}).fill(customer.name)
  await page.getByRole('combobox',{name:'Customer',exact:true}).selectOption(String(customer.id))
  await page.getByRole('combobox',{name:'Payment',exact:true}).selectOption(payment)
  await page.getByLabel('Find product',{exact:true}).fill(product.name)
  await page.getByRole('combobox',{name:'Add product',exact:true}).selectOption(String(product.id))
  await page.getByLabel('Quantity 1',{exact:true}).fill(String(quantity))
  const saved=page.waitForResponse(r=>r.url().endsWith('/api/sales-orders')&&r.request().method()==='POST')
  await page.getByRole('button',{name:'Save sales order',exact:true}).click()
  expect((await saved).status()).toBe(201)
  await expect(page).toHaveURL(/order=\d+/)
  return new URL(page.url()).searchParams.get('order')
}
async function action(page,name,endpoint){
  const response=page.waitForResponse(r=>r.url().endsWith(`/${endpoint}`)&&r.request().method()==='POST')
  await page.getByRole('button',{name,exact:true}).click()
  const result=await response;expect(result.ok(),await result.text()).toBeTruthy()
  return result.json()
}
async function pickPack(page,quantity){
  await page.getByLabel('I verified the product, location and lot',{exact:true}).check()
  await page.getByLabel('Total picked',{exact:true}).fill(String(quantity))
  await action(page,'Verify pick','pick')
  await page.getByRole('spinbutton',{name:/^Pack quantity /}).fill(String(quantity))
  return action(page,'Create package','pack')
}
async function dispatch(page){
  await page.locator('.fulfillment-task').filter({hasText:/PKG-/}).locator('input[type="checkbox"]').last().check()
  return action(page,'Dispatch selected packages','dispatch')
}

test('complete outbound UI cycle, draft edit, assignment, proof and return',async({page})=>{
  test.setTimeout(90000)
  const errors=[];page.on('pageerror',e=>errors.push(e.message));await login(page)
  const f=await fixture(page);const id=await createOrder(page,f)
  await page.getByRole('button',{name:'Edit draft',exact:true}).click()
  await page.getByLabel('Notes',{exact:true}).fill('Edited before confirmation')
  await action(page,'Save sales order','edit')
  await action(page,'Confirm order','confirm');await action(page,'Reserve available stock','reserve')
  await page.getByRole('combobox',{name:'Assign new tasks to',exact:true}).selectOption({label:'Enterprise Staff'})
  await action(page,'Create pick tasks','allocate')
  await page.getByRole('combobox',{name:'Assigned picker',exact:true}).selectOption('')
  await action(page,'Save assignment','assign')
  await pickPack(page,4)
  const before=await api(page,`/products/${f.product.id}`);expect(Number(before.product.quantity)).toBe(20)
  await dispatch(page)
  await page.getByRole('button',{name:'Mark all quantities delivered',exact:true}).click()
  await page.getByLabel('Recipient',{exact:true}).fill('Warehouse customer')
  const delivered=await action(page,'Confirm delivery','delivery');expect(delivered.status).toBe('delivered')
  await page.getByRole('combobox',{name:'Delivered product / package',exact:true}).selectOption({index:1})
  await page.getByLabel('Return quantity',{exact:true}).fill('1');await page.getByLabel('Reason',{exact:true}).fill('Damaged packaging')
  await action(page,'Request return','return');await action(page,'Authorize return','return');await action(page,'Receive return','return')
  const returned=await action(page,'Inspect & resolve','return');expect(returned.returns[0].status).toBe('resolved')
  expect(Number((await api(page,`/products/${f.product.id}`)).product.quantity)).toBe(17)
  expect((await api(page,`/sales-orders/${id}`)).dispatches).toHaveLength(1)
  expect(errors).toEqual([])
})

test('partial allocation, short pick, partial dispatch and cumulative delivery',async({page})=>{
  test.setTimeout(90000);await login(page);const f=await fixture(page);const id=await createOrder(page,f,25)
  await action(page,'Confirm order','confirm');const reserved=await action(page,'Reserve available stock','reserve');expect(Number(reserved.items[0].reserved_quantity)).toBe(20)
  await action(page,'Create pick tasks','allocate')
  await page.getByLabel('Short-pick reason (if needed)',{exact:true}).fill('Only part ready today')
  await pickPack(page,3);await dispatch(page)
  await page.getByRole('spinbutton',{name:/^Delivered quantity /}).fill('1');await page.getByLabel('Recipient',{exact:true}).fill('Partial recipient')
  const partial=await action(page,'Confirm delivery','delivery');expect(partial.status).toBe('partially_delivered');expect(Number(partial.items[0].delivered_quantity)).toBe(1)
  await page.getByRole('button',{name:'Mark all quantities delivered',exact:true}).click()
  const delivered=await action(page,'Confirm delivery','delivery');expect(Number(delivered.items[0].delivered_quantity)).toBe(3);expect(delivered.status).toBe('partially_delivered')
  expect(Number((await api(page,`/products/${f.product.id}`)).product.quantity)).toBe(17)
  expect(Number((await api(page,`/sales-orders/${id}`)).items[0].base_quantity)).toBe(25)
})

test('credit rejection, shared approval UI, and exact order confirmation',async({page})=>{
  test.setTimeout(90000);await login(page);const f=await fixture(page,true);const id=await createOrder(page,f,4,'credit')
  await page.getByRole('button',{name:'Confirm order',exact:true}).click();await expect(page.getByRole('alert')).toContainText('Credit approval is required')
  const requested=await action(page,'Request credit override','credit-override')
  await page.evaluate(()=>localStorage.clear());await login(page,'manager');await page.goto('/procurement')
  const approvals=page.locator('.procurement-record').filter({hasText:'customer_credit'})
  await expect(approvals).toHaveCount(1)
  await approvals.getByRole('button',{name:'Approve',exact:true}).click()
  await expect(approvals).toHaveCount(0)
  await page.goto(`/fulfillment?order=${id}`);const confirmed=await action(page,'Confirm order','confirm');expect(confirmed.approval.id).toBe(requested.approval.id)
  expect(confirmed.status).toBe('ready_to_allocate')
})

test('worker permissions and command filters use actual route state',async({page})=>{
  await login(page);await page.goto('/fulfillment?status=packed');await expect(page.getByLabel('Order status',{exact:true})).toHaveValue('packed')
  await page.goto('/fulfillment?view=returns');await expect(page.getByText('Filtered view:',{exact:false})).toContainText('Returns awaiting action')
  await page.goto('/fulfillment?view=late');await expect(page.getByText('Filtered view:',{exact:false})).toContainText('Late orders')
  await page.evaluate(()=>localStorage.clear());await login(page,'staff');await page.goto('/fulfillment?new=1')
  await expect(page.getByRole('button',{name:'New sales order',exact:true})).toHaveCount(0)
  await expect(page.getByRole('button',{name:'Save sales order',exact:true})).toHaveCount(0)
})

for(const width of [390,768,1024,1366,1920])test(`fulfillment responsive ${width}`,async({page})=>{
  await page.setViewportSize({width,height:900});await login(page);await page.goto('/fulfillment?new=1')
  await expect(page.getByRole('button',{name:'Save sales order',exact:true})).toBeVisible()
  for(const theme of ['light','dark']){await page.evaluate(theme=>document.documentElement.dataset.theme=theme,theme);expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1)).toBeTruthy();await page.screenshot({path:`test-results/fulfillment-${width}-${theme}.png`,fullPage:true})}
  const existing=(await api(page,'/sales-orders')).data.find(o=>o.tasks_count>0)
  if(existing){await page.goto(`/fulfillment?order=${existing.id}`);await expect(page.getByRole('heading',{name:'Pick & verify',exact:true})).toBeVisible();for(const theme of ['light','dark']){await page.evaluate(theme=>document.documentElement.dataset.theme=theme,theme);expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1)).toBeTruthy();await page.screenshot({path:`test-results/picking-${width}-${theme}.png`,fullPage:true})}}
})
