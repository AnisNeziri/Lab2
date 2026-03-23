import React from "react";
import { Box, Typography } from "@mui/material";

const EmptyState = ({ label = "No data found." }) => (
  <Box sx={{ py: 4, textAlign: "center" }}>
    <Typography variant="body2" color="text.secondary">
      {label}
    </Typography>
  </Box>
);

export default EmptyState;
