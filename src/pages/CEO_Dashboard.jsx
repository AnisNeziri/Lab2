import React, { useEffect, useState } from "react";
import DashboardCard from "../components/DashboardCard";
import Charts from "../components/Charts";
import "./CEO_Dashboard.css";

export default function CEO_Dashboard() {
  const [stats, setStats] = useState({
    totalProducts: 0,
    lowStock: 0,
    overStock: 0,
    normalStock: 0,
  });

  const [loading, setLoading] = useState(true);

  useEffect(() => {
    // later we replace this with API → /api/dashboard/overview
    const fetchStats = async () => {
      try {
        setLoading(true);

        // Example mock data (remove when backend is ready)
        setTimeout(() => {
          setStats({
            totalProducts: 128,
            lowStock: 12,
            overStock: 5,
            normalStock: 111,
          });
          setLoading(false);
        }, 500);

      } catch (err) {
        console.error("Error loading dashboard stats", err);
      }
    };

    fetchStats();
  }, []);

  if (loading) return <div className="loading">Loading dashboard...</div>;

  return (
    <div className="ceo-dashboard">
      <h1 className="dashboard-title">CEO Dashboard</h1>

      <div className="stats-grid">
        <DashboardCard title="Total Products" value={stats.totalProducts} color="#1976d2" />
        <DashboardCard title="Low Stock Items" value={stats.lowStock} color="#d32f2f" />
        <DashboardCard title="Over Stock Items" value={stats.overStock} color="#ed6c02" />
        <DashboardCard title="Normal Stock" value={stats.normalStock} color="#2e7d32" />
      </div>

      <div className="charts-section">
        <Charts />
      </div>
    </div>
  );
}
