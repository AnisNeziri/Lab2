import React from "react";
import { Box, CircularProgress, Typography } from "@mui/material";

const LoadingState = ({ label = "Loading..." }) => (
  <Box sx={{ display: "flex", flexDirection: "column", alignItems: "center", py: 6, gap: 1 }}>
    <CircularProgress />
    <Typography variant="body2" color="text.secondary">
      {label}
    </Typography>
  </Box>
);

export default LoadingState;
