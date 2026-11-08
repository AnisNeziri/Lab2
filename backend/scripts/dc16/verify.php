<?php
require __DIR__.'/api.php';
use Illuminate\Support\Facades\{Auth,DB};
// Read-only consistent snapshot. Do not log in/write auth-session rows while a
// historical day is being committed; authentication is tested separately.
$accounts=json_decode(file_get_contents($dc16Root.'/accounts.json'),true);
Auth::setUser(App\Models\User::withoutGlobalScopes()->findOrFail($accounts['primary']['user_id']));
$report=DB::transaction(function(){
    $checkpoint=DB::table('_dc16_checkpoints')->where('name','history')->first();
    $asOf=($GLOBALS['argv'][1]??'')==='post-ui'?date('Y-m-d'):($checkpoint?Carbon\CarbonImmutable::parse('2025-11-01')->addDays($checkpoint->completed_day)->toDateString():'2025-10-31');
    dc16Clock($asOf.' 23:59:00');
    $accounting=app(App\Services\AccountingService::class);
    $reconciliation=$accounting->reconciliation(false);
    $tb=$accounting->trialBalance(null,$asOf);$bs=$accounting->balanceSheet($asOf);
    $journals=DB::table('journal_entries as j')->leftJoin('journal_lines as l','l.journal_entry_id','=','j.id')->where('j.company_id',auth()->user()->company_id)->whereIn('j.status',['posted','reversed'])->groupBy('j.id','j.total_debit','j.total_credit')->selectRaw('j.id,j.total_debit,j.total_credit,ROUND(SUM(l.debit),2) debit,ROUND(SUM(l.credit),2) credit')->get();
    $unbalanced=$journals->filter(fn($j)=>$j->debit!=$j->credit||$j->debit!=$j->total_debit||$j->credit!=$j->total_credit)->values();
    $stock=[];foreach(App\Models\Product::orderBy('id')->get() as $p){$snapshot=app(App\Services\InventoryIntegrityService::class)->productSnapshot($p);if($snapshot['issues'])$stock[]=$snapshot;}
    $stateRows=DB::table('warehouse_stock')->where('company_id',auth()->user()->company_id)->whereRaw('ABS(quantity-available_quantity-reserved_quantity-damaged_quantity-quarantine_quantity-blocked_quantity)>=0.0005 OR quantity<0 OR available_quantity<0')->get();
    $invoiceIssues=[];$invoices=App\Models\Invoice::whereNotNull('issued_at')->get();
    foreach($invoices as $invoice){try{app(App\Services\InvoiceService::class)->find($invoice);}catch(Throwable $e){$invoiceIssues[]=['id'=>$invoice->id,'error'=>$e->getMessage()];}}
    $keys=collect(DB::select('SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME,ORDINAL_POSITION FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND REFERENCED_TABLE_SCHEMA=? ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION',[DB::getDatabaseName(),DB::getDatabaseName()]))->groupBy(fn($k)=>$k->TABLE_NAME.':'.$k->CONSTRAINT_NAME);
    $references=[];
    foreach($keys as $key=>$parts){
        $first=$parts->first();$join=implode(' AND ',$parts->map(fn($p)=>'c.`'.$p->COLUMN_NAME.'`=p.`'.$p->REFERENCED_COLUMN_NAME.'`')->all());
        $nonNull=implode(' AND ',$parts->map(fn($p)=>'c.`'.$p->COLUMN_NAME.'` IS NOT NULL')->all());
        $count=DB::selectOne('SELECT COUNT(*) n FROM `'.$first->TABLE_NAME.'` c LEFT JOIN `'.$first->REFERENCED_TABLE_NAME.'` p ON '.$join.' WHERE '.$nonNull.' AND p.`'.$first->REFERENCED_COLUMN_NAME.'` IS NULL')->n;
        if($count)$references[]=['constraint'=>$key,'violations'=>(int)$count];
    }
    $stats=[];foreach(['products','customers','suppliers','warehouses','warehouse_locations','employees','sales_orders','daily_sales','invoices','customer_quotations','purchase_orders','goods_receipts','stock_movements','customer_debt_transactions','purchase_order_payments','expenses','journal_entries','payroll_runs','payroll_lines','business_events','operational_tasks'] as $table)$stats[$table]=DB::table($table)->where('company_id',auth()->user()->company_id)->count();
    $stats['journal_lines']=DB::table('journal_lines as l')->join('journal_entries as j','j.id','=','l.journal_entry_id')->where('j.company_id',auth()->user()->company_id)->count();
    $payroll=App\Models\PayrollLine::orderBy('id')->get();$payrollErrors=[];
    foreach($payroll as $p){
        // Independent arithmetic oracle for this deliberately simple 5%/5%, primary-employer fixture.
        $g=(int)round((float)$p->gross*100);$ee=(int)round($g*.05);$taxable=$g-$ee;$tax=(int)round(min(max(0,$taxable-25000),20000)*.08+max(0,$taxable-45000)*.10);$net=$taxable-$tax-(int)round((float)$p->advance_repayment*100)-(int)round((float)$p->deductions*100);
        if((int)round((float)$p->employee_pension*100)!==$ee||(int)round((float)$p->tax*100)!==$tax||(int)round((float)$p->net*100)!==$net)$payrollErrors[]=$p->id;
    }
    return ['as_of'=>$asOf,'completed_days'=>($checkpoint?->completed_day??-1)+1,'stats'=>$stats,'posted_journals_checked'=>$journals->count(),'unbalanced_posted_journals'=>$unbalanced,'reconciliation'=>$reconciliation,'trial_balance'=>$tb,'balance_sheet'=>$bs,'inventory_issues'=>$stock,'warehouse_state_issues'=>$stateRows,'issued_documents_checked'=>$invoices->count(),'issued_document_issues'=>$invoiceIssues,'declared_constraints_checked'=>$keys->count(),'reference_violations'=>$references,'payroll_lines_checked'=>$payroll->count(),'independent_payroll_formula_errors'=>$payrollErrors];
});
if(($argv[1]??'')==='post-ui')dc16Evidence('integrity-post-ui',$report);else dc16Evidence('integrity-day-'.$report['completed_days'],$report);
if(($argv[1]??'')!=='post-ui')dc16Evidence('integrity-latest',$report);
echo json_encode(['days'=>$report['completed_days'],'journals'=>$report['posted_journals_checked'],'unbalanced'=>count($report['unbalanced_posted_journals']),'all_controls_reconciled'=>$report['reconciliation']['all_reconciled'],'inventory_issues'=>count($report['inventory_issues']),'declared_constraints'=>$report['declared_constraints_checked'],'reference_violations'=>count($report['reference_violations']),'payroll_formula_errors'=>count($report['independent_payroll_formula_errors'])])."\n";
if(count($report['unbalanced_posted_journals']) || !$report['reconciliation']['all_reconciled'] || !($report['trial_balance']['balanced']??false) || !($report['balance_sheet']['balanced']??false) || count($report['inventory_issues']) || count($report['warehouse_state_issues']) || count($report['issued_document_issues']) || count($report['reference_violations']) || count($report['independent_payroll_formula_errors']))exit(2);
