import { useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import * as api from "../../api/accounting";
import { getExpenses } from "../../api/finance";
import { getInvoices } from "../../api/invoices";
import WorkspaceModal from "../WorkspaceModal";
import { Amount, DateValue, Empty, Field, MoneyTable, Pager, QueryState, errorMessage, useFinance, useFinanceQuery } from "./FinanceShared";

export default function FinanceFixedAssets({ today }) {
  const { t, can, run, busy, refresh } = useFinance();
  const [params, setParams] = useSearchParams();
  const assetId = params.get("asset");
  const [asOf, setAsOf] = useState(today);
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [entryPage, setEntryPage] = useState(1);
  const [action, setAction] = useState(null);
  const openAsset = (id) => {
    setEntryPage(1);
    const next = new URLSearchParams(params);
    if (id) next.set("asset", id);
    else next.delete("asset");
    setParams(next);
  };
  const registry = useFinanceQuery(() => api.getFixedAssets({ as_of: asOf || today, search, page }), [asOf, today, search, page, refresh]);
  const record = useFinanceQuery(() => assetId ? api.getFixedAsset(assetId, { as_of: asOf || today, page: entryPage }) : null, [assetId, asOf, today, entryPage, refresh]);
  const details = record.data;
  const canPost = can("accounting.journal.post");
  const reversible = new Set(details?.entries.data.map(entry => entry.reverses_entry_id));
  const typeLabels = {
    acquisition: t("Acquisition", "Blerje"), addition: t("Acquisition cost", "Kosto e blerjes"), depreciation: t("Depreciation", "Zhvlerësim"),
    impairment: t("Impairment", "Rënie në vlerë"), write_off: t("Write-off", "Çregjistrim"), reversal: t("Reversal", "Anulim"), sale: t("Asset sale", "Shitje e asetit"),
  };
  const metrics = [
    ["cost", t("Acquisition cost", "Kostoja e blerjes")], ["depreciation", t("Accumulated depreciation", "Zhvlerësimi i akumuluar")],
    ["impairment", t("Impairment", "Rënia në vlerë")], ["net_book_value", t("Net book value", "Vlera kontabël neto")],
  ];
  return (
    <section className="fc-panel">
      <div className="fc-section-head">
        <div>
          <h2>{t("Fixed assets", "Asetet fikse")}</h2>
          <p>{t("Review equipment costs, depreciation and adjustments.", "Shqyrto kostot e pajisjeve, zhvlerësimin dhe korrigjimet.")}</p>
        </div>
        <div className="fc-toolbar">
          {can("accounting.settings.manage") && <button type="button" className="secondary" onClick={() => setAction({ kind: "category" })}>{t("Add category policy", "Shto politikën e kategorisë")}</button>}
          {canPost && <button type="button" onClick={() => setAction({ kind: "prepare" })}>{t("Review an investment bill", "Shqyrto një faturë investimi")}</button>}
        </div>
      </div>
      <div className="fc-form-grid">
        <Field label={t("As of", "Deri më datën")} type="date" max={today} value={asOf} onChange={e => setAsOf(e.target.value)} />
        <Field label={t("Find an asset", "Gjej një aset")} type="search" value={search} onChange={e => { setSearch(e.target.value); setPage(1); }} />
      </div>
      <QueryState query={registry}>{data => <>
        <h3>{t("Register and ledger", "Regjistri dhe libri kryesor")}</h3>
        <p role="status">{data.reconciliation.reconciled
          ? t("Asset balances match the General Ledger.", "Gjendjet e aseteve përputhen me librin kryesor.")
          : t("Review asset balance differences and any older investment bills.", "Shqyrto diferencat dhe faturat e mëparshme të investimit.")}</p>
        {data.reconciliation.legacy_investment_bills > 0 && <p>{t("Older investment bills to review", "Fatura të mëparshme për shqyrtim")} · {data.reconciliation.legacy_investment_bills}</p>}
        {!data.assets.data.length ? <Empty>{t("No assets match this view.", "Nuk ka asete në këtë pamje.")}</Empty> : <MoneyTable label={t("Asset register", "Regjistri i aseteve")}>
          <thead><tr><th>{t("Asset", "Aseti")}</th><th>{t("Category", "Kategoria")}</th><th>{t("Cost", "Kostoja")}</th><th>{t("Net book value", "Vlera kontabël neto")}</th></tr></thead>
          <tbody>{data.assets.data.map(asset => <tr key={asset.id}>
            <td><button type="button" className="secondary" aria-pressed={String(asset.id) === assetId} onClick={() => openAsset(asset.id)}>{asset.asset_number} · {asset.name}</button></td>
            <td>{asset.category.name}</td><td><Amount value={asset.cost} /></td><td><Amount value={asset.net_book_value} /></td>
          </tr>)}</tbody>
        </MoneyTable>}
        <Pager page={data.assets} onPage={setPage} />
      </>}</QueryState>
      {assetId && <QueryState query={record}>{asset => asset && <section className="fc-panel" aria-label={t("Asset detail", "Hollësitë e asetit")}>
        <div className="fc-section-head">
          <div><h3>{asset.asset_number} · {asset.name}</h3><p>{asset.category.policy_reference} · {asset.category.useful_life_months} {t("months", "muaj")}</p></div>
          <button type="button" className="secondary" onClick={() => openAsset(null)}>{t("Close details", "Mbyll hollësitë")}</button>
        </div>
        <div className="fc-form-grid">{metrics.map(([key, label]) => <div key={key}>{label}<br /><strong><Amount value={asset[key]} /></strong></div>)}</div>
        <p>{t("Available for use", "Gati për përdorim")} · <DateValue value={asset.available_for_use_on} /> · {t("Depreciation due through selected date", "Zhvlerësimi deri në datën e zgjedhur")} <Amount value={asset.depreciation_due} /></p>
        {canPost && Number(asset.cost) > 0 && <div className="fc-toolbar">
          {!asset.available_for_use_on && <button type="button" onClick={() => setAction({ kind: "service" })}>{t("Set in-service date", "Cakto datën e përdorimit")}</button>}
          {Number(asset.depreciation_due) > 0 && <button type="button" onClick={() => setAction({ kind: "depreciation" })}>{t("Review depreciation", "Shqyrto zhvlerësimin")}</button>}
          <button type="button" className="secondary" onClick={() => setAction({ kind: "impairment" })}>{t("Record impairment", "Regjistro rënie në vlerë")}</button>
          <button type="button" className="secondary" onClick={() => setAction({ kind: "write_off" })}>{t("Write off asset", "Çregjistro asetin")}</button>
          {can("invoices.manage") && <button type="button" className="secondary" onClick={() => setAction({ kind: "sale" })}>{t("Link a sale invoice", "Lidh një faturë shitjeje")}</button>}
        </div>}
        <MoneyTable label={t("Asset history", "Historiku i asetit")}>
          <thead><tr><th>{t("Date", "Data")}</th><th>{t("Event", "Veprimi")}</th><th>{t("Source and journal", "Burimi dhe ditari")}</th></tr></thead>
          <tbody>{asset.entries.data.map(entry => <tr key={entry.id}>
            <td><DateValue value={entry.effective_on} /></td>
            <td>{typeLabels[entry.kind]}<br /><small>{entry.reason}</small></td>
            <td><div className="fc-toolbar">
              {entry.expense && <Link to={`/finance?expense=${entry.expense.id}`}>{entry.expense.document_number}</Link>}
              {entry.sale_invoice && can("invoices.manage") && <Link to={`/invoices?invoice=${entry.sale_invoice.id}`}>{entry.sale_invoice.invoice_number || t("Open draft sale invoice", "Hap faturën paraprake të shitjes")}</Link>}
              {entry.journal && <Link to={`/accounting?tab=journal&journal=${entry.journal.id}`}>{entry.journal.journal_number}</Link>}
              {entry.expense && !entry.journal && canPost && <button type="button" className="secondary" onClick={() => setAction({ kind: "withdraw", id: entry.expense.id })}>{t("Withdraw draft review", "Tërhiq shqyrtimin paraprak")}</button>}
              {entry.sale_invoice && !entry.journal && canPost && can("invoices.manage") && <button type="button" className="secondary" onClick={() => setAction({ kind: "withdrawSale", id: entry.id })}>{t("Withdraw draft review", "Tërhiq shqyrtimin paraprak")}</button>}
              {entry.journal && !entry.expense && !entry.sale_invoice_id && !entry.reverses_entry_id && !reversible.has(entry.id) && can("accounting.journal.reverse") && <button type="button" className="secondary" onClick={() => setAction({ kind: "reverse", id: entry.id })}>{t("Reverse entry", "Anulo regjistrimin")}</button>}
            </div></td>
          </tr>)}</tbody>
        </MoneyTable>
        <Pager page={asset.entries} onPage={setEntryPage} />
      </section>}</QueryState>}
      {action && <AssetAction key={action.kind} action={action} today={today} asOf={asOf || today}
        asset={details} categories={registry.data?.categories || []} onClose={() => setAction(null)}
        run={run} busy={busy} t={t} openAsset={openAsset} />}
    </section>
  );
}

function AssetAction({ action, today, asOf, asset, categories, onClose, run, busy, t, openAsset }) {
  const { kind } = action;
  const [requestKey] = useState(() => globalThis.crypto?.randomUUID?.() || `asset-${Date.now()}-${Math.random().toString(36).slice(2)}`);
  const [error, setError] = useState("");
  const [billSearch, setBillSearch] = useState("");
  const [fields, setFields] = useState({ date: kind === "service" ? today : asOf, residual_value: "0" });
  const bills = useFinanceQuery(() => kind === "prepare" ? getExpenses({ status: "draft", asset_treatment: "investment", search: billSearch, per_page: 30 }) : null, [kind, billSearch]);
  const invoices = useFinanceQuery(() => kind === "sale" ? getInvoices({ status: "draft", search: billSearch, per_page: 30 }) : null, [kind, billSearch]);
  const field = (key) => ({ value: fields[key] ?? "", onChange: e => setFields(current => ({ ...current, [key]: e.target.value })) });
  const money = { type: "number", min: "0", step: ".01", required: true };
  const titles = {
    category: t("Add category policy", "Shto politikën e kategorisë"), prepare: t("Review an investment bill", "Shqyrto një faturë investimi"),
    service: t("Set in-service date", "Cakto datën e përdorimit"), depreciation: t("Review depreciation", "Shqyrto zhvlerësimin"),
    impairment: t("Record impairment", "Regjistro rënie në vlerë"), write_off: t("Write off asset", "Çregjistro asetin"),
    reverse: t("Reverse entry", "Anulo regjistrimin"), withdraw: t("Withdraw draft review", "Tërhiq shqyrtimin paraprak"),
    sale: t("Link a sale invoice", "Lidh një faturë shitjeje"), withdrawSale: t("Withdraw draft sale", "Tërhiq shitjen paraprake"),
  };
  const submit = async (event) => {
    event.preventDefault();
    const { date, reason, amount, expense_id, invoice_id, attach_to_existing, minimum_cost, useful_life_months, policy_reference, name, category_id, asset_number, residual_value } = fields;
    const actions = {
      category: () => api.createFixedAssetCategory({ name, minimum_cost, useful_life_months, policy_reference }),
      prepare: () => api.prepareFixedAssetBill(expense_id, attach_to_existing
        ? { fixed_asset_id: asset.id, reason } : { category_id, asset_number, name, residual_value, reason }),
      service: () => api.placeFixedAssetInService(asset.id, { date, reason }),
      reverse: () => api.reverseFixedAssetEntry(action.id, { date, reason }),
      withdraw: () => api.withdrawFixedAssetBill(action.id, { reason }),
      sale: () => api.prepareFixedAssetSale(asset.id, { invoice_id, reason }),
      withdrawSale: () => api.withdrawFixedAssetSale(action.id, { reason }),
    };
    await run(async () => {
      setError("");
      try {
        const result = await (actions[kind] || (() => api.postFixedAssetEntry(asset.id, {
          kind, date, reason, request_key: requestKey, ...(kind === "impairment" ? { amount } : {}),
        })))();
        if (kind === "prepare") openAsset(result.id);
        if (kind === "withdraw") openAsset(null);
        onClose();
      } catch (err) {
        setError(errorMessage(err));
        throw err;
      }
    });
  };
  return <WorkspaceModal title={titles[kind]} onClose={onClose}>
    <form className="fc-form" onSubmit={submit}>
      {error && <p className="fc-error" role="alert">{error}</p>}
      {kind === "category" && <>
        <Field label={t("Category name", "Emri i kategorisë")} required {...field("name")} />
        <Field label={t("Minimum capitalization cost", "Kosto minimale për kapitalizim")} {...money} {...field("minimum_cost")} />
        <Field label={t("Useful life in months", "Jeta e dobishme në muaj")} type="number" min="1" max="1200" required {...field("useful_life_months")} />
        <Field label={t("Approved policy reference", "Referenca e politikës së miratuar")} required {...field("policy_reference")} />
        <p>{t("Book depreciation is straight-line from the in-service date, prorated by day. Tax depreciation is separate.", "Zhvlerësimi kontabël është linear nga data e përdorimit, sipas ditëve. Zhvlerësimi tatimor trajtohet veçmas.")}</p>
      </>}
      {kind === "prepare" && <>
        <Field label={t("Find a draft bill", "Gjej një faturë paraprake")} type="search" value={billSearch} onChange={e => setBillSearch(e.target.value)} />
        <QueryState query={bills}>{result => result && <Field label={t("Investment bill", "Fatura e investimit")}>
          <select required {...field("expense_id")}>
            <option value="">{t("Choose a bill", "Zgjidh faturën")}</option>
            {(result.data || []).map(bill => <option key={bill.id} value={bill.id}>{bill.document_number} · {bill.vendor_name}</option>)}
          </select>
        </Field>}</QueryState>
        {asset && !asset.available_for_use_on && Number(asset.cost) > 0 && <label className="fc-check"><input type="checkbox" checked={!!fields.attach_to_existing} onChange={e => setFields(current => ({ ...current, attach_to_existing: e.target.checked }))} />{t("Add this cost to the selected asset", "Shto këtë kosto te aseti i zgjedhur")}</label>}
        {!fields.attach_to_existing && <>
          <Field label={t("Category policy", "Politika e kategorisë")}><select required {...field("category_id")}>
            <option value="">{t("Select approved policy", "Zgjidh politikën e miratuar")}</option>
            {categories.map(category => <option key={category.id} value={category.id}>{category.name}</option>)}
          </select></Field>
          <Field label={t("Asset number", "Numri i asetit")} required {...field("asset_number")} />
          <Field label={t("Asset name", "Emri i asetit")} required {...field("name")} />
          <Field label={t("Residual value", "Vlera e mbetur")} {...money} {...field("residual_value")} />
        </>}
        <p>{t("This records your capitalization decision. Post the bill through its existing approval workflow.", "Kjo regjistron vendimin për kapitalizim. Posto faturën përmes procesit ekzistues të miratimit.")}</p>
      </>}
      {kind === "sale" && <>
        <p>{t("Use a draft customer invoice with a single non-stock line for this asset. Issue it after the review is confirmed.", "Përdor një faturë paraprake me një rresht pa stok për këtë aset. Lëshoje pas konfirmimit të shqyrtimit.")}</p>
        <Field label={t("Find a draft invoice", "Gjej një faturë paraprake")} type="search" value={billSearch} onChange={e => setBillSearch(e.target.value)} />
        <QueryState query={invoices}>{result => result && <Field label={t("Draft sale invoice", "Fatura paraprake e shitjes")}><select required {...field("invoice_id")}>
          <option value="">{t("Choose an invoice", "Zgjidh faturën")}</option>
          {(result.data || []).map(invoice => <option key={invoice.id} value={invoice.id}>{invoice.customer_name} · {invoice.grand_total} {invoice.currency}</option>)}
        </select></Field>}</QueryState>
      </>}
      {!["category", "prepare", "withdraw", "sale", "withdrawSale"].includes(kind) && <Field label={t("Accounting date", "Data kontabël")} type="date" max={today} required readOnly={kind === "depreciation"} {...field("date")} />}
      {kind === "depreciation" && <p>{t("Depreciation through the selected date", "Zhvlerësimi deri në datën e zgjedhur")} <strong><Amount value={asset.depreciation_due} /></strong></p>}
      {kind === "impairment" && <Field label={t("Supported impairment amount", "Vlera e dokumentuar e rënies")} {...money} {...field("amount")} />}
      {kind !== "category" && <Field label={t("Reason or evidence reference", "Arsyeja ose referenca e dëshmisë")} required minLength={5} maxLength={1000} {...field("reason")} />}
      <div className="fc-toolbar">
        <button type="submit" disabled={busy}>{t("Confirm", "Konfirmo")}</button>
        <button type="button" className="secondary" onClick={onClose}>{t("Cancel", "Anulo")}</button>
      </div>
    </form>
  </WorkspaceModal>;
}
