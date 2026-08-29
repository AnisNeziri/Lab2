import { useEffect, useMemo, useState } from "react";
import {
  Activity,
  Building2,
  KeyRound,
  Pencil,
  ShieldCheck,
  Trash2,
  UserPlus,
  Users,
} from "lucide-react";
import {
  createCompany,
  createSuperadminUser,
  deleteSuperadminUser,
  getCompanies,
  getSuperadminDashboard,
  getSuperadminUsers,
  getSystemHealth,
  resetSuperadminUserPassword,
  updateSuperadminUser,
} from "../api/superadmin";
import { useTranslation } from "../hooks/useTranslation";

const blankUser = (companyId = "") => ({
  company_id: companyId,
  name: "",
  email: "",
  role: "admin",
  temporary_password: "",
  temporary_password_confirmation: "",
});

const dateTime = (value) =>
  value
    ? new Intl.DateTimeFormat(undefined, {
        dateStyle: "medium",
        timeStyle: "short",
      }).format(new Date(value))
    : "—";

export default function Superadmin() {
  const { t } = useTranslation();
  const isDesktop = Boolean(window.__AIMS_API_BASE__);
  const [companies, setCompanies] = useState([]);
  const [users, setUsers] = useState([]);
  const [health, setHealth] = useState(null);
  const [metrics, setMetrics] = useState(null);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  const [search, setSearch] = useState("");
  const [companyFilter, setCompanyFilter] = useState("");
  const [company, setCompany] = useState({ name: "", address: "" });
  const [user, setUser] = useState(blankUser());
  const [editing, setEditing] = useState(null);
  const [resetTarget, setResetTarget] = useState(null);
  const [resetForm, setResetForm] = useState({
    temporary_password: "",
    temporary_password_confirmation: "",
  });
  const [deleteTarget, setDeleteTarget] = useState(null);

  const load = async () => {
    try {
      setError("");
      const [companyData, userData, healthData, metricData] = await Promise.all(
        [
          getCompanies(),
          getSuperadminUsers(),
          getSystemHealth(),
          getSuperadminDashboard(),
        ],
      );
      setCompanies(companyData);
      setUsers(userData);
      setHealth(healthData);
      setMetrics(metricData);
      setUser((value) =>
        value.company_id || !companyData[0]
          ? value
          : { ...value, company_id: String(companyData[0].id) },
      );
    } catch (err) {
      setError(err.message || t("superadmin.loadError"));
    }
  };

  useEffect(() => {
    load();
  }, []);

  const run = async (action, success) => {
    if (busy) return;
    setBusy(true);
    setError("");
    setMessage("");
    try {
      await action();
      setMessage(success);
      await load();
    } catch (err) {
      setError(
        err.errors ? Object.values(err.errors).flat().join(" ") : err.message,
      );
    } finally {
      setBusy(false);
    }
  };

  const submitCompany = (event) => {
    event.preventDefault();
    run(async () => {
      await createCompany(company);
      setCompany({ name: "", address: "" });
    }, t("superadmin.companyCreated"));
  };

  const submitUser = (event) => {
    event.preventDefault();
    run(async () => {
      await createSuperadminUser({
        ...user,
        company_id: Number(user.company_id),
      });
      setUser(blankUser(user.company_id));
    }, t("superadmin.userCreated"));
  };

  const submitEdit = (event) => {
    event.preventDefault();
    run(async () => {
      await updateSuperadminUser(editing.id, {
        company_id: Number(editing.company_id),
        name: editing.name,
        email: editing.email,
        role: editing.role,
        is_active: editing.is_active,
      });
      setEditing(null);
    }, t("superadmin.userUpdated"));
  };

  const submitReset = (event) => {
    event.preventDefault();
    run(async () => {
      await resetSuperadminUserPassword(resetTarget.id, resetForm);
      setResetTarget(null);
      setResetForm({
        temporary_password: "",
        temporary_password_confirmation: "",
      });
    }, t("superadmin.passwordReset"));
  };

  const confirmDelete = () =>
    run(async () => {
      await deleteSuperadminUser(deleteTarget.id);
      setDeleteTarget(null);
    }, t("superadmin.userDeleted"));

  const filteredUsers = useMemo(
    () =>
      users.filter((item) => {
        const term = search.trim().toLowerCase();
        const matchesSearch =
          !term || `${item.name} ${item.email}`.toLowerCase().includes(term);
        const matchesCompany =
          !companyFilter || String(item.company_id || "") === companyFilter;
        return matchesSearch && matchesCompany;
      }),
    [users, search, companyFilter],
  );
  const desktopUser = users.find((item) => item.role !== "superadmin") || null;

  const metricCards = metrics
    ? [
        [t("superadmin.totalUsers"), metrics.total_users],
        [t("superadmin.activeUsers"), metrics.active_users],
        [t("superadmin.newUsers30"), metrics.new_users_30_days],
        [t("superadmin.activeUsers30"), metrics.active_users_30_days],
        [t("superadmin.logins30"), metrics.logins_30_days],
        [t("superadmin.actions30"), metrics.actions_30_days],
      ]
    : [];

  return (
    <main className="page-stack superadmin-page">
      <section className="card">
        <div className="section-header">
          <div>
            <h2>
              <ShieldCheck size={22} /> {t("superadmin.title")}
            </h2>
            <p className="page-intro">{t("superadmin.description")}</p>
          </div>
          {health ? (
            <span className="status-pill status-paid">
              {t("superadmin.platformOnline")}
            </span>
          ) : null}
        </div>
        {error ? <div className="form-error-banner">{error}</div> : null}
        {message ? (
          <div className="success-banner">
            <strong>{message}</strong>
          </div>
        ) : null}
        <section className="stats-grid superadmin-metrics">
          {metricCards.map(([label, value]) => (
            <article className="stat-card" key={label}>
              <p className="stat-label">{label}</p>
              <p className="stat-value">{value}</p>
            </article>
          ))}
        </section>
      </section>

      {!isDesktop && editing ? (
        <section className="card inline-editor">
          <div className="section-header">
            <h2>
              <Pencil size={20} /> {t("superadmin.editUser")}
            </h2>
          </div>
          <form
            className="form-grid form-grid-2"
            autoComplete="off"
            onSubmit={submitEdit}
          >
            <label>
              {t("superadmin.company")}
              <select
                required
                value={editing.company_id}
                onChange={(e) =>
                  setEditing({ ...editing, company_id: e.target.value })
                }
              >
                {companies.map((item) => (
                  <option key={item.id} value={item.id}>
                    {item.name}
                  </option>
                ))}
              </select>
            </label>
            <label>
              {t("superadmin.name")}
              <input
                required
                value={editing.name}
                onChange={(e) =>
                  setEditing({ ...editing, name: e.target.value })
                }
              />
            </label>
            <label>
              {t("superadmin.email")}
              <input
                required
                type="email"
                autoComplete="off"
                value={editing.email}
                onChange={(e) =>
                  setEditing({ ...editing, email: e.target.value })
                }
              />
            </label>
            <label>
              {t("superadmin.role")}
              <select
                value={editing.role}
                onChange={(e) =>
                  setEditing({ ...editing, role: e.target.value })
                }
              >
                <option value="admin">{t("superadmin.admin")}</option>
                <option value="manager">{t("superadmin.manager")}</option>
                <option value="staff">{t("superadmin.staff")}</option>
              </select>
            </label>
            <label className="checkbox-row">
              <input
                type="checkbox"
                checked={editing.is_active}
                onChange={(e) =>
                  setEditing({ ...editing, is_active: e.target.checked })
                }
              />{" "}
              {t("superadmin.active")}
            </label>
            <div className="form-actions form-span-2">
              <button disabled={busy}>{t("superadmin.saveUser")}</button>
              <button
                type="button"
                className="secondary"
                onClick={() => setEditing(null)}
              >
                {t("superadmin.cancel")}
              </button>
            </div>
          </form>
        </section>
      ) : null}

      {resetTarget ? (
        <section className="card inline-editor">
          <div className="section-header">
            <div>
              <h2>
                <KeyRound size={20} /> {t("superadmin.resetPassword")}
              </h2>
              <p className="page-intro">
                {resetTarget.name} · {resetTarget.email}
              </p>
            </div>
          </div>
          <form
            className="form-grid form-grid-2"
            autoComplete="off"
            onSubmit={submitReset}
          >
            <label>
              {t("superadmin.temporaryPassword")}
              <input
                required
                type="password"
                autoComplete="new-password"
                minLength="12"
                value={resetForm.temporary_password}
                onChange={(e) =>
                  setResetForm({
                    ...resetForm,
                    temporary_password: e.target.value,
                  })
                }
              />
            </label>
            <label>
              {t("superadmin.confirmPassword")}
              <input
                required
                type="password"
                autoComplete="new-password"
                minLength="12"
                value={resetForm.temporary_password_confirmation}
                onChange={(e) =>
                  setResetForm({
                    ...resetForm,
                    temporary_password_confirmation: e.target.value,
                  })
                }
              />
            </label>
            <div className="form-actions form-span-2">
              <button disabled={busy}>
                {t("superadmin.setTemporaryPassword")}
              </button>
              <button
                type="button"
                className="secondary"
                onClick={() => setResetTarget(null)}
              >
                {t("superadmin.cancel")}
              </button>
            </div>
          </form>
        </section>
      ) : null}

      {!isDesktop && deleteTarget ? (
        <section className="card danger-confirmation">
          <h2>{t("superadmin.deleteUser")}</h2>
          <p>
            {t("superadmin.deleteConfirm")} <strong>{deleteTarget.name}</strong>
            ?
          </p>
          <div className="form-actions">
            <button
              type="button"
              className="danger"
              disabled={busy}
              onClick={confirmDelete}
            >
              {t("superadmin.deleteUser")}
            </button>
            <button
              type="button"
              className="secondary"
              onClick={() => setDeleteTarget(null)}
            >
              {t("superadmin.cancel")}
            </button>
          </div>
        </section>
      ) : null}

      {/* Desktop superadmin provisions one local company user. */}
      <section className="card">
        <div className="section-header">
          <h2>
            <UserPlus size={20} /> {t("superadmin.createUser")}
          </h2>
        </div>
        {isDesktop && desktopUser ? (
          <div className="page-intro">
            {t("superadmin.desktopUserReady")}: <strong>{desktopUser.name}</strong> ({desktopUser.email})
            <div className="form-actions">
              <button type="button" className="secondary" onClick={() => setResetTarget(desktopUser)}>
                <KeyRound size={16} /> {t("superadmin.password")}
              </button>
            </div>
          </div>
        ) : (
          <form
            className="form-grid form-grid-2"
            autoComplete="off"
            onSubmit={submitUser}
          >
            <label>
              {t("superadmin.company")}
              <select
                required
                value={user.company_id}
                onChange={(e) =>
                  setUser({ ...user, company_id: e.target.value })
                }
              >
                {companies.map((item) => (
                  <option key={item.id} value={item.id}>
                    {item.name}
                  </option>
                ))}
              </select>
            </label>
            <label>
              {t("superadmin.name")}
              <input
                required
                value={user.name}
                onChange={(e) => setUser({ ...user, name: e.target.value })}
              />
            </label>
            <label>
              {t("superadmin.email")}
              <input
                required
                type="email"
                autoComplete="off"
                value={user.email}
                onChange={(e) => setUser({ ...user, email: e.target.value })}
              />
            </label>
            <label>
              {t("superadmin.role")}
              <select
                value={user.role}
                onChange={(e) => setUser({ ...user, role: e.target.value })}
              >
                <option value="admin">{t("superadmin.admin")}</option>
                <option value="manager">{t("superadmin.manager")}</option>
                <option value="staff">{t("superadmin.staff")}</option>
              </select>
            </label>
            <label>
              {t("superadmin.temporaryPassword")}
              <input
                required
                type="password"
                autoComplete="new-password"
                minLength="12"
                value={user.temporary_password}
                onChange={(e) =>
                  setUser({ ...user, temporary_password: e.target.value })
                }
              />
            </label>
            <label>
              {t("superadmin.confirmPassword")}
              <input
                required
                type="password"
                autoComplete="new-password"
                minLength="12"
                value={user.temporary_password_confirmation}
                onChange={(e) =>
                  setUser({
                    ...user,
                    temporary_password_confirmation: e.target.value,
                  })
                }
              />
            </label>
            <div className="form-actions form-span-2">
              <button disabled={busy}>
                <UserPlus size={16} /> {t("superadmin.createUser")}
              </button>
            </div>
          </form>
        )}
      </section>

      {!isDesktop ? (
        <section className="card">
          <div className="section-header">
            <h2>
              <Users size={20} /> {t("superadmin.allUsers")}
            </h2>
            <span className="result-count">{filteredUsers.length}</span>
          </div>
          <div className="filter-toolbar">
            <input
              placeholder={t("superadmin.searchUsers")}
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
            <select
              value={companyFilter}
              onChange={(e) => setCompanyFilter(e.target.value)}
            >
              <option value="">{t("superadmin.allCompanies")}</option>
              {companies.map((item) => (
                <option key={item.id} value={item.id}>
                  {item.name}
                </option>
              ))}
            </select>
          </div>
          <div className="table-wrap">
            <table className="product-table">
              <thead>
                <tr>
                  <th>{t("superadmin.user")}</th>
                  <th>{t("superadmin.company")}</th>
                  <th>{t("superadmin.role")}</th>
                  <th>{t("superadmin.joined")}</th>
                  <th>{t("superadmin.lastLogin")}</th>
                  <th>{t("superadmin.logins")}</th>
                  <th>{t("superadmin.status")}</th>
                  <th>{t("superadmin.actions")}</th>
                </tr>
              </thead>
              <tbody>
                {filteredUsers.map((item) => (
                  <tr key={item.id}>
                    <td>
                      {item.name}
                      <br />
                      <span className="muted-text">{item.email}</span>
                    </td>
                    <td>
                      {item.company?.name || t("superadmin.globalAccount")}
                    </td>
                    <td>{item.role}</td>
                    <td>{dateTime(item.created_at)}</td>
                    <td>{dateTime(item.last_login_at)}</td>
                    <td>{item.login_count || 0}</td>
                    <td>
                      {item.role === "superadmin"
                        ? t("superadmin.protected")
                        : item.is_active
                          ? t("superadmin.active")
                          : t("superadmin.disabled")}
                    </td>
                    <td>
                      {item.role === "superadmin" ? (
                        "—"
                      ) : (
                        <div className="table-actions">
                          <button
                            type="button"
                            className="secondary"
                            onClick={() => {
                              setEditing({
                                ...item,
                                company_id: String(item.company_id),
                              });
                              setResetTarget(null);
                              setDeleteTarget(null);
                            }}
                          >
                            <Pencil size={15} /> {t("superadmin.edit")}
                          </button>
                          <button
                            type="button"
                            className="secondary"
                            onClick={() => {
                              setResetTarget(item);
                              setEditing(null);
                              setDeleteTarget(null);
                            }}
                          >
                            <KeyRound size={15} /> {t("superadmin.password")}
                          </button>
                          <button
                            type="button"
                            className="danger"
                            onClick={() => {
                              setDeleteTarget(item);
                              setEditing(null);
                              setResetTarget(null);
                            }}
                          >
                            <Trash2 size={15} /> {t("superadmin.delete")}
                          </button>
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      ) : null}

      <section className="card">
        <div className="section-header">
          <h2>
            <Building2 size={20} /> {t("superadmin.companies")}
          </h2>
        </div>
        {!isDesktop ? (
          <form className="form-grid form-grid-2" onSubmit={submitCompany}>
            <label>
              {t("superadmin.companyName")}
              <input
                required
                value={company.name}
                onChange={(e) =>
                  setCompany({ ...company, name: e.target.value })
                }
              />
            </label>
            <label>
              {t("superadmin.address")}
              <input
                required
                value={company.address}
                onChange={(e) =>
                  setCompany({ ...company, address: e.target.value })
                }
              />
            </label>
            <div className="form-actions form-span-2">
              <button disabled={busy}>
                <Building2 size={16} /> {t("superadmin.createCompany")}
              </button>
            </div>
          </form>
        ) : null}
        <div className="table-wrap separated-table">
          <table className="product-table">
            <thead>
              <tr>
                <th>{t("superadmin.company")}</th>
                <th>{t("superadmin.address")}</th>
                <th>{t("superadmin.users")}</th>
              </tr>
            </thead>
            <tbody>
              {companies.map((item) => (
                <tr key={item.id}>
                  <td>{item.name}</td>
                  <td>{item.address}</td>
                  <td>{item.users_count}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <section className="card compact-card">
        <div className="section-header">
          <h2>
            <Activity size={20} /> {t("superadmin.platformServices")}
          </h2>
        </div>
        <div className="service-status-grid">
          <p>
            <strong>{t("superadmin.database")}</strong>
            <span>{health?.database || "—"}</span>
          </p>
          <p>
            <strong>{t("superadmin.cache")}</strong>
            <span>{health?.cache_driver || "—"}</span>
          </p>
          <p>
            <strong>{t("superadmin.totalLogins")}</strong>
            <span>{metrics?.total_logins ?? "—"}</span>
          </p>
        </div>
      </section>
    </main>
  );
}
