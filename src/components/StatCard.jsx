import React from "react";

export default function StatCard({ title, value, color }) {
  return (
    <div 
      className="stat-card" 
      style={{ borderLeft: `5px solid ${color}` }}
    >
      <h3>{title}</h3>
      <p>{value}</p>
    </div>
  );
}
