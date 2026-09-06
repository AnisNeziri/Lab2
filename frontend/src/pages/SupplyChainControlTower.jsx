import { useCallback, useEffect, useMemo, useState } from "react";
import { AlertTriangle, Anchor, Boxes, CheckCircle2, ExternalLink, RefreshCw, Radio, Route, Ship } from "lucide-react";
import { useNavigate, useParams } from "react-router-dom";
import { useTranslation } from "../hooks/useTranslation";
import { useAuthStore } from "../store/authStore";
import { getControlTower, getControlTowerAttention, getIntegrationHealth, resolveControlTowerException, saveControlTowerMilestone } from "../api/controlTower";
import "./SupplyChainControlTower.css";

const readable = (value) => String(value || "unknown").replaceAll("_", " ");
const formatDate = (value) => value ? new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value)) : "Unknown";

export default function SupplyChainControlTower() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { shipmentId } = useParams();
  const permissions = useAuthStore((state) => state.permissions);
  const canManage = permissions.includes("control_tower.manage");
  const [tab, setTab] = useState("imports");
  const [imports, setImports] = useState([]);
  const [attention, setAttention] = useState([]);
  const [integrations, setIntegrations] = useState([]);
  const [selectedId, setSelectedId] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [filters, setFilters] = useState({ severity: "", status: "active" });
  const [milestone, setMilestone] = useState({ milestone_type: "cargo_ready", planned_at: "", estimated_at: "", actual_at: "", notes: "" });
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    setLoading(true); setError("");
    try {
      const [tower, issues, health] = await Promise.all([
        getControlTower({ per_page: 100 }),
        getControlTowerAttention({ ...filters, per_page: 100 }),
        getIntegrationHealth(),
      ]);
      setImports(tower.data || []);
      setAttention(issues.data || []);
      setIntegrations(health.data || []);
      setSelectedId((current) => Number(shipmentId) || current || tower.data?.[0]?.id || null);
    } catch (exception) {
      setError(exception.message || t("controlTower.loadError"));
    } finally { setLoading(false); }
  }, [filters, shipmentId, t]);

  useEffect(() => { void load(); }, [load]);
  useEffect(() => {
    const refresh = () => void load();
    window.addEventListener("database-refresh", refresh);
    return () => window.removeEventListener("database-refresh", refresh);
  }, [load]);

  const selected = useMemo(() => imports.find((item) => item.id === selectedId) || imports[0], [imports, selectedId]);
  const counts = useMemo(() => ({
    critical: attention.filter((item) => item.severity === "critical").length,
    warning: attention.filter((item) => item.severity === "warning").length,
    live: imports.filter((item) => item.vessel?.ais_freshness === "live").length,
  }), [attention, imports]);

  const saveMilestone = async (event) => {
    event.preventDefault();
    if (!selected || saving) return;
    setSaving(true); setError("");
    try {
      const result = await saveControlTowerMilestone(selected.id, Object.fromEntries(Object.entries(milestone).map(([key, value]) => [key, value || null])));
      setImports((rows) => rows.map((row) => row.id === selected.id ? result.shipment : row));
    } catch (exception) { setError(exception.message || t("controlTower.saveError")); }
    finally { setSaving(false); }
  };

  const resolveIssue = async (id) => {
    try { await resolveControlTowerException(id); await load(); }
    catch (exception) { setError(exception.message || t("controlTower.resolveError")); }
  };

  return <main className="control-tower">
    <header className="ct-hero">
      <div><span>{t("controlTower.eyebrow")}</span><h1>{t("controlTower.title")}</h1><p>{t("controlTower.subtitle")}</p></div>
      <button type="button" className="ct-refresh" onClick={load} disabled={loading}><RefreshCw size={17} className={loading ? "spin" : ""}/>{t("common.refresh")}</button>
    </header>
    {error && <p className="ct-error" role="alert">{error}</p>}
    <section className="ct-metrics">
      <article><Ship/><div><strong>{imports.length}</strong><span>{t("controlTower.activeImports")}</span></div></article>
      <article className="danger"><AlertTriangle/><div><strong>{counts.critical}</strong><span>{t("controlTower.critical")}</span></div></article>
      <article className="warning"><AlertTriangle/><div><strong>{counts.warning}</strong><span>{t("controlTower.warnings")}</span></div></article>
      <article className="live"><Radio/><div><strong>{counts.live}</strong><span>{t("controlTower.liveAis")}</span></div></article>
    </section>
    <nav className="ct-tabs">
      <button className={tab === "imports" ? "active" : ""} onClick={() => setTab("imports")}><Route size={17}/>{t("controlTower.imports")}</button>
      <button className={tab === "attention" ? "active" : ""} onClick={() => setTab("attention")}><AlertTriangle size={17}/>{t("controlTower.attention")} {attention.length > 0 && <b>{attention.length}</b>}</button>
      <button className={tab === "integrations" ? "active" : ""} onClick={() => setTab("integrations")}><Radio size={17}/>{t("controlTower.integrations")}</button>
    </nav>
    {loading ? <section className="ct-loading"><span/><span/><span/></section> : null}
    {!loading && tab === "imports" && <section className="ct-workspace">
      <aside className="ct-list">
        {imports.length === 0 && <p className="ct-empty">{t("controlTower.noImports")}</p>}
        {imports.map((item) => <button key={item.id} className={item.id === selected?.id ? "active" : ""} onClick={() => setSelectedId(item.id)}>
          <span><b>{item.reference}</b><small>{item.supplier?.name || t("controlTower.unknownSupplier")}</small></span>
          <em className={`ct-signal ${item.vessel?.ais_freshness}`}>{readable(item.vessel?.ais_freshness)}</em>
          <small>{readable(item.current_milestone?.type)} · {item.containers.length} {t("controlTower.containers")}</small>
        </button>)}
      </aside>
      {selected && <article className="ct-detail">
        <header><div><span>{selected.supplier?.name || t("controlTower.unknownSupplier")}</span><h2>{selected.reference}</h2></div><button onClick={() => navigate(selected.links.shipment)}>{t("controlTower.openShipment")}<ExternalLink size={15}/></button></header>
        <div className="ct-facts">
          <span><small>{t("controlTower.route")}</small><b>{selected.origin || "Unknown"} → {selected.destination || "Unknown"}</b></span>
          <span><small>ETD / ETA</small><b>{formatDate(selected.etd)} / {formatDate(selected.eta)}</b></span>
          <span><small>{t("controlTower.receiving")}</small><b>{readable(selected.goods_receipt.status)}</b></span>
          <span><small>{t("controlTower.landedCost")}</small><b>{readable(selected.landed_cost.status)}</b></span>
        </div>
        <section className="ct-timeline" aria-label={t("controlTower.timeline")}>
          {selected.timeline.map((step) => <div key={step.type} className={`ct-step ${step.state}`}>
            <i>{step.state === "completed" ? <CheckCircle2 size={16}/> : null}</i>
            <div><b>{readable(step.type)}</b><small>{step.actual_at ? `${t("controlTower.actual")}: ${formatDate(step.actual_at)}` : step.estimated_at ? `${t("controlTower.estimated")}: ${formatDate(step.estimated_at)}` : step.planned_at ? `${t("controlTower.planned")}: ${formatDate(step.planned_at)}` : t("controlTower.dateUnknown")}</small></div>
            <em>{readable(step.state)}</em>
          </div>)}
        </section>
        <section className="ct-grid-two">
          <div className="ct-panel"><h3><Boxes size={17}/>{t("controlTower.containers")}</h3>{selected.containers.length ? selected.containers.map((container) => <div className="ct-container" key={container.id}><b>{container.container_number}</b><span>{container.container_type || "—"} · {readable(container.status)}</span><small>{container.used_cbm} / {container.capacity_cbm ?? "?"} CBM · {container.used_weight_kg} / {container.capacity_weight_kg ?? "?"} kg</small>{container.capacity_warning && <em>{t("controlTower.capacityExceeded")}</em>}</div>) : <p>{t("controlTower.noContainers")}</p>}</div>
          <div className="ct-panel"><h3><Anchor size={17}/>{t("controlTower.linkedOrders")}</h3>{selected.purchase_orders.map((po) => <button className="ct-link" key={po.id} onClick={() => navigate(po.url)}><span><b>{po.po_number}</b><small>{po.supplier?.name} · {readable(po.status)}</small></span><ExternalLink size={15}/></button>)}{selected.next_action && <button className="ct-next" onClick={() => navigate(selected.next_action.url)}>{t("controlTower.nextAction")}: {selected.next_action.label}</button>}</div>
        </section>
        {canManage && <form className="ct-milestone" onSubmit={saveMilestone}><h3>{t("controlTower.updateMilestone")}</h3><select value={milestone.milestone_type} onChange={(e) => setMilestone({ ...milestone, milestone_type: e.target.value })}>{selected.timeline.map((step) => <option key={step.type} value={step.type}>{readable(step.type)}</option>)}</select><label>{t("controlTower.planned")}<input type="datetime-local" value={milestone.planned_at} onChange={(e) => setMilestone({ ...milestone, planned_at: e.target.value })}/></label><label>{t("controlTower.estimated")}<input type="datetime-local" value={milestone.estimated_at} onChange={(e) => setMilestone({ ...milestone, estimated_at: e.target.value })}/></label><label>{t("controlTower.actual")}<input type="datetime-local" value={milestone.actual_at} onChange={(e) => setMilestone({ ...milestone, actual_at: e.target.value })}/></label><input placeholder={t("controlTower.notes")} value={milestone.notes} onChange={(e) => setMilestone({ ...milestone, notes: e.target.value })}/><button disabled={saving}>{saving ? t("common.saving") : t("common.save")}</button></form>}
      </article>}
    </section>}
    {!loading && tab === "attention" && <section className="ct-attention">
      <header><h2>{t("controlTower.attention")}</h2><div><select value={filters.severity} onChange={(e) => setFilters({ ...filters, severity: e.target.value })}><option value="">{t("controlTower.allSeverity")}</option><option value="critical">Critical</option><option value="warning">Warning</option><option value="informational">Informational</option></select><select value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value })}><option value="active">Active</option><option value="resolved">Resolved</option></select></div></header>
      {attention.map((issue) => <article key={issue.id} className={`ct-issue ${issue.severity}`}><AlertTriangle/><div><span>{readable(issue.exception_type)} · {issue.shipment?.tracking_number || issue.purchase_order?.po_number}</span><b>{issue.description}</b><small>{formatDate(issue.detected_at)}</small></div><div>{issue.next_action && <button onClick={() => navigate(issue.next_action.url)}>{issue.next_action.label}</button>}{canManage && issue.status === "active" && <button className="secondary" onClick={() => resolveIssue(issue.id)}>{t("controlTower.resolve")}</button>}</div></article>)}
      {attention.length === 0 && <p className="ct-empty">{t("controlTower.noAttention")}</p>}
    </section>}
    {!loading && tab === "integrations" && <section className="ct-integrations">{integrations.map((provider) => <article key={provider.id}><span className={`ct-health ${provider.health_state}`}/><div><h3>{provider.display_name}</h3><p>{readable(provider.category)} · {provider.enabled ? t("controlTower.enabled") : t("controlTower.disabled")}</p><small>{provider.last_data_received_at ? `${t("controlTower.lastData")}: ${formatDate(provider.last_data_received_at)}` : t("controlTower.noDataYet")}</small>{provider.last_error && <em>{provider.last_error}</em>}</div><b>{readable(provider.health_state)}</b></article>)}</section>}
  </main>;
}
