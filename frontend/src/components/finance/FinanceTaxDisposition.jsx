import { useState } from "react";
import * as api from "../../api/accounting";
import WorkspaceModal from "../WorkspaceModal";
import FinanceCorporateTaxReview from "./FinanceCorporateTaxReview";
import { errorMessage, Field, QueryState, useFinance, useFinanceQuery } from "./FinanceShared";

export function taxStatus(state, t) {
  const labels = {
    missing_policy: ["Record the company’s tax policy for this year.", "Regjistro politikën tatimore të kompanisë për këtë vit."],
    unresolved: ["Confirm which tax regime applies.", "Konfirmo cili regjim tatimor zbatohet."],
    unsupported: ["Have the accountant review and document the treatment for this regime.", "Kërko nga kontabilisti të shqyrtojë dhe të dokumentojë trajtimin për këtë regjim."],
    unsupported_year_end: ["This period includes year-end. Final close requires the complete configured fiscal year. You can still use soft close.", "Kjo periudhë përfshin fundin e vitit. Mbyllja përfundimtare kërkon tërë vitin fiskal të konfiguruar. Mund të përdorësh mbylljen paraprake."],
    stale_policy: ["Review and approve the policy against current evidence.", "Shqyrto dhe mirato politikën sipas dëshmive aktuale."],
    review_required: ["Calculate and approve the corporate-tax worksheet.", "Llogarit dhe mirato fletën e tatimit të korporatave."],
    legacy_review: ["Recalculate the worksheet under the current evidence rules.", "Rillogarit fletën sipas rregullave aktuale të dëshmive."],
    stale_review: ["The source evidence changed. Recalculate and approve the worksheet again.", "Dëshmitë burimore kanë ndryshuar. Rillogarit dhe mirato fletën përsëri."],
    journal_conflict: ["Reconcile current-tax journals before closing.", "Rakordo ditarët e tatimit aktual para mbylljes."],
    approved: ["Corporate-tax worksheet approved and current.", "Fleta e tatimit të korporatave është miratuar dhe është aktuale."],
    inapplicable: ["Inapplicability decision approved and current.", "Vendimi për moszbatimin është miratuar dhe është aktual."],
    alternative_reviewed: ["Alternative tax treatment reviewed and reconciled.", "Trajtimi alternativ tatimor është shqyrtuar dhe rakorduar."],
  };
  return labels[state] ? t(...labels[state]) : "";
}

export default function FinanceTaxDisposition({ period, onClose }) {
  const { t, can, run, refresh, busy } = useFinance();
  const query = useFinanceQuery(() => api.getTaxDisposition(period.id), [period.id, refresh]);
  const [edit, setEdit] = useState(false);
  const [regime, setRegime] = useState("actual_income");
  const [error, setError] = useState("");
  const canApprove = period.status === "open" && can("accounting.periods.close") && can("accounting.journal.post");

  return <WorkspaceModal title={t("Annual corporate-tax review", "Shqyrtimi vjetor i tatimit të korporatave")} onClose={onClose} wide>
    <QueryState query={query}>{data => {
      const policy = data.decision?.policy;
      return <>
        <p role="status">{taxStatus(data.state, t)}</p>
        {policy && <p className="fc-caption">{policy.jurisdiction} · {policy.regime_name} · {policy.policy_reference}</p>}
        {canApprove && !edit && <button type="button" className="secondary" onClick={() => { setRegime(policy?.regime || "actual_income"); setEdit(true); }}>
          {t("Record tax policy", "Regjistro politikën tatimore")}
        </button>}
        {edit && <form className="fc-form" onSubmit={async event => {
          event.preventDefault();
          setError("");
          const payload = Object.fromEntries(new FormData(event.currentTarget));
          const saved = await run(async () => {
            try {
              return await api.approveTaxDisposition(period.id, payload);
            } catch (failure) {
              setError(errorMessage(failure));
              throw failure;
            }
          });
          if (saved) setEdit(false);
        }}>
          {error && <p className="fc-error" role="alert">{error}</p>}
          <div className="fc-form-grid">
            <Field label={t("Treatment", "Trajtimi")}><select name="regime" value={regime} onChange={e => setRegime(e.target.value)}>
              <option value="actual_income">{t("Actual income · worksheet required", "Të ardhurat reale · kërkohet fleta")}</option>
              <option value="inapplicable">{t("No corporate-tax obligation for this period", "Pa detyrim për tatimin e korporatave në këtë periudhë")}</option>
              <option value="alternative_reviewed">{t("Alternative treatment reviewed", "Trajtim alternativ i shqyrtuar")}</option>
              <option value="unresolved">{t("Applicability unconfirmed", "Zbatueshmëria e pakonfirmuar")}</option>
              <option value="unsupported">{t("Unsupported treatment", "Trajtim i pambështetur")}</option>
            </select></Field>
            <Field label={t("Jurisdiction (country code)", "Juridiksioni (kodi i shtetit)")} name="jurisdiction" required pattern="[A-Za-z]{2}" maxLength={2} defaultValue={policy?.jurisdiction || ""} />
            <Field label={t("Regime or legal basis", "Regjimi ose baza ligjore")} name="regime_name" required minLength={5} maxLength={160} defaultValue={policy?.regime_name} />
            <Field label={t("Policy or evidence reference", "Referenca e politikës ose dëshmisë")} name="policy_reference" required minLength={5} maxLength={500} defaultValue={policy?.policy_reference} />
            <Field label={t("Effective from", "Në fuqi nga")} type="date" name="effective_from" required defaultValue={policy?.effective_from || String(period.starts_at).slice(0, 10)} />
            <Field label={t("Effective through", "Në fuqi deri më")} type="date" name="effective_to" required defaultValue={policy?.effective_to || String(period.ends_at).slice(0, 10)} />
            {regime === "alternative_reviewed" && <Field label={t("Recorded current tax in company currency", "Tatimi aktual i regjistruar në valutën e kompanisë")} type="number" name="recorded_tax" min="0" step="0.01" required defaultValue={policy?.recorded_tax} />}
          </div>
          <Field label={t("Reason and accounting evidence", "Arsyeja dhe dëshmia kontabël")} name="reason" required minLength={10} maxLength={1000} defaultValue={policy?.reason} />
          <div className="fc-toolbar">
            <button disabled={busy}>{t("Approve and record policy", "Mirato dhe regjistro politikën")}</button>
            <button type="button" className="secondary" onClick={() => { setError(""); setEdit(false); }}>{t("Cancel", "Anulo")}</button>
          </div>
        </form>}
        {!edit && policy?.regime === "actual_income" && ["review_required", "legacy_review", "stale_review", "approved", "journal_conflict"].includes(data.state) &&
          <FinanceCorporateTaxReview key={data.decision.id} period={period} onClose={onClose} />}
      </>;
    }}</QueryState>
  </WorkspaceModal>;
}
