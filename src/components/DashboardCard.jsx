import React from "react";
import "./DashboardCard.css";

export default function DashboardCard({ title, value, color }) {
  return (
    <div className="dashboard-card" style={{ borderLeft: `5px solid ${color}` }}>
      <h3>{title}</h3>
      <p>{value}</p>
    </div>
  );
}
