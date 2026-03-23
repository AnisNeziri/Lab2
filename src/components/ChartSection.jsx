import React from "react";
import { BarChart, Bar, XAxis, YAxis, Tooltip, Legend, ResponsiveContainer } from "recharts";

export default function ChartSection({ products }) {

  const chartData = products.map(p => ({
    name: p.name,
    quantity: p.quantity,
    low: p.low_stock_threshold,
    high: p.high_stock_threshold
  }));

  return (
    <div className="chart-card">
      <h2>Inventory Stock Overview</h2>
      <ResponsiveContainer width="100%" height={350}>
        <BarChart data={chartData}>
          <XAxis dataKey="name" />
          <YAxis />
          <Tooltip />
          <Legend />

          <Bar dataKey="quantity" fill="#4f46e5" name="Quantity" />
          <Bar dataKey="low" fill="#ef4444" name="Low Threshold" />
          <Bar dataKey="high" fill="#10b981" name="High Threshold" />
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}
