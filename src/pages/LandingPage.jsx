import React, { useEffect, useContext } from "react";
import { useNavigate } from "react-router-dom";
import {
  AppBar,
  Toolbar,
  Typography,
  Button,
  Box,
  Container,
  Grid,
  Paper,
  IconButton,
} from "@mui/material";
import { GitHub, LinkedIn, Email } from "@mui/icons-material";
import { AuthContext } from "../context/AuthContext";

// 🖼 Import your local images
import banner from "../assets/banner.jpg";
import img1 from "../assets/img1.jpg";
import slider1 from "../assets/slider1.jpg";
import slider2 from "../assets/slider2.jpg";
import slider3 from "../assets/slider3.jpg";

export default function LandingPage() {
  const { user } = useContext(AuthContext);
  const navigate = useNavigate();

  useEffect(() => {
    if (user) navigate("/dashboard", { replace: true });
  }, [user, navigate]);

  return (
    <>
      {/* HEADER */}
      <AppBar position="static" color="transparent" elevation={0} sx={{ p: 1 }}>
        <Toolbar sx={{ display: "flex", justifyContent: "space-between" }}>
          <Typography
            variant="h6"
            sx={{ fontWeight: "bold", color: "#1976d2", cursor: "pointer" }}
            onClick={() => navigate("/")}
          >
            AIMS
          </Typography>
          <Box>
            <Button href="/register" sx={{ mr: 1 }}>
              Register
            </Button>
            <Button href="/login" variant="outlined">
              Log In
            </Button>
          </Box>
        </Toolbar>
      </AppBar>

      {/* BANNER SECTION */}
      <Box
        sx={{
          width: "100%",
          height: { xs: "40vh", md: "70vh" },
          backgroundImage: `url(${banner})`,
          backgroundSize: "cover",
          backgroundPosition: "center",
          display: "flex",
          alignItems: "center",
          justifyContent: "center",
          color: "#fff",
          textAlign: "center",
          position: "relative",
          mb: 6,
        }}
      >
        <Box
          sx={{
            backgroundColor: "rgba(0,0,0,0.5)",
            p: 4,
            borderRadius: 2,
          }}
        >
          <Typography variant="h3" sx={{ fontWeight: "bold", mb: 2 }}>
            Simplify Your Inventory with AIMS
          </Typography>
          <Typography variant="h6">
            Empower your company with real-time insights and effortless control.
          </Typography>
        </Box>
      </Box>

      {/* WHY YOU NEED OUR SYSTEM */}
      <Container sx={{ mb: 8 }}>
        <Typography
          variant="h4"
          align="center"
          sx={{ fontWeight: "bold", mb: 4, color: "primary.main" }}
        >
          Why Choose AIMS for Your Business
        </Typography>

        <Box
          sx={{
            display: "flex",
            flexWrap: "wrap",
            justifyContent: "space-around",
            gap: 3,
          }}
        >
          {[
            {
              title: "Real-Time Tracking",
              desc: "Always know your inventory levels instantly with live updates.",
            },
            {
              title: "Powerful Analytics",
              desc: "Gain insights into sales, stock, and operations with smart reports.",
            },
            {
              title: "Easy Integration",
              desc: "Connect seamlessly with your existing tools and workflows.",
            },
          ].map((item, i) => (
            <Paper
              key={i}
              elevation={4}
              sx={{
                flex: "1 1 250px",
                maxWidth: 320,
                p: 3,
                textAlign: "center",
                borderRadius: 3,
                transition: "0.3s",
                "&:hover": {
                  transform: "translateY(-6px)",
                  boxShadow: 6,
                },
              }}
            >
              <Typography variant="h6" sx={{ fontWeight: "bold", mb: 1 }}>
                {item.title}
              </Typography>
              <Typography variant="body2" color="text.secondary">
                {item.desc}
              </Typography>
            </Paper>
          ))}
        </Box>
      </Container>

      {/* ABOUT SECTION */}
      <Container sx={{ mb: 10 }}>
        <Grid container spacing={4} alignItems="center">
          <Grid item xs={12} md={6}>
            <Typography
              variant="h4"
              sx={{ fontWeight: "bold", mb: 2, color: "primary.main" }}
            >
              About AIMS
            </Typography>
            <Typography variant="body1" paragraph>
              AIMS is built by passionate engineers and business experts who
              understand how critical inventory control is for a company’s
              success. We help organizations reduce waste, boost efficiency, and
              make informed decisions through a simple and powerful dashboard.
            </Typography>
            <Typography variant="body1" paragraph>
              Our mission is to make inventory management smarter, faster, and
              more reliable — for businesses of all sizes.
            </Typography>
          </Grid>
          <Grid item xs={12} md={6}>
            <Box
              component="img"
              src={img1}
              alt="Our Company"
              sx={{
                width: "100%",
                borderRadius: 3,
                boxShadow: 4,
                transition: "0.3s",
                "&:hover": { transform: "scale(1.02)" },
              }}
            />
          </Grid>
        </Grid>
      </Container>

      {/* IMAGE SLIDER */}
<Box
  sx={{
    width: "30%",
    overflow: "hidden",
    position: "relative",
    mb: 10,
    marginLeft: 80,
  }}
>
  <Box
    sx={{
      display: "flex",
      animation: "slide 12s infinite",
      "@keyframes slide": {
        "0%": { transform: "translateX(0)" },
        "33%": { transform: "translateX(-100%)" },
        "66%": { transform: "translateX(-200%)" },
        "100%": { transform: "translateX(0)" },
      },
    }}
  >
    {[slider1, slider2, slider3].map((img, i) => (
      <Box
        key={i}
        component="img"
        src={img}
        alt={`Slide ${i + 1}`}
        sx={{
          width: "100%",
          height: { xs: "30vh", md: "40vh" }, // ✅ reduced height
          objectFit: "cover",
          flexShrink: 0,
          borderRadius: 3,
          mx: 0.5, // slight spacing between slides
          boxShadow: 3,
        }}
      />
    ))}
  </Box>
</Box>


      {/* FOOTER */}
      <Box
        sx={{
          backgroundColor: "#f8f9fa",
          py: 6,
          textAlign: "center",
          borderTop: "1px solid #ddd",
        }}
      >
        <Typography variant="h6" fontWeight="bold" color="primary">
          AIMS Inventory System
        </Typography>
        <Typography variant="body2" color="text.secondary" mb={2}>
          Simplify. Optimize. Grow.
        </Typography>

        <Box>
          <IconButton
            href="https://github.com/"
            target="_blank"
            sx={{
              color: "#333",
              "&:hover": { color: "#1976d2", transform: "scale(1.2)" },
              transition: "0.3s",
            }}
          >
            <GitHub />
          </IconButton>

          <IconButton
            href="https://linkedin.com/"
            target="_blank"
            sx={{
              color: "#333",
              "&:hover": { color: "#0a66c2", transform: "scale(1.2)" },
              transition: "0.3s",
            }}
          >
            <LinkedIn />
          </IconButton>

          <IconButton
            href="mailto:support@aims.com"
            sx={{
              color: "#333",
              "&:hover": { color: "#d93025", transform: "scale(1.2)" },
              transition: "0.3s",
            }}
          >
            <Email />
          </IconButton>
        </Box>

        <Typography variant="body2" color="text.secondary" mt={2}>
          © {new Date().getFullYear()} AIMS. All rights reserved.
        </Typography>
      </Box>
    </>
  );
}
