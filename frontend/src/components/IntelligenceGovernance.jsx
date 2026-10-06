import {Link} from 'react-router-dom'
import {useTranslation} from '../hooks/useTranslation'

export default function IntelligenceGovernance({data}) {
 const {language}=useTranslation(),t=(en,sq)=>language==='sq'?sq:en
 const number=v=>v==null?'—':new Intl.NumberFormat(language==='sq'?'sq-AL':'en-GB',{maximumFractionDigits:3}).format(v)
 const health=data.health
 const status={healthy:t('Healthy measured performance','Performancë e matur e shëndetshme'),warning:t('Review required','Nevojitet rishikim'),insufficient_evidence:t('Insufficient evidence','Prova të pamjaftueshme')}
 const runStatus={ready:t('Candidate validation finished','Validimi i kandidatit përfundoi'),failed:t('Training failed — forecasts preserved','Trajnimi dështoi — parashikimet u ruajtën'),running:t('Training in progress','Trajnimi në vazhdim'),interrupted:t('Interrupted — safe to retry','Ndërprerë — mund të riprovohet'),insufficient_data:t('Insufficient data — no forced training','Të dhëna të pamjaftueshme — pa trajnim të detyruar')}
 return <section className="intelligence-governance" aria-label={t('Model health and governance','Shëndeti dhe qeverisja e modelit')}>
  {health&&<article className="intelligence-candidate"><h3>{t('Model health','Shëndeti i modelit')}: {status[health.state]}</h3>
   <p>{health.independent_periods} / {health.required_periods} {t('independent completed periods needed for a reliable health judgement.','periudha të pavarura të përfunduara për vlerësim të besueshëm.')}</p>
   <p>{t('Latest usable observation','Vëzhgimi i fundit i përdorshëm')}: {health.latest_usable_date||'—'} · {t('Unusable days','Ditë të papërdorshme')}: {health.unusable_days} · {t('Stockout days','Ditë pa stok')}: {health.stockout_days}</p>
   {health.stale_features&&<p>{t('Recent reliable input is missing. Record daily sales and stock observations before relying on the forecast.','Mungojnë të dhëna të fundit të besueshme. Regjistro shitjet ditore dhe vëzhgimet e stokut para se të mbështetesh në parashikim.')}</p>}
   <small>{t('MAE is the average error in inventory units. WAPE compares total error with actual sales; it is undefined when actual sales are zero. Positive bias means overforecasting.','MAE është gabimi mesatar në njësi inventari. WAPE krahason gabimin total me shitjet reale; nuk përcaktohet kur shitjet reale janë zero. Devijimi pozitiv tregon mbiparashikim.')}</small>
  </article>}
  {!!data.pending?.length&&<details><summary>{t('Forecasts awaiting outcomes','Parashikime që presin rezultate')} ({data.pending.length})</summary>{data.pending.map(p=><p key={p.id}>{p.from} – {p.to} · {p.model_version.slice(0,8)} · {p.status==='pending'?t('Period still open — not scored','Periudha ende e hapur — pa vlerësim'):t('Period finished — evaluation queued','Periudha përfundoi — vlerësimi në pritje')}</p>)}</details>}
  <details><summary>{t('Retraining history and evidence','Historiku i ritrajnimit dhe provat')}</summary>
   {!data.training_history?.length&&<p>{t('No training attempt recorded yet.','Ende nuk është regjistruar ndonjë tentim trajnimi.')}</p>}
   {data.training_history?.map(r=><article key={r.details.attempt} className="intelligence-candidate"><strong>{runStatus[r.status]||r.status}</strong><p>{r.at.slice(0,16).replace('T',' ')} · {t('Data cutoff','Kufiri i të dhënave')}: {r.details.cutoff}</p><small>{t('Frozen dataset','Grupi i ngrirë i të dhënave')}: {r.details.dataset_version||'—'}</small>{r.details.reason&&<p>{t('Failure reason','Arsyeja e dështimit')}: {r.details.reason}</p>}<p>{t('Required improvement over champion','Përmirësimi i kërkuar ndaj modelit aktiv')}: {number(r.details.configuration?.promotion_improvement_percent)}%</p></article>)}
   {data.models.filter(m=>m.status==='candidate').map(m=><article key={m.id}><h4>{t('Historical comparison, not production accuracy','Krahasim historik, jo saktësi e përdorimit real')} · {m.version.slice(0,8)}</h4><p>{t('Seasonal baseline MAE','MAE e modelit sezonal bazë')}: {number(m.comparison?.seasonal_mean?.mae)} · {t('Ridge MAE','MAE e modelit Ridge')}: {number(m.comparison?.ridge?.mae)}</p></article>)}
  </details>
  {!!data.outcomes?.length&&<details><summary>{t('Receipts and realized stock outcomes','Pranimet dhe rezultatet reale të stokut')}</summary>
   {data.outcomes.map(r=><article key={r.id} className="intelligence-candidate"><strong>{t('Recommendation','Rekomandimi')} #{r.id}</strong>
    <p>{t('Recommended / requested base quantity','Sasia bazë e rekomanduar / kërkuar')}: {number(r.outcome.recommended_base_quantity)} / {number(r.outcome.action_base_quantity)}</p>
    <p>{t('Observed stockout days / captured days','Ditë të vëzhguara pa stok / ditë të regjistruara')}: {number(r.outcome.observed_stockout_days)} / {number(r.outcome.outcome_observation_days)}</p>
    {r.outcome.decision==='ignored'&&<p>{t('Superseded without acceptance; this does not prove the user rejected it.','Zëvendësuar pa pranim; kjo nuk provon se përdoruesi e refuzoi.')}</p>}
    {r.outcome.receipts?.map(receipt=><p key={receipt.id}><Link to={`/purchase-orders?po=${receipt.purchase_order_id}`}>{receipt.reference}</Link> · {number(receipt.accepted_base_quantity)} · {t('Days after expected date','Ditë pas datës së pritur')}: {number(receipt.delay_days)}</p>)}
    {!r.outcome.receipts?.length&&<p>{t('No linked posted receipt yet.','Ende nuk ka pranim të regjistruar të lidhur.')}</p>}
   </article>)}
  </details>}
 </section>
}
