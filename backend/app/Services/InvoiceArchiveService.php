<?php
namespace App\Services;

use App\Contracts\DocumentStorageProvider;
use App\Models\{Company, Document, DocumentLink, DocumentType, DocumentVersion, Invoice};
use Illuminate\Support\Facades\{Auth, DB};
use Illuminate\Support\Str;

/** An immutable issue-time copy, not a second invoice or economic posting. */
class InvoiceArchiveService
{
    public function archive(Invoice $invoice): Document
    {
        abort_unless($invoice->issued_at && (int)$invoice->company_id === (int)Auth::user()->company_id, 403);
        return DB::transaction(function () use ($invoice) {
            Company::whereKey($invoice->company_id)->lockForUpdate()->firstOrFail();
            $key = 'issued-invoice:'.$invoice->invoice_number;
            if ($existing = Document::where('legacy_key', $key)->first()) return $existing;
            $invoice = app(InvoiceService::class)->find($invoice);
            $bytes = app(InvoicePdfService::class)->render($invoice);
            $checksum = hash('sha256', $bytes);
            $storageKey = $invoice->company_id.'/'.substr($checksum,0,2).'/'.Str::uuid();
            $stream = fopen('php://temp', 'w+b');
            try {
                fwrite($stream, $bytes); rewind($stream);
                app(DocumentStorageProvider::class)->put($storageKey, $stream);
            } finally { fclose($stream); }
            $type = DocumentType::firstOrCreate(['company_id'=>$invoice->company_id,'name'=>'Customer Invoice']);
            $document = Document::create([
                'uuid'=>(string)Str::uuid(), 'legacy_key'=>$key, 'reference'=>$invoice->invoice_number,
                'title'=>$invoice->invoice_number.'.pdf', 'description'=>'Immutable issued invoice copy captured at '.now()->toIso8601String().'; balances reflect capture time. Subsequent settlements remain in the source ledger.',
                'document_type_id'=>$type->id, 'status'=>'active', 'confidentiality'=>'restricted',
                'document_date'=>$invoice->invoice_date, 'retain_until'=>$invoice->retention_until,
                'legal_hold'=>true, 'hold_reason'=>'Issued financial document retention',
                'tags'=>['generated','invoice'], 'created_by'=>Auth::id(),
            ]);
            DocumentVersion::create(['document_id'=>$document->id,'version'=>1,'filename'=>$document->title,
                'mime_type'=>'application/pdf','size'=>strlen($bytes),'checksum'=>$checksum,
                'provider'=>'local','storage_key'=>$storageKey,'uploaded_by'=>Auth::id(),
                'change_note'=>'Automatically archived from issued invoice','integrity_status'=>'valid','verified_at'=>now()]);
            $links = [['invoice',$invoice->id]];
            $order = $invoice->dailySale?->outboundDispatch?->order;
            if (!$order && $invoice->original_invoice_id) $order = Invoice::find($invoice->original_invoice_id)?->dailySale?->outboundDispatch?->order;
            if ($order) $links[]=['sales-order',$order->id];
            if ($invoice->customer_id) $links[]=['customer',$invoice->customer_id];
            foreach ($links as [$entity,$id]) DocumentLink::create(['document_id'=>$document->id,'entity_type'=>$entity,'entity_id'=>$id,'linked_by'=>Auth::id()]);
            app(BusinessEventService::class)->record('document.invoice_archived',$invoice,$invoice->invoice_number,['document_id'=>$document->id],'invoice-archive:'.$invoice->id);
            return $document;
        });
    }
}
