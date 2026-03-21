import React from "react";
import { Alert, Snackbar } from "@mui/material";

const AppSnackbar = ({ open, onClose, severity = "info", message = "" }) => (
  <Snackbar open={open} autoHideDuration={3500} onClose={onClose} anchorOrigin={{ vertical: "top", horizontal: "right" }}>
    <Alert onClose={onClose} severity={severity} variant="filled" sx={{ width: "100%" }}>
      {message}
    </Alert>
  </Snackbar>
);

export default AppSnackbar;
