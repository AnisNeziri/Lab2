import React, { useEffect, useMemo, useState } from "react";
import {
  Box,
  Button,
  Card,
  CardContent,
  Chip,
  Container,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Grid,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from "@mui/material";
import PrintOutlinedIcon from "@mui/icons-material/PrintOutlined";
import ReceiptLongOutlinedIcon from "@mui/icons-material/ReceiptLongOutlined";
import api from "../services/api";
import LoadingState from "../components/LoadingState";
import EmptyState from "../components/EmptyState";
import AppSnackbar from "../components/AppSnackbar";

const currency = new Intl.NumberFormat("en-US", {
  style: "currency",
  currency: "USD",
});

const formatDate = (value) => {
  if (!value) return "-";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleDateString("en-US", {
    year: "numeric",
    month: "short",
    day: "2-digit",
  });
};

const escapeHtml = (value) =>
  String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");

const buildInvoice = (transaction) => {
  const product = transaction.product || {};
  const unitPrice = Number(product.selling_price || transaction.unit_price || 0);
  const quantity = Number(transaction.quantity || 0);
  const total = unitPrice * quantity;

  return {
    id: transaction.id,
    number: `INV-${String(transaction.id).padStart(5, "0")}`,
    date: transaction.created_at,
    customer: transaction.customer_name || transaction.client_name || "Walk-in customer",
    status: transaction.status || "Paid",
    productName: product.name || "Product",
    sku: product.sku || "-",
    quantity,
    unitPrice,
    total,
    notes: transaction.notes || "",
  };
};

const InvoicesPage = () => {
  const [transactions, setTransactions] = useState([]);
  const [loading, setLoading] = useState(true);
  const [selectedInvoice, setSelectedInvoice] = useState(null);
  const [toast, setToast] = useState({ open: false, severity: "info", message: "" });

  useEffect(() => {
    const loadInvoices = async () => {
      setLoading(true);
      try {
        const res = await api.get("/transactions");
        setTransactions(Array.isArray(res.data) ? res.data : []);
      } catch {
        setToast({ open: true, severity: "error", message: "Failed to load invoices." });
      } finally {
        setLoading(false);
      }
    };

    loadInvoices();
  }, []);

  const invoices = useMemo(
    () => transactions.filter((tx) => tx.type === "sale").map(buildInvoice),
    [transactions]
  );

  const totals = useMemo(() => {
    const revenue = invoices.reduce((sum, invoice) => sum + invoice.total, 0);
    const units = invoices.reduce((sum, invoice) => sum + invoice.quantity, 0);
    return { revenue, units };
  }, [invoices]);

  const printInvoice = (invoice) => {
    const printWindow = window.open("", "_blank", "width=900,height=700");
    if (!printWindow) {
      setToast({ open: true, severity: "warning", message: "Popup blocked. Allow popups to print invoices." });
      return;
    }

    const safeInvoice = {
      number: escapeHtml(invoice.number),
      date: escapeHtml(formatDate(invoice.date)),
      customer: escapeHtml(invoice.customer),
      productName: escapeHtml(invoice.productName),
      sku: escapeHtml(invoice.sku),
      notes: escapeHtml(invoice.notes || "Thank you for your business."),
    };

    printWindow.document.write(`
      <html>
        <head>
          <title>${safeInvoice.number}</title>
          <style>
            body { font-family: Arial, sans-serif; color: #111827; margin: 40px; }
            .header { display: flex; justify-content: space-between; border-bottom: 2px solid #111827; padding-bottom: 18px; }
            .brand { font-size: 28px; font-weight: 700; }
            .muted { color: #6b7280; }
            table { border-collapse: collapse; width: 100%; margin-top: 28px; }
            th, td { border-bottom: 1px solid #e5e7eb; padding: 12px; text-align: left; }
            th { background: #f3f4f6; }
            .right { text-align: right; }
            .total { font-size: 22px; font-weight: 700; margin-top: 24px; text-align: right; }
          </style>
        </head>
        <body>
          <div class="header">
            <div>
              <div class="brand">AIMS</div>
              <div class="muted">Advanced Inventory Management System</div>
            </div>
            <div>
              <h2>${safeInvoice.number}</h2>
              <div class="muted">${safeInvoice.date}</div>
            </div>
          </div>
          <p><strong>Bill to:</strong> ${safeInvoice.customer}</p>
          <table>
            <thead>
              <tr>
                <th>Product</th>
                <th>SKU</th>
                <th class="right">Qty</th>
                <th class="right">Unit Price</th>
                <th class="right">Total</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>${safeInvoice.productName}</td>
                <td>${safeInvoice.sku}</td>
                <td class="right">${invoice.quantity}</td>
                <td class="right">${currency.format(invoice.unitPrice)}</td>
                <td class="right">${currency.format(invoice.total)}</td>
              </tr>
            </tbody>
          </table>
          <div class="total">Total: ${currency.format(invoice.total)}</div>
          <p class="muted">${safeInvoice.notes}</p>
        </body>
      </html>
    `);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
  };

  return (
    <Container maxWidth="xl" sx={{ py: 3 }}>
      <Box sx={{ display: "flex", justifyContent: "space-between", gap: 2, flexWrap: "wrap", mb: 3 }}>
        <Box>
          <Typography variant="overline" color="text.secondary">
            Billing
          </Typography>
          <Typography variant="h4" sx={{ fontWeight: 700 }}>
            Invoices
          </Typography>
        </Box>
        <Button
          startIcon={<ReceiptLongOutlinedIcon />}
          variant="contained"
          disabled={!invoices.length}
          onClick={() => setSelectedInvoice(invoices[0])}
        >
          Latest Invoice
        </Button>
      </Box>

      <Grid container spacing={2} sx={{ mb: 3 }}>
        <Grid item xs={12} md={4}>
          <Card sx={{ border: "1px solid", borderColor: "divider" }}>
            <CardContent>
              <Typography color="text.secondary">Invoices Issued</Typography>
              <Typography variant="h4" sx={{ fontWeight: 700 }}>{invoices.length}</Typography>
            </CardContent>
          </Card>
        </Grid>
        <Grid item xs={12} md={4}>
          <Card sx={{ border: "1px solid", borderColor: "divider" }}>
            <CardContent>
              <Typography color="text.secondary">Total Revenue</Typography>
              <Typography variant="h4" sx={{ fontWeight: 700 }}>{currency.format(totals.revenue)}</Typography>
            </CardContent>
          </Card>
        </Grid>
        <Grid item xs={12} md={4}>
          <Card sx={{ border: "1px solid", borderColor: "divider" }}>
            <CardContent>
              <Typography color="text.secondary">Units Sold</Typography>
              <Typography variant="h4" sx={{ fontWeight: 700 }}>{totals.units}</Typography>
            </CardContent>
          </Card>
        </Grid>
      </Grid>

      <Card sx={{ border: "1px solid", borderColor: "divider" }}>
        <CardContent>
          {loading ? (
            <LoadingState label="Loading invoices..." />
          ) : (
            <Table>
              <TableHead>
                <TableRow>
                  <TableCell>Invoice</TableCell>
                  <TableCell>Date</TableCell>
                  <TableCell>Customer</TableCell>
                  <TableCell>Product</TableCell>
                  <TableCell align="right">Amount</TableCell>
                  <TableCell>Status</TableCell>
                  <TableCell align="right">Actions</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {invoices.map((invoice) => (
                  <TableRow key={invoice.id}>
                    <TableCell sx={{ fontWeight: 700 }}>{invoice.number}</TableCell>
                    <TableCell>{formatDate(invoice.date)}</TableCell>
                    <TableCell>{invoice.customer}</TableCell>
                    <TableCell>{invoice.productName}</TableCell>
                    <TableCell align="right">{currency.format(invoice.total)}</TableCell>
                    <TableCell>
                      <Chip size="small" color="success" variant="outlined" label={invoice.status} />
                    </TableCell>
                    <TableCell align="right">
                      <Stack direction="row" spacing={1} justifyContent="flex-end">
                        <Button size="small" onClick={() => setSelectedInvoice(invoice)}>
                          View
                        </Button>
                        <Button size="small" startIcon={<PrintOutlinedIcon />} onClick={() => printInvoice(invoice)}>
                          Print
                        </Button>
                      </Stack>
                    </TableCell>
                  </TableRow>
                ))}
                {!invoices.length && (
                  <TableRow>
                    <TableCell colSpan={7}>
                      <EmptyState label="No invoices yet. Save a sale transaction to generate one." />
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <Dialog open={Boolean(selectedInvoice)} onClose={() => setSelectedInvoice(null)} maxWidth="md" fullWidth>
        {selectedInvoice && (
          <>
            <DialogTitle>
              <Box sx={{ display: "flex", justifyContent: "space-between", gap: 2, flexWrap: "wrap" }}>
                <span>{selectedInvoice.number}</span>
                <Chip label={selectedInvoice.status} color="success" variant="outlined" />
              </Box>
            </DialogTitle>
            <DialogContent>
              <Grid container spacing={2} sx={{ mb: 2 }}>
                <Grid item xs={12} sm={6}>
                  <Typography color="text.secondary">Bill to</Typography>
                  <Typography sx={{ fontWeight: 700 }}>{selectedInvoice.customer}</Typography>
                </Grid>
                <Grid item xs={12} sm={6}>
                  <Typography color="text.secondary">Date</Typography>
                  <Typography sx={{ fontWeight: 700 }}>{formatDate(selectedInvoice.date)}</Typography>
                </Grid>
              </Grid>
              <Table>
                <TableHead>
                  <TableRow>
                    <TableCell>Product</TableCell>
                    <TableCell>SKU</TableCell>
                    <TableCell align="right">Qty</TableCell>
                    <TableCell align="right">Unit Price</TableCell>
                    <TableCell align="right">Total</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  <TableRow>
                    <TableCell>{selectedInvoice.productName}</TableCell>
                    <TableCell>{selectedInvoice.sku}</TableCell>
                    <TableCell align="right">{selectedInvoice.quantity}</TableCell>
                    <TableCell align="right">{currency.format(selectedInvoice.unitPrice)}</TableCell>
                    <TableCell align="right">{currency.format(selectedInvoice.total)}</TableCell>
                  </TableRow>
                </TableBody>
              </Table>
              <Typography variant="h5" align="right" sx={{ fontWeight: 700, mt: 3 }}>
                {currency.format(selectedInvoice.total)}
              </Typography>
            </DialogContent>
            <DialogActions>
              <Button onClick={() => setSelectedInvoice(null)}>Close</Button>
              <Button variant="contained" startIcon={<PrintOutlinedIcon />} onClick={() => printInvoice(selectedInvoice)}>
                Print
              </Button>
            </DialogActions>
          </>
        )}
      </Dialog>

      <AppSnackbar
        open={toast.open}
        severity={toast.severity}
        message={toast.message}
        onClose={() => setToast({ ...toast, open: false })}
      />
    </Container>
  );
};

export default InvoicesPage;
